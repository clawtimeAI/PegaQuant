from __future__ import annotations

from datetime import datetime, timezone
from typing import Literal

from sqlalchemy.orm import Session

from app.repositories.kline_repo import Interval, upsert_klines, prune_old_klines
from app.services.binance_usdm_client import BinanceUSDMClient
from app.settings import settings


def _ms_to_dt(ms: int) -> datetime:
    return datetime.fromtimestamp(ms / 1000, tz=timezone.utc).replace(tzinfo=None)


def compute_boll(rows: list[dict], period: int = 20) -> None:
    closes = []
    for i, r in enumerate(rows):
        closes.append(r["close"])
        if len(closes) < period:
            r["boll_up"] = None
            r["boll_mb"] = None
            r["boll_dn"] = None
            r["bw"] = None
            continue
        window = closes[-period:]
        mb = sum(window) / period
        var = sum((c - mb) ** 2 for c in window) / period
        sd = var ** 0.5
        r["boll_mb"] = mb
        r["boll_up"] = mb + 2 * sd
        r["boll_dn"] = mb - 2 * sd
        if mb == 0.0:
            r["bw"] = None
        else:
            r["bw"] = (float(r["boll_up"]) - float(r["boll_dn"])) / float(mb)


def _mouth_params(interval: Interval) -> tuple[float, float, int]:
    if interval == "1m":
        return 0.00015, 0.0012, 3
    if interval == "5m":
        return 0.00015, 0.0016, 3
    if interval == "15m":
        return 0.00012, 0.0018, 3
    if interval == "30m":
        return 0.00010, 0.0022, 3
    if interval == "1h":
        return 0.00008, 0.0026, 3
    if interval == "4h":
        return 0.00006, 0.0032, 3
    return 0.00015, 0.0016, 3


def _update_mouth_state(ctx: dict, v: float, *, eps: float, switch_delta: float, confirm_bars: int) -> int:
    state = int(ctx.get("state") or 0)
    peak = ctx.get("peak")
    trough = ctx.get("trough")
    drop_streak = int(ctx.get("drop_streak") or 0)
    rise_streak = int(ctx.get("rise_streak") or 0)

    if state == 0:
        last_v = ctx.get("last_v")
        if last_v is not None and v >= float(last_v):
            state = 1
        else:
            state = 2
        ctx["state"] = state
        ctx["peak"] = v
        ctx["trough"] = v
        ctx["drop_streak"] = 0
        ctx["rise_streak"] = 0
        ctx["last_v"] = v
        return state

    if state == 1:
        if peak is None or v >= float(peak) + eps:
            ctx["peak"] = v
            ctx["drop_streak"] = 0
        else:
            peak_f = float(peak)
            if v <= peak_f - switch_delta:
                drop_streak += 1
                if drop_streak >= confirm_bars:
                    ctx["state"] = 2
                    ctx["trough"] = v
                    ctx["rise_streak"] = 0
                    ctx["drop_streak"] = 0
                else:
                    ctx["drop_streak"] = drop_streak
            else:
                ctx["drop_streak"] = 0
    else:
        if trough is None or v <= float(trough) - eps:
            ctx["trough"] = v
            ctx["rise_streak"] = 0
        else:
            trough_f = float(trough)
            if v >= trough_f + switch_delta:
                rise_streak += 1
                if rise_streak >= confirm_bars:
                    ctx["state"] = 1
                    ctx["peak"] = v
                    ctx["rise_streak"] = 0
                    ctx["drop_streak"] = 0
                else:
                    ctx["rise_streak"] = rise_streak
            else:
                ctx["rise_streak"] = 0

    state = int(ctx.get("state") or 0)
    ctx["last_v"] = v
    return state


def compute_mouth_state(rows: list[dict], *, interval: Interval) -> None:
    eps, switch_delta, confirm_bars = _mouth_params(interval)
    ctx: dict = {"state": 0, "peak": None, "trough": None, "drop_streak": 0, "rise_streak": 0, "last_v": None}
    for r in rows:
        bw = r.get("bw")
        if bw is not None:
            v = float(bw)
        else:
            mb = r.get("boll_mb")
            up = r.get("boll_up")
            dn = r.get("boll_dn")
            if mb is None or up is None or dn is None:
                r["mouth_state"] = 0
                continue
            mbf = float(mb)
            if mbf == 0.0:
                r["mouth_state"] = 0
                continue
            v = (float(up) - float(dn)) / mbf
        r["mouth_state"] = _update_mouth_state(ctx, v, eps=eps, switch_delta=switch_delta, confirm_bars=confirm_bars)


def sync_symbol_interval(
    db: Session,
    *,
    symbol: str,
    interval: Interval,
    keep: int = 1500,
) -> int:
    client = BinanceUSDMClient(api_key="", api_secret="")
    raw = client.klines(symbol=symbol, interval=interval, limit=keep)
    rows: list[dict] = []
    for k in raw:
        rows.append(
            {
                "open_time": _ms_to_dt(int(k[0])),
                "open": float(k[1]),
                "high": float(k[2]),
                "low": float(k[3]),
                "close": float(k[4]),
                "volume": float(k[5]),
                "close_time": _ms_to_dt(int(k[6])),
                "amount": float(k[7]),
                "num_trades": int(k[8]),
                "buy_volume": float(k[9]),
                "buy_amount": float(k[10]),
            }
        )
    compute_boll(rows, period=int(settings.kline_boll_period))
    compute_mouth_state(rows, interval=interval)
    inserted = upsert_klines(db, symbol=symbol, interval=interval, rows=rows)
    prune_old_klines(db, symbol=symbol, interval=interval, keep=keep)
    return inserted
