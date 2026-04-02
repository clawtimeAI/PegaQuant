from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session

from app.database import get_db
from app.repositories.kline_repo import Interval, list_symbols
from app.repositories.oscillation_repo import get_structure, list_structures
from app.schemas import OscillationRunIn, OscillationStructureOut
from app.services.oscillation_engine import run_oscillation_engine

router = APIRouter(prefix="/trend", tags=["trend"])


@router.get("/intervals")
def get_intervals():
    return ["1m", "5m", "15m", "30m", "1h", "4h"]


@router.get("/symbols")
def get_symbols(interval: Interval = Query(...), db: Session = Depends(get_db)):
    return list_symbols(db, interval)


@router.get("/structures", response_model=list[OscillationStructureOut])
def get_structures(
    symbol: str = Query(...),
    interval: Interval = Query(...),
    status: str | None = Query(None),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
    db: Session = Depends(get_db),
):
    rows = list_structures(db, symbol=symbol, interval=interval, status=status, limit=limit, offset=offset)
    return rows


@router.get("/structures/{structure_id}", response_model=OscillationStructureOut)
def get_structure_detail(structure_id: int, db: Session = Depends(get_db)):
    s = get_structure(db, structure_id=structure_id)
    if s is None:
        raise HTTPException(status_code=404, detail="not found")
    return s


@router.post("/run")
def run_engine(body: OscillationRunIn, db: Session = Depends(get_db)):
    return run_oscillation_engine(
        db,
        symbol=body.symbol,
        interval=body.interval,
        start_time=body.start_time,
        end_time=body.end_time,
        confirm_bars=body.confirm_bars,
        break_pct=body.break_pct,
        break_extreme_pct=body.break_extreme_pct,
    )
