from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from typing import Any, Literal

from sqlalchemy.orm import Session

from app.repositories.kline_repo import Interval, iter_klines_paged
from app.repositories.oscillation_repo import close_structure, create_structure, get_active_structure
from app.settings import settings


Side = Literal["X", "Y"]


@dataclass(frozen=True)
class KeyPoint:
    kind: Side
    time: datetime
    price: float


def _is_in_band(close: float, boll_up: float | None, boll_dn: float | None) -> bool:
    if boll_up is None or boll_dn is None:
        return False
    return boll_dn <= close <= boll_up


def _is_outside_low(low: float, boll_dn: float | None) -> bool:
    if boll_dn is None:
        return False
    return low < boll_dn


def _is_outside_high(high: float, boll_up: float | None) -> bool:
    if boll_up is None:
        return False
    return high > boll_up


def run_oscillation_engine(
    db: Session,
    *,
    symbol: str,
    interval: Interval,
    start_time: datetime | None,
    end_time: datetime | None,
    confirm_bars: int | None,
    break_pct: float | None,
    break_extreme_pct: float | None,
) -> dict[str, Any]:
    confirm_bars = settings.osc_confirm_bars if confirm_bars is None else confirm_bars
    break_pct = settings.osc_break_pct if break_pct is None else break_pct
    break_extreme_pct = settings.osc_break_extreme_pct if break_extreme_pct is None else break_extreme_pct

    active = get_active_structure(db, symbol=symbol, interval=interval)
    if active is None:
        active = create_structure(db, symbol=symbol, interval=interval, start_time=None)

    state: dict[str, Any] = active.engine_state or {}
    last_processed_raw = state.get("last_processed_open_time")
    last_processed: datetime | None
    if isinstance(last_processed_raw, str) and last_processed_raw:
        last_processed = datetime.fromisoformat(last_processed_raw)
    else:
        last_processed = None
    pending: dict[str, Any] | None = state.get("pending")
    inband_count: int = int(state.get("inband_count") or 0)

    if start_time is not None:
        last_processed = None
        pending = None
        inband_count = 0

    kline_iter = iter_klines_paged(
        db,
        symbol=symbol,
        interval=interval,
        start_time=start_time,
        end_time=end_time,
        after_time=last_processed,
    )

    x_points: list[dict[str, Any]] = list(active.x_points or [])
    y_points: list[dict[str, Any]] = list(active.y_points or [])

    processed = 0

    def append_point(p: KeyPoint):
        nonlocal x_points, y_points
        item = {"time": p.time.isoformat(), "price": p.price, "kind": p.kind}
        if p.kind == "X":
            x_points.append(item)
        else:
            y_points.append(item)

    def maybe_init_start_time(p: KeyPoint):
        if active.start_time is None:
            active.start_time = p.time

    def check_break(row: dict[str, Any]) -> dict[str, Any] | None:
        if len(x_points) > 0:
            last_x = float(x_points[-1]["price"])
            min_x = min(float(p["price"]) for p in x_points)
            last_threshold = last_x * (1 - break_pct)
            extreme_threshold = min_x * (1 - break_extreme_pct)
            if row["low"] < last_threshold and row["low"] < extreme_threshold:
                return {
                    "side": "X",
                    "break_price": row["low"],
                    "break_open_time": row["open_time"].isoformat(),
                    "last_key_price": last_x,
                    "extreme_key_price": min_x,
                    "break_last_pct": break_pct,
                    "break_extreme_pct": break_extreme_pct,
                    "last_threshold": last_threshold,
                    "extreme_threshold": extreme_threshold,
                }
        if len(y_points) > 0:
            last_y = float(y_points[-1]["price"])
            max_y = max(float(p["price"]) for p in y_points)
            last_threshold = last_y * (1 + break_pct)
            extreme_threshold = max_y * (1 + break_extreme_pct)
            if row["high"] > last_threshold and row["high"] > extreme_threshold:
                return {
                    "side": "Y",
                    "break_price": row["high"],
                    "break_open_time": row["open_time"].isoformat(),
                    "last_key_price": last_y,
                    "extreme_key_price": max_y,
                    "break_last_pct": break_pct,
                    "break_extreme_pct": break_extreme_pct,
                    "last_threshold": last_threshold,
                    "extreme_threshold": extreme_threshold,
                }
        return None

    for row in kline_iter:
        processed += 1
        last_processed = row["open_time"]

        break_info = check_break(row)
        if break_info is not None and active.status == "ACTIVE":
            close_structure(
                db,
                structure=active,
                end_time=row["open_time"],
                close_reason="BREAK_DUAL",
                close_condition=break_info,
            )
            active.x_points = x_points
            active.y_points = y_points
            active.engine_state = {
                "last_processed_open_time": row["open_time"].isoformat(),
                "pending": None,
                "inband_count": 0,
            }
            db.flush()
            active = create_structure(db, symbol=symbol, interval=interval, start_time=row["open_time"])
            x_points = []
            y_points = []
            pending = None
            inband_count = 0

        high = row["high"]
        low = row["low"]
        close = row["close"]
        boll_up = row["boll_up"]
        boll_dn = row["boll_dn"]

        if _is_outside_low(low, boll_dn):
            if pending is None or pending.get("kind") != "X":
                pending = {"kind": "X", "time": row["open_time"].isoformat(), "price": low}
            else:
                if low < float(pending["price"]):
                    pending = {"kind": "X", "time": row["open_time"].isoformat(), "price": low}
            inband_count = 0
        elif _is_outside_high(high, boll_up):
            if pending is None or pending.get("kind") != "Y":
                pending = {"kind": "Y", "time": row["open_time"].isoformat(), "price": high}
            else:
                if high > float(pending["price"]):
                    pending = {"kind": "Y", "time": row["open_time"].isoformat(), "price": high}
            inband_count = 0
        else:
            if pending is not None and _is_in_band(close, boll_up, boll_dn):
                inband_count += 1
                if inband_count >= confirm_bars:
                    kp = KeyPoint(
                        kind=pending["kind"],
                        time=datetime.fromisoformat(pending["time"]),
                        price=float(pending["price"]),
                    )
                    maybe_init_start_time(kp)
                    append_point(kp)
                    pending = None
                    inband_count = 0
            else:
                inband_count = 0

    active.x_points = x_points
    active.y_points = y_points
    active.engine_state = {
        "last_processed_open_time": last_processed.isoformat() if last_processed is not None else None,
        "pending": pending,
        "inband_count": inband_count,
        "confirm_bars": confirm_bars,
        "break_pct": break_pct,
        "break_extreme_pct": break_extreme_pct,
    }
    db.add(active)
    db.commit()

    return {"processed_bars": processed, "active_structure_id": active.id}
