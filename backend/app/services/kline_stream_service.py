from __future__ import annotations

import asyncio
import json
from collections import deque
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any

import websockets
from fastapi import WebSocket
from sqlalchemy.orm import Session, sessionmaker

from app.repositories.kline_repo import (
    Interval,
    compute_mouth_state_from_db,
    list_recent_klines,
    prune_old_klines,
    upsert_klines,
)
from app.services.oscillation_engine import run_oscillation_engine
from app.settings import settings


def _parse_intervals_csv(v: str) -> list[Interval]:
    allowed = {"1m", "5m", "15m", "30m", "1h", "4h"}
    out: list[Interval] = []
    for raw in v.split(","):
        s = raw.strip()
        if not s:
            continue
        if s not in allowed:
            continue
        out.append(s)  # type: ignore[arg-type]
    return out


def _parse_symbols_csv(v: str) -> list[str]:
    return [s.strip().upper() for s in v.split(",") if s.strip()]


def _dt_to_ms(dt: datetime) -> int:
    return int(dt.replace(tzinfo=timezone.utc).timestamp() * 1000)


def _ms_to_dt(ms: int) -> datetime:
    return datetime.fromtimestamp(ms / 1000, tz=timezone.utc).replace(tzinfo=None)


def _parse_dt(v: str) -> datetime:
    try:
        return datetime.fromisoformat(v)
    except Exception:
        return datetime.strptime(v, "%Y-%m-%d %H:%M:%S")


@dataclass(frozen=True, slots=True)
class Candle:
    symbol: str
    interval: Interval
    open_time_ms: int
    close_time_ms: int
    open: float
    high: float
    low: float
    close: float
    volume: float
    amount: float
    num_trades: int
    buy_volume: float
    buy_amount: float
    is_final: bool
    boll_up: float | None
    boll_mb: float | None
    boll_dn: float | None

    @property
    def open_time(self) -> datetime:
        return _ms_to_dt(self.open_time_ms)

    @property
    def close_time(self) -> datetime:
        return _ms_to_dt(self.close_time_ms)


class CandleBuffer:
    def __init__(self, *, symbol: str, interval: Interval, maxlen: int, boll_period: int):
        self.symbol = symbol
        self.interval = interval
        self.maxlen = maxlen
        self.boll_period = boll_period
        self.items: deque[Candle] = deque(maxlen=maxlen)

    def snapshot_payload(self) -> list[dict[str, Any]]:
        out: list[dict[str, Any]] = []
        for c in self.items:
            out.append(_candle_to_payload(c))
        return out

    def _compute_boll_for_last(self, *, open_time_ms: int, close: float) -> tuple[float | None, float | None, float | None]:
        period = self.boll_period
        mult = 2.0
        closes: list[float] = []
        for c in reversed(self.items):
            if c.open_time_ms == open_time_ms:
                continue
            closes.append(c.close)
            if len(closes) >= period - 1:
                break
        if len(closes) < period - 1:
            return None, None, None
        closes.reverse()
        window = closes + [close]
        mb = sum(window) / period
        var = sum((x - mb) ** 2 for x in window) / period
        sd = var**0.5
        return mb + mult * sd, mb, mb - mult * sd

    def apply(self, c: Candle) -> str:
        if not self.items:
            self.items.append(c)
            return "append"
        last = self.items[-1]
        if c.open_time_ms == last.open_time_ms:
            self.items[-1] = c
            return "update_last"
        if c.open_time_ms > last.open_time_ms:
            self.items.append(c)
            return "append"
        return "ignore"

    def apply_stream_payload(self, payload: dict[str, Any]) -> tuple[str, Candle | None]:
        c = _parse_upstream_candle(payload, symbol=self.symbol, interval=self.interval)
        boll_up, boll_mb, boll_dn = self._compute_boll_for_last(open_time_ms=c.open_time_ms, close=c.close)
        c = Candle(
            symbol=c.symbol,
            interval=c.interval,
            open_time_ms=c.open_time_ms,
            close_time_ms=c.close_time_ms,
            open=c.open,
            high=c.high,
            low=c.low,
            close=c.close,
            volume=c.volume,
            amount=c.amount,
            num_trades=c.num_trades,
            buy_volume=c.buy_volume,
            buy_amount=c.buy_amount,
            is_final=c.is_final,
            boll_up=boll_up,
            boll_mb=boll_mb,
            boll_dn=boll_dn,
        )
        kind = self.apply(c)
        if kind == "ignore":
            return kind, None
        return kind, c

    def load_snapshot_from_db(self, db_rows: list[dict[str, Any]]) -> None:
        self.items.clear()
        for r in db_rows:
            boll_up = r.get("boll_up")
            boll_mb = r.get("boll_mb")
            boll_dn = r.get("boll_dn")
            ot: datetime = r["open_time"]
            ct: datetime = r["close_time"]
            self.items.append(
                Candle(
                    symbol=self.symbol,
                    interval=self.interval,
                    open_time_ms=_dt_to_ms(ot),
                    close_time_ms=_dt_to_ms(ct),
                    open=float(r["open"]),
                    high=float(r["high"]),
                    low=float(r["low"]),
                    close=float(r["close"]),
                    volume=float(r.get("volume") or 0.0),
                    amount=float(r.get("amount") or 0.0),
                    num_trades=int(r.get("num_trades") or 0),
                    buy_volume=float(r.get("buy_volume") or 0.0),
                    buy_amount=float(r.get("buy_amount") or 0.0),
                    is_final=True,
                    boll_up=float(boll_up) if boll_up is not None else None,
                    boll_mb=float(boll_mb) if boll_mb is not None else None,
                    boll_dn=float(boll_dn) if boll_dn is not None else None,
                )
            )


@dataclass(frozen=True, slots=True)
class GroupKey:
    symbol: str
    interval: Interval

    @property
    def group(self) -> str:
        return f"kline:{self.symbol}:{self.interval}"


class WsClient:
    def __init__(self, ws: WebSocket, *, queue_size: int):
        self.ws = ws
        self.queue: asyncio.Queue[dict[str, Any]] = asyncio.Queue(maxsize=queue_size)
        self.sender_task: asyncio.Task[None] | None = None
        self.groups: set[GroupKey] = set()

    def start(self) -> None:
        self.sender_task = asyncio.create_task(self._sender())

    async def _sender(self) -> None:
        while True:
            msg = await self.queue.get()
            await self.ws.send_json(msg)

    def try_send(self, msg: dict[str, Any]) -> None:
        if self.queue.full():
            try:
                _ = self.queue.get_nowait()
            except Exception:
                pass
        try:
            self.queue.put_nowait(msg)
        except Exception:
            pass

    async def close(self) -> None:
        if self.sender_task is not None:
            self.sender_task.cancel()
            try:
                await self.sender_task
            except asyncio.CancelledError:
                pass
            self.sender_task = None


class KlineStreamService:
    def __init__(self, *, session_factory: sessionmaker):
        self.session_factory = session_factory
        self.symbols = _parse_symbols_csv(settings.kline_symbols)
        self.intervals = _parse_intervals_csv(settings.kline_intervals)
        self.maxlen = int(settings.kline_buffer_size)
        self.queue_size = int(settings.kline_sender_queue_size)
        self.boll_period = int(settings.kline_boll_period)
        self.upstream_url = settings.kline_upstream_ws_url.strip()

        self._buffers: dict[GroupKey, CandleBuffer] = {}
        self._clients: set[WsClient] = set()
        self._group_members: dict[GroupKey, set[WsClient]] = {}
        self._lock = asyncio.Lock()
        self._stop = asyncio.Event()
        self._upstream_task: asyncio.Task[None] | None = None

    async def start(self) -> None:
        asyncio.create_task(self._warmup_from_db())
        self._upstream_task = asyncio.create_task(self._upstream_loop())

    async def stop(self) -> None:
        self._stop.set()
        if self._upstream_task is not None:
            self._upstream_task.cancel()
            try:
                await self._upstream_task
            except asyncio.CancelledError:
                pass
            self._upstream_task = None
        async with self._lock:
            clients = list(self._clients)
        for c in clients:
            await self.unregister(c)

    async def register(self, ws: WebSocket) -> WsClient:
        client = WsClient(ws, queue_size=self.queue_size)
        client.start()
        async with self._lock:
            self._clients.add(client)
        return client

    async def unregister(self, client: WsClient) -> None:
        async with self._lock:
            if client in self._clients:
                self._clients.remove(client)
            for g in list(client.groups):
                members = self._group_members.get(g)
                if members is not None:
                    members.discard(client)
            client.groups.clear()
        await client.close()

    async def subscribe(self, client: WsClient, *, symbols: list[str], intervals: list[Interval]) -> list[str]:
        groups: list[str] = []
        async with self._lock:
            for sym in symbols:
                s = sym.strip().upper()
                if s not in self.symbols:
                    continue
                for itv in intervals:
                    if itv not in self.intervals:
                        continue
                    g = GroupKey(s, itv)
                    groups.append(g.group)
                    client.groups.add(g)
                    self._group_members.setdefault(g, set()).add(client)
        return groups

    async def unsubscribe(self, client: WsClient, *, symbols: list[str], intervals: list[Interval]) -> list[str]:
        removed: list[str] = []
        async with self._lock:
            for sym in symbols:
                s = sym.strip().upper()
                for itv in intervals:
                    g = GroupKey(s, itv)
                    if g in client.groups:
                        client.groups.remove(g)
                        members = self._group_members.get(g)
                        if members is not None:
                            members.discard(client)
                        removed.append(g.group)
        return removed

    async def push_snapshots(self, client: WsClient, *, symbols: list[str], intervals: list[Interval]) -> None:
        for sym in symbols:
            s = sym.strip().upper()
            if s not in self.symbols:
                continue
            for itv in intervals:
                if itv not in self.intervals:
                    continue
                g = GroupKey(s, itv)
                rows = await asyncio.to_thread(self._load_recent_for_group_sync, g)
                async with self._lock:
                    buf = self._buffers.get(g)
                    if buf is None:
                        buf = CandleBuffer(
                            symbol=g.symbol,
                            interval=g.interval,
                            maxlen=self.maxlen,
                            boll_period=self.boll_period,
                        )
                        self._buffers[g] = buf
                    buf.load_snapshot_from_db(rows)
                    snap = buf.snapshot_payload()
                client.try_send({"type": "kline_snapshot", "symbol": g.symbol, "interval": g.interval, "data": snap})

    async def get_snapshot(self, g: GroupKey) -> list[dict[str, Any]]:
        async with self._lock:
            buf = self._buffers.get(g)
            if buf is None:
                return []
            return buf.snapshot_payload()

    async def _warmup_from_db(self) -> None:
        async with self._lock:
            for sym in self.symbols:
                for itv in self.intervals:
                    g = GroupKey(sym, itv)
                    self._buffers[g] = CandleBuffer(
                        symbol=sym,
                        interval=itv,
                        maxlen=self.maxlen,
                        boll_period=self.boll_period,
                    )

        try:
            rows_by_key = await asyncio.to_thread(self._load_recent_from_db_sync)
        except Exception:
            return

        async with self._lock:
            for (sym, itv), rows in rows_by_key.items():
                g = GroupKey(sym, itv)
                buf = self._buffers.get(g)
                if buf is None:
                    continue
                buf.load_snapshot_from_db(rows)

    def _load_recent_from_db_sync(self) -> dict[tuple[str, Interval], list[dict[str, Any]]]:
        db: Session = self.session_factory()
        try:
            out: dict[tuple[str, Interval], list[dict[str, Any]]] = {}
            for sym in self.symbols:
                for itv in self.intervals:
                    rows = list_recent_klines(db, symbol=sym, interval=itv, limit=self.maxlen)
                    out[(sym, itv)] = rows
            return out
        finally:
            db.close()

    def _load_recent_for_group_sync(self, g: GroupKey) -> list[dict[str, Any]]:
        db: Session = self.session_factory()
        try:
            return list_recent_klines(db, symbol=g.symbol, interval=g.interval, limit=self.maxlen)
        finally:
            db.close()

    async def _broadcast(self, g: GroupKey, msg: dict[str, Any]) -> None:
        async with self._lock:
            members = list(self._group_members.get(g, set()))
        for c in members:
            c.try_send(msg)

    async def _apply_upstream_message(self, obj: dict[str, Any]) -> None:
        t = obj.get("type")
        if t not in ("kline_stream", "kline_sync"):
            return
        sym = str(obj.get("symbol") or "").strip().upper()
        itv = obj.get("interval")
        if sym not in self.symbols:
            return
        if itv not in self.intervals:
            return
        data = obj.get("data") or {}
        g = GroupKey(sym, itv)
        async with self._lock:
            buf = self._buffers.get(g)
        if buf is None:
            return
        kind, candle = buf.apply_stream_payload(data)
        if candle is None:
            return
        payload = {"type": "kline_stream", "symbol": sym, "interval": itv, "data": _candle_to_payload(candle)}
        await self._broadcast(g, payload)
        if candle.is_final:
            await self._persist_final(candle)

    async def _persist_final(self, candle: Candle) -> None:
        await asyncio.to_thread(self._persist_final_sync, candle)

    def _persist_final_sync(self, candle: Candle) -> None:
        db: Session = self.session_factory()
        try:
            row = {
                "open_time": candle.open_time,
                "close_time": candle.close_time,
                "open": candle.open,
                "high": candle.high,
                "low": candle.low,
                "close": candle.close,
                "volume": candle.volume,
                "amount": candle.amount,
                "num_trades": candle.num_trades,
                "buy_volume": candle.buy_volume,
                "buy_amount": candle.buy_amount,
                "boll_up": candle.boll_up,
                "boll_mb": candle.boll_mb,
                "boll_dn": candle.boll_dn,
            }
            mb = row.get("boll_mb")
            up = row.get("boll_up")
            dn = row.get("boll_dn")
            if mb is None or up is None or dn is None:
                row["bw"] = None
            else:
                mbf = float(mb)
                if mbf == 0.0:
                    row["bw"] = None
                else:
                    row["bw"] = (float(up) - float(dn)) / mbf
            row["mouth_state"] = compute_mouth_state_from_db(
                db,
                symbol=candle.symbol,
                interval=candle.interval,
                open_time=row["open_time"],
                boll_up=row["boll_up"],
                boll_mb=row["boll_mb"],
                boll_dn=row["boll_dn"],
            )
            upsert_klines(db, symbol=candle.symbol, interval=candle.interval, rows=[row])
            prune_old_klines(db, symbol=candle.symbol, interval=candle.interval, keep=self.maxlen)
            db.commit()
            run_oscillation_engine(
                db,
                symbol=candle.symbol,
                interval=candle.interval,
                start_time=None,
                end_time=None,
                confirm_bars=None,
                break_pct=None,
                break_extreme_pct=None,
            )
        except Exception:
            db.rollback()
        finally:
            db.close()

    async def _upstream_loop(self) -> None:
        backoff = 1.0
        while not self._stop.is_set():
            try:
                async with websockets.connect(self.upstream_url, ping_interval=20, ping_timeout=20, close_timeout=5) as ws:
                    backoff = 1.0
                    await ws.send(
                        json.dumps(
                            {
                                "type": "subscribe_kline",
                                "symbols": self.symbols,
                                "intervals": self.intervals,
                            }
                        )
                    )
                    async for msg in ws:
                        try:
                            obj = json.loads(msg)
                        except Exception:
                            continue
                        await self._apply_upstream_message(obj)
            except Exception:
                await asyncio.sleep(backoff)
                backoff = min(backoff * 2, 30.0)


def _parse_upstream_candle(payload: dict[str, Any], *, symbol: str, interval: Interval) -> Candle:
    ot_ms = payload.get("open_time_ms")
    ct_ms = payload.get("close_time_ms")
    if ot_ms is None:
        ot_dt = _parse_dt(str(payload.get("open_time") or ""))
        ot_ms = _dt_to_ms(ot_dt)
    if ct_ms is None:
        ct_dt = _parse_dt(str(payload.get("close_time") or ""))
        ct_ms = _dt_to_ms(ct_dt)
    is_final = _parse_bool(payload.get("is_final"))
    return Candle(
        symbol=symbol,
        interval=interval,
        open_time_ms=int(ot_ms),
        close_time_ms=int(ct_ms),
        open=float(payload.get("open") or 0.0),
        high=float(payload.get("high") or 0.0),
        low=float(payload.get("low") or 0.0),
        close=float(payload.get("close") or 0.0),
        volume=float(payload.get("volume") or 0.0),
        amount=float(payload.get("amount") or 0.0),
        num_trades=int(payload.get("num_trades") or 0),
        buy_volume=float(payload.get("buy_volume") or 0.0),
        buy_amount=float(payload.get("buy_amount") or 0.0),
        is_final=is_final,
        boll_up=_maybe_float(payload.get("boll_up")),
        boll_mb=_maybe_float(payload.get("boll_mb")),
        boll_dn=_maybe_float(payload.get("boll_dn")),
    )


def _parse_bool(v: Any) -> bool:
    if v is True:
        return True
    if v is False or v is None:
        return False
    if isinstance(v, (int, float)):
        return bool(v)
    if isinstance(v, str):
        s = v.strip().lower()
        return s in {"true", "1", "yes", "y"}
    return False


def _maybe_float(v: Any) -> float | None:
    if v is None:
        return None
    try:
        return float(v)
    except Exception:
        return None


def _candle_to_payload(c: Candle) -> dict[str, Any]:
    return {
        "symbol": c.symbol,
        "is_final": c.is_final,
        "open_time_ms": c.open_time_ms,
        "close_time_ms": c.close_time_ms,
        "open_time": c.open_time.isoformat(sep=" "),
        "close_time": c.close_time.isoformat(sep=" "),
        "open": c.open,
        "high": c.high,
        "low": c.low,
        "close": c.close,
        "volume": c.volume,
        "amount": c.amount,
        "num_trades": c.num_trades,
        "buy_volume": c.buy_volume,
        "buy_amount": c.buy_amount,
        "boll_up": c.boll_up,
        "boll_mb": c.boll_mb,
        "boll_dn": c.boll_dn,
    }
