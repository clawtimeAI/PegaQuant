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
          boll_up, boll_mb, boll_dn
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
        (symbol, open, high, low, close, volume, amount, num_trades, buy_volume, buy_amount, open_time, close_time, boll_up, boll_mb, boll_dn, is_check, is_key)
        VALUES
        (:symbol, :open, :high, :low, :close, :volume, :amount, :num_trades, :buy_volume, :buy_amount, :open_time, :close_time, :boll_up, :boll_mb, :boll_dn, 0, 0)
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
            boll_dn = EXCLUDED.boll_dn
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
            },
        )
        count += 1
    return count


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
