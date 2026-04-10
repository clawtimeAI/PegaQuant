from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Query, Request
from sqlalchemy import text
from sqlalchemy.orm import Session

from app.database import get_db
from app.repositories.kline_repo import Interval, list_symbols
from app.repositories.oscillation_repo import get_structure, list_structures
from app.schemas import AbcStructureOut, NewOscillationRunIn, OscillationRunIn, OscillationStructureOut, OscillationStructureV3Out
from app.services.oscillation_engine import run_oscillation_engine

router = APIRouter(prefix="/trend", tags=["trend"])
router2 = APIRouter(prefix="/trend2", tags=["trend2"])
router3 = APIRouter(prefix="/trend3", tags=["trend3"])


@router.get("/intervals")
def get_intervals():
    return ["1m", "5m", "15m", "30m", "1h", "4h"]


@router.get("/symbols")
def get_symbols(interval: Interval = Query(...), db: Session = Depends(get_db)):
    syms: list[str] = []
    try:
        syms = list_symbols(db, interval)
    except Exception:
        syms = []
    if syms:
        return syms
    rows = db.execute(
        text(
            """
            SELECT symbol, MIN(id) AS min_id
            FROM oscillation_structures
            WHERE interval = :interval
            GROUP BY symbol
            ORDER BY min_id ASC
            """
        ),
        {"interval": interval},
    ).all()
    return [r[0] for r in rows]


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


@router2.get("/intervals")
def get_intervals_v2():
    return ["1m", "5m", "15m", "30m", "1h", "4h"]


@router2.get("/symbols")
def get_symbols_v2(interval: Interval = Query(...), db: Session = Depends(get_db)):
    rows = db.execute(
        text(
            """
            SELECT symbol, MIN(id) AS min_id
            FROM oscillation_structures_v2
            WHERE interval = :interval
            GROUP BY symbol
            ORDER BY min_id ASC
            """
        ),
        {"interval": interval},
    ).all()
    syms = [r[0] for r in rows]
    if syms:
        return syms
    return list_symbols(db, interval)


@router2.get("/structures", response_model=list[OscillationStructureOut])
def get_structures_v2(
    symbol: str = Query(...),
    interval: Interval = Query(...),
    status: str | None = Query(None),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
    db: Session = Depends(get_db),
):
    sql = """
        SELECT
          id,
          symbol,
          interval,
          status,
          x_points,
          y_points,
          close_reason,
          close_condition,
          engine_state,
          start_time,
          end_time,
          created_at,
          updated_at
        FROM oscillation_structures_v2
        WHERE symbol = :symbol
          AND interval = :interval
          AND (:status IS NULL OR status = :status)
        ORDER BY id DESC
        LIMIT :limit
        OFFSET :offset
    """
    rows = db.execute(
        text(sql),
        {"symbol": symbol.upper(), "interval": interval, "status": status, "limit": limit, "offset": offset},
    ).mappings()
    return [dict(r) for r in rows]


@router2.get("/structures/{structure_id}", response_model=OscillationStructureOut)
def get_structure_detail_v2(structure_id: int, db: Session = Depends(get_db)):
    sql = """
        SELECT
          id,
          symbol,
          interval,
          status,
          x_points,
          y_points,
          close_reason,
          close_condition,
          engine_state,
          start_time,
          end_time,
          created_at,
          updated_at
        FROM oscillation_structures_v2
        WHERE id = :id
        LIMIT 1
    """
    row = db.execute(text(sql), {"id": structure_id}).mappings().first()
    if row is None:
        raise HTTPException(status_code=404, detail="not found")
    return dict(row)


@router2.post("/run")
def run_engine_v2(body: NewOscillationRunIn):
    return {"ok": True, "hint": "run in webman: NewOscillationEngine::run", "symbol": body.symbol, "interval": body.interval}


@router2.get("/runner/status")
def runner_status_v2(request: Request):
    return runner_status(request)


@router2.post("/runner/start")
async def runner_start_v2(
    request: Request,
    dry_run: bool | None = Query(None),
    mode: str | None = Query(None),
):
    return await runner_start(request=request, dry_run=dry_run, mode=mode)


@router2.post("/runner/stop")
async def runner_stop_v2(request: Request):
    return await runner_stop(request=request)


@router3.get("/intervals")
def get_intervals_v3():
    return ["1m", "5m", "15m", "30m", "1h", "4h"]


@router3.get("/symbols")
def get_symbols_v3(interval: Interval = Query(...), db: Session = Depends(get_db)):
    rows = db.execute(
        text(
            """
            SELECT symbol, MIN(id) AS min_id
            FROM oscillation_structures_v3
            WHERE interval = :interval
            GROUP BY symbol
            ORDER BY min_id ASC
            """
        ),
        {"interval": interval},
    ).all()
    syms = [r[0] for r in rows]
    if syms:
        return syms
    return list_symbols(db, interval)


@router3.get("/structures", response_model=list[OscillationStructureV3Out])
def get_structures_v3(
    symbol: str = Query(...),
    interval: Interval = Query(...),
    status: str | None = Query(None),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
    db: Session = Depends(get_db),
):
    sql = """
        SELECT
          id,
          symbol,
          interval,
          status,
          x_points,
          y_points,
          a_point,
          episode,
          close_reason,
          close_condition,
          engine_state,
          start_time,
          end_time,
          created_at,
          updated_at
        FROM oscillation_structures_v3
        WHERE symbol = :symbol
          AND interval = :interval
          AND (:status IS NULL OR status = :status)
        ORDER BY id DESC
        LIMIT :limit
        OFFSET :offset
    """
    params = {"symbol": symbol.upper(), "interval": interval, "status": status, "limit": limit, "offset": offset}
    try:
        rows = db.execute(text(sql), params).mappings()
        return [dict(r) for r in rows]
    except Exception:
        sql2 = """
            SELECT
              id,
              symbol,
              interval,
              status,
              a_point,
              episode,
              close_reason,
              close_condition,
              engine_state,
              start_time,
              end_time,
              created_at,
              updated_at
            FROM oscillation_structures_v3
            WHERE symbol = :symbol
              AND interval = :interval
              AND (:status IS NULL OR status = :status)
            ORDER BY id DESC
            LIMIT :limit
            OFFSET :offset
        """
        rows = db.execute(text(sql2), params).mappings()
        out = []
        for r in rows:
            d = dict(r)
            d["x_points"] = []
            d["y_points"] = []
            out.append(d)
        return out


@router3.get("/structures/{structure_id}", response_model=OscillationStructureV3Out)
def get_structure_detail_v3(structure_id: int, db: Session = Depends(get_db)):
    sql = """
        SELECT
          id,
          symbol,
          interval,
          status,
          x_points,
          y_points,
          a_point,
          episode,
          close_reason,
          close_condition,
          engine_state,
          start_time,
          end_time,
          created_at,
          updated_at
        FROM oscillation_structures_v3
        WHERE id = :id
        LIMIT 1
    """
    try:
        row = db.execute(text(sql), {"id": structure_id}).mappings().first()
        if row is None:
            raise HTTPException(status_code=404, detail="not found")
        return dict(row)
    except Exception:
        sql2 = """
            SELECT
              id,
              symbol,
              interval,
              status,
              a_point,
              episode,
              close_reason,
              close_condition,
              engine_state,
              start_time,
              end_time,
              created_at,
              updated_at
            FROM oscillation_structures_v3
            WHERE id = :id
            LIMIT 1
        """
        row2 = db.execute(text(sql2), {"id": structure_id}).mappings().first()
        if row2 is None:
            raise HTTPException(status_code=404, detail="not found")
        d = dict(row2)
        d["x_points"] = []
        d["y_points"] = []
        return d


@router3.post("/run")
def run_engine_v3(body: NewOscillationRunIn):
    return {"ok": True, "hint": "run in webman: NewOscillationEngineV3::run", "symbol": body.symbol, "interval": body.interval}


@router3.get("/runner/status")
def runner_status_v3(request: Request):
    return runner_status(request)


@router3.post("/runner/start")
async def runner_start_v3(
    request: Request,
    dry_run: bool | None = Query(None),
    mode: str | None = Query(None),
):
    return await runner_start(request=request, dry_run=dry_run, mode=mode)


@router3.post("/runner/stop")
async def runner_stop_v3(request: Request):
    return await runner_stop(request=request)


@router.get("/abc/symbols")
def get_abc_symbols(interval: Interval = Query(...), db: Session = Depends(get_db)):
    syms: list[str] = []
    try:
        syms = list_symbols(db, interval)
    except Exception:
        syms = []
    if syms:
        return syms
    rows = db.execute(
        text(
            """
            SELECT symbol, MIN(id) AS min_id
            FROM abc_structures
            WHERE interval = :interval
            GROUP BY symbol
            ORDER BY min_id ASC
            """
        ),
        {"interval": interval},
    ).all()
    return [r[0] for r in rows]


@router.get("/abc/structures", response_model=list[AbcStructureOut])
def get_abc_structures(
    symbol: str = Query(...),
    interval: Interval = Query(...),
    status: str | None = Query(None),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
    db: Session = Depends(get_db),
):
    sql = """
        SELECT
          id,
          symbol,
          interval,
          status,
          direction,
          last_state,
          a_point,
          a_confirm_time,
          b_points,
          c_points,
          close_reason,
          close_condition,
          engine_state,
          start_time,
          end_time,
          created_at,
          updated_at
        FROM abc_structures
        WHERE symbol = :symbol
          AND interval = :interval
          AND (:status IS NULL OR status = :status)
        ORDER BY id DESC
        LIMIT :limit
        OFFSET :offset
    """
    rows = db.execute(
        text(sql),
        {"symbol": symbol.upper(), "interval": interval, "status": status, "limit": limit, "offset": offset},
    ).mappings()
    return [dict(r) for r in rows]


@router.get("/abc/structures/{structure_id}", response_model=AbcStructureOut)
def get_abc_structure_detail(structure_id: int, db: Session = Depends(get_db)):
    sql = """
        SELECT
          id,
          symbol,
          interval,
          status,
          direction,
          last_state,
          a_point,
          a_confirm_time,
          b_points,
          c_points,
          close_reason,
          close_condition,
          engine_state,
          start_time,
          end_time,
          created_at,
          updated_at
        FROM abc_structures
        WHERE id = :id
        LIMIT 1
    """
    row = db.execute(text(sql), {"id": structure_id}).mappings().first()
    if row is None:
        raise HTTPException(status_code=404, detail="not found")
    return dict(row)



@router.get("/runner/status")
def runner_status(request: Request):
    svc = getattr(request.app.state, "strategy_runner_service", None)
    if svc is None:
        return {"running": False}
    return svc.status()


@router.post("/runner/start")
async def runner_start(
    request: Request,
    dry_run: bool | None = Query(None),
    mode: str | None = Query(None),
):
    from app.settings import settings

    svc = getattr(request.app.state, "strategy_runner_service", None)
    if svc is None:
        raise HTTPException(status_code=500, detail="runner service not available")
    if dry_run is not None:
        settings.strategy_dry_run = bool(dry_run)
    if mode is not None and mode.strip():
        settings.strategy_mode = mode.strip()
    await svc.start()
    return svc.status()


@router.post("/runner/stop")
async def runner_stop(request: Request):
    svc = getattr(request.app.state, "strategy_runner_service", None)
    if svc is None:
        return {"ok": True, "running": False}
    await svc.stop()
    return svc.status()
