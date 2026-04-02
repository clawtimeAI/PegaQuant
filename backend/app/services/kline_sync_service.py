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
            continue
        window = closes[-period:]
        mb = sum(window) / period
        var = sum((c - mb) ** 2 for c in window) / period
        sd = var ** 0.5
        r["boll_mb"] = mb
        r["boll_up"] = mb + 2 * sd
        r["boll_dn"] = mb - 2 * sd


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
    inserted = upsert_klines(db, symbol=symbol, interval=interval, rows=rows)
    prune_old_klines(db, symbol=symbol, interval=interval, keep=keep)
    return inserted
