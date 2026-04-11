from __future__ import annotations

from datetime import datetime, timezone
from typing import Literal

from sqlalchemy import text
from sqlalchemy.orm import Session


Interval = Literal["1m", "5m", "15m", "30m", "1h", "4h"]

INTERVAL_TABLE: dict[Interval, str] = {
    "1m": "kline_1m",
    "5m": "kline_5m",
    "15m": "kline_15m",
    "30m": "kline_30m",
    "1h": "kline_1h",
    "4h": "kline_4h",
}


class KlineRow(dict):
    open_time: datetime
    open: float
    high: float
    low: float
    close: float
    boll_up: float | None
    boll_dn: float | None


def list_symbols(db: Session, interval: Interval) -> list[str]:
    table = INTERVAL_TABLE[interval]
    rows = db.execute(
        text(
            f"""
            SELECT symbol
            FROM (
                SELECT symbol, MIN(id) AS min_id
                FROM "{table}"
                GROUP BY symbol
            ) t
            ORDER BY t.min_id ASC
            """
        )
    ).all()
    return [r[0] for r in rows]


def iter_klines(
    db: Session,
    *,
    symbol: str,
    interval: Interval,
    start_time: datetime | None,
    end_time: datetime | None,
    limit: int = 5000,
):
    table = INTERVAL_TABLE[interval]
    sql = f"""
        SELECT open_time, open, high, low, close, boll_up, boll_dn
        FROM "{table}"
        WHERE symbol = :symbol
          AND (:start_time IS NULL OR open_time >= :start_time)
          AND (:end_time IS NULL OR open_time <= :end_time)
        ORDER BY open_time ASC
        LIMIT :limit
    """
    rows = db.execute(
        text(sql),
        {"symbol": symbol, "start_time": start_time, "end_time": end_time, "limit": limit},
    ).mappings()
    for r in rows:
        ot: datetime = r["open_time"]
        yield {
            "open_time": ot,
            "open_time_ms": int(ot.replace(tzinfo=timezone.utc).timestamp() * 1000),
            "open": float(r["open"]),
            "high": float(r["high"]),
            "low": float(r["low"]),
            "close": float(r["close"]),
            "boll_up": float(r["boll_up"]) if r["boll_up"] is not None else None,
            "boll_dn": float(r["boll_dn"]) if r["boll_dn"] is not None else None,
        }


def iter_klines_recent(
    db: Session,
    *,
    symbol: str,
    interval: Interval,
    limit: int = 1500,
):
    """最近 N 根 K（时间正序）。与 iter_klines 不同：表中行数超过 limit 时仍返回「最新」一段，避免丢掉当前未收盘 K。"""
    table = INTERVAL_TABLE[interval]
    sql = f"""
        SELECT open_time, open, high, low, close, boll_up, boll_dn
        FROM "{table}"
        WHERE symbol = :symbol
        ORDER BY open_time DESC
        LIMIT :limit
    """
    rows = list(db.execute(text(sql), {"symbol": symbol, "limit": limit}).mappings())
    rows.reverse()
    for r in rows:
        ot: datetime = r["open_time"]
        yield {
            "open_time": ot,
            "open_time_ms": int(ot.replace(tzinfo=timezone.utc).timestamp() * 1000),
            "open": float(r["open"]),
            "high": float(r["high"]),
            "low": float(r["low"]),
            "close": float(r["close"]),
            "boll_up": float(r["boll_up"]) if r["boll_up"] is not None else None,
            "boll_dn": float(r["boll_dn"]) if r["boll_dn"] is not None else None,
        }


def list_recent_klines(
    db: Session,
    *,
    symbol: str,
    interval: Interval,
    limit: int = 1500,
) -> list[dict]:
    table = INTERVAL_TABLE[interval]
    sql = f"""
        SELECT
          open_time, close_time,
          open, high, low, close,
          volume, amount, num_trades, buy_volume, buy_amount,
          boll_up, boll_mb, boll_dn, bw, mouth_state
        FROM "{table}"
        WHERE symbol = :symbol
        ORDER BY open_time DESC
        LIMIT :limit
    """
    rows = list(db.execute(text(sql), {"symbol": symbol, "limit": limit}).mappings())
    rows.reverse()
    out: list[dict] = []
    for r in rows:
        out.append(
            {
                "open_time": r["open_time"],
                "close_time": r["close_time"],
                "open": float(r["open"]),
                "high": float(r["high"]),
                "low": float(r["low"]),
                "close": float(r["close"]),
                "volume": float(r["volume"]) if r["volume"] is not None else 0.0,
                "amount": float(r["amount"]) if r["amount"] is not None else 0.0,
                "num_trades": int(r["num_trades"]) if r["num_trades"] is not None else 0,
                "buy_volume": float(r["buy_volume"]) if r["buy_volume"] is not None else 0.0,
                "buy_amount": float(r["buy_amount"]) if r["buy_amount"] is not None else 0.0,
                "boll_up": float(r["boll_up"]) if r["boll_up"] is not None else None,
                "boll_mb": float(r["boll_mb"]) if r["boll_mb"] is not None else None,
                "boll_dn": float(r["boll_dn"]) if r["boll_dn"] is not None else None,
                "bw": float(r["bw"]) if r.get("bw") is not None else None,
                "mouth_state": int(r["mouth_state"]) if r.get("mouth_state") is not None else 0,
            }
        )
    return out


def upsert_klines(
    db: Session,
    *,
    symbol: str,
    interval: Interval,
    rows: list[dict],
) -> int:
    table = INTERVAL_TABLE[interval]
    sql = f"""
        INSERT INTO "{table}"
        (symbol, open, high, low, close, volume, amount, num_trades, buy_volume, buy_amount, open_time, close_time, boll_up, boll_mb, boll_dn, bw, mouth_state, is_check, is_key)
        VALUES
        (:symbol, :open, :high, :low, :close, :volume, :amount, :num_trades, :buy_volume, :buy_amount, :open_time, :close_time, :boll_up, :boll_mb, :boll_dn, :bw, :mouth_state, 0, 0)
        ON CONFLICT (symbol, open_time) DO UPDATE SET
            open = EXCLUDED.open,
            high = EXCLUDED.high,
            low = EXCLUDED.low,
            close = EXCLUDED.close,
            volume = EXCLUDED.volume,
            amount = EXCLUDED.amount,
            num_trades = EXCLUDED.num_trades,
            buy_volume = EXCLUDED.buy_volume,
            buy_amount = EXCLUDED.buy_amount,
            close_time = EXCLUDED.close_time,
            boll_up = EXCLUDED.boll_up,
            boll_mb = EXCLUDED.boll_mb,
            boll_dn = EXCLUDED.boll_dn,
            bw = EXCLUDED.bw,
            mouth_state = EXCLUDED.mouth_state
    """
    count = 0
    for r in rows:
        db.execute(
            text(sql),
            {
                "symbol": symbol,
                "open": r["open"],
                "high": r["high"],
                "low": r["low"],
                "close": r["close"],
                "volume": r.get("volume", 0.0),
                "amount": r.get("amount", 0.0),
                "num_trades": r.get("num_trades", 0),
                "buy_volume": r.get("buy_volume", 0.0),
                "buy_amount": r.get("buy_amount", 0.0),
                "open_time": r["open_time"],
                "close_time": r["close_time"],
                "boll_up": r.get("boll_up"),
                "boll_mb": r.get("boll_mb"),
                "boll_dn": r.get("boll_dn"),
                "bw": r.get("bw"),
                "mouth_state": int(r.get("mouth_state") or 0),
            },
        )
        count += 1
    return count


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


def compute_mouth_state_from_db(
    db: Session,
    *,
    symbol: str,
    interval: Interval,
    open_time: datetime,
    boll_up: float | None,
    boll_mb: float | None,
    boll_dn: float | None,
    lookback: int = 1200,
) -> int:
    if boll_up is None or boll_mb is None or boll_dn is None:
        return 0
    mbf = float(boll_mb)
    if mbf == 0.0:
        return 0

    table = INTERVAL_TABLE[interval]
    sql = f"""
        SELECT boll_up, boll_mb, boll_dn
        FROM "{table}"
        WHERE symbol = :symbol
          AND boll_mb IS NOT NULL
          AND open_time < :open_time
        ORDER BY open_time DESC
        LIMIT :limit
    """
    rows = list(
        db.execute(text(sql), {"symbol": symbol, "open_time": open_time, "limit": int(lookback)}).mappings()
    )
    rows.reverse()

    eps, switch_delta, confirm_bars = _mouth_params(interval)
    ctx: dict = {"state": 0, "peak": None, "trough": None, "drop_streak": 0, "rise_streak": 0, "last_v": None}
    for r in rows:
        mb = r.get("boll_mb")
        up = r.get("boll_up")
        dn = r.get("boll_dn")
        if mb is None or up is None or dn is None:
            continue
        mbv = float(mb)
        if mbv == 0.0:
            continue
        v = (float(up) - float(dn)) / mbv
        _update_mouth_state(ctx, v, eps=eps, switch_delta=switch_delta, confirm_bars=confirm_bars)

    v_now = (float(boll_up) - float(boll_dn)) / mbf
    return _update_mouth_state(ctx, v_now, eps=eps, switch_delta=switch_delta, confirm_bars=confirm_bars)


def prune_old_klines(db: Session, *, symbol: str, interval: Interval, keep: int = 1500) -> int:
    table = INTERVAL_TABLE[interval]
    sql = f"""
        DELETE FROM "{table}" t
        WHERE t.symbol = :symbol
          AND t.open_time < (
            SELECT open_time
            FROM "{table}" s
            WHERE s.symbol = :symbol
            ORDER BY s.open_time DESC
            OFFSET :keep - 1
            LIMIT 1
          )
    """
    res = db.execute(text(sql), {"symbol": symbol, "keep": keep})
    return res.rowcount or 0


def iter_klines_after(
    db: Session,
    *,
    symbol: str,
    interval: Interval,
    after_time: datetime,
    end_time: datetime | None,
    limit: int = 5000,
):
    table = INTERVAL_TABLE[interval]
    sql = f"""
        SELECT open_time, open, high, low, close, boll_up, boll_dn
        FROM "{table}"
        WHERE symbol = :symbol
          AND open_time > :after_time
          AND (:end_time IS NULL OR open_time <= :end_time)
        ORDER BY open_time ASC
        LIMIT :limit
    """
    rows = db.execute(
        text(sql),
        {"symbol": symbol, "after_time": after_time, "end_time": end_time, "limit": limit},
    ).mappings()
    for r in rows:
        yield {
            "open_time": r["open_time"],
            "open": float(r["open"]),
            "high": float(r["high"]),
            "low": float(r["low"]),
            "close": float(r["close"]),
            "boll_up": float(r["boll_up"]) if r["boll_up"] is not None else None,
            "boll_dn": float(r["boll_dn"]) if r["boll_dn"] is not None else None,
        }


def iter_klines_paged(
    db: Session,
    *,
    symbol: str,
    interval: Interval,
    start_time: datetime | None,
    end_time: datetime | None,
    after_time: datetime | None,
    page_size: int = 5000,
):
    cursor = after_time
    while True:
        if cursor is None:
            batch = list(
                iter_klines(
                    db,
                    symbol=symbol,
                    interval=interval,
                    start_time=start_time,
                    end_time=end_time,
                    limit=page_size,
                )
            )
        else:
            batch = list(
                iter_klines_after(
                    db,
                    symbol=symbol,
                    interval=interval,
                    after_time=cursor,
                    end_time=end_time,
                    limit=page_size,
                )
            )
        if not batch:
            return
        for r in batch:
            yield r
        cursor = batch[-1]["open_time"]
