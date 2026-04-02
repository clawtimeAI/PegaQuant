from __future__ import annotations

import time

from fastapi import APIRouter, Depends, Query
from sqlalchemy.orm import Session

from app.database import get_db
from app.repositories.kline_repo import Interval, iter_klines_recent
from app.services.kline_sync_service import sync_symbol_interval

router = APIRouter(prefix="/market", tags=["market"])

_SUBS: dict[tuple[str, Interval], dict[str, float]] = {}

_INTERVAL_MIN_SYNC_SEC: dict[Interval, int] = {
    "1m": 10,
    "5m": 30,
    "15m": 60,
    "30m": 120,
    "1h": 300,
    "4h": 900,
}


def touch_subscription(*, symbol: str, interval: Interval) -> None:
    now = time.time()
    key = (symbol.upper(), interval)
    state = _SUBS.get(key)
    if state is None:
        _SUBS[key] = {"last_requested": now, "last_synced": 0.0}
    else:
        state["last_requested"] = now


def list_due_subscriptions(*, now: float, active_ttl_sec: int = 3600) -> list[tuple[str, Interval]]:
    out: list[tuple[str, Interval]] = []
    for (symbol, interval), st in list(_SUBS.items()):
        if now - st.get("last_requested", 0.0) > active_ttl_sec:
            continue
        min_gap = _INTERVAL_MIN_SYNC_SEC[interval]
        if now - st.get("last_synced", 0.0) >= min_gap:
            out.append((symbol, interval))
    return out


def mark_subscription_synced(*, symbol: str, interval: Interval, now: float) -> None:
    key = (symbol.upper(), interval)
    st = _SUBS.get(key)
    if st is None:
        _SUBS[key] = {"last_requested": now, "last_synced": now}
    else:
        st["last_synced"] = now


@router.get("/klines")
def get_klines(
    symbol: str = Query(...),
    interval: Interval = Query(...),
    limit: int = Query(1500, ge=1, le=1500),
    ensure: bool = Query(True),
    db: Session = Depends(get_db),
):
    touch_subscription(symbol=symbol, interval=interval)
    if ensure:
        sync_symbol_interval(db, symbol=symbol, interval=interval, keep=limit)
        db.commit()
    out = list(iter_klines_recent(db, symbol=symbol, interval=interval, limit=limit))
    return out
