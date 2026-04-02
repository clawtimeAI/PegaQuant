from __future__ import annotations

import argparse
from datetime import datetime

from sqlalchemy import delete

from app.database import SessionLocal
from app.models import OscillationStructure
from app.repositories.kline_repo import Interval
from app.services.oscillation_engine import run_oscillation_engine


def _parse_dt(v: str | None) -> datetime | None:
    if not v:
        return None
    return datetime.fromisoformat(v)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("symbol", type=str)
    parser.add_argument("--start", type=str, default=None)
    parser.add_argument("--end", type=str, default=None)
    parser.add_argument(
        "--intervals",
        type=str,
        default="1m,5m,15m,30m,1h,4h",
    )
    parser.add_argument("--confirm-bars", type=int, default=None)
    parser.add_argument("--break-pct", type=float, default=None)
    parser.add_argument("--break-extreme-pct", type=float, default=None)
    parser.add_argument("--reset", action="store_true")
    args = parser.parse_args()

    symbol: str = args.symbol
    start_time = _parse_dt(args.start)
    end_time = _parse_dt(args.end)
    intervals: list[Interval] = [i.strip() for i in args.intervals.split(",") if i.strip()]  # type: ignore[assignment]

    with SessionLocal() as db:
        for interval in intervals:
            if args.reset:
                db.execute(
                    delete(OscillationStructure).where(
                        OscillationStructure.symbol == symbol,
                        OscillationStructure.interval == interval,
                    )
                )
                db.commit()

            result = run_oscillation_engine(
                db,
                symbol=symbol,
                interval=interval,
                start_time=start_time,
                end_time=end_time,
                confirm_bars=args.confirm_bars,
                break_pct=args.break_pct,
                break_extreme_pct=args.break_extreme_pct,
            )
            print(f"{symbol} {interval} -> {result}")


if __name__ == "__main__":
    main()
