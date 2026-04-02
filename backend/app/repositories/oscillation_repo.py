from __future__ import annotations

from datetime import datetime

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models import OscillationStructure


def get_active_structure(db: Session, *, symbol: str, interval: str) -> OscillationStructure | None:
    stmt = (
        select(OscillationStructure)
        .where(OscillationStructure.symbol == symbol, OscillationStructure.interval == interval)
        .where(OscillationStructure.status == "ACTIVE")
        .order_by(OscillationStructure.id.desc())
        .limit(1)
    )
    return db.execute(stmt).scalars().first()


def list_structures(
    db: Session,
    *,
    symbol: str,
    interval: str,
    status: str | None,
    limit: int,
    offset: int,
) -> list[OscillationStructure]:
    stmt = select(OscillationStructure).where(
        OscillationStructure.symbol == symbol,
        OscillationStructure.interval == interval,
    )
    if status is not None:
        stmt = stmt.where(OscillationStructure.status == status)
    stmt = stmt.order_by(OscillationStructure.id.desc()).limit(limit).offset(offset)
    return list(db.execute(stmt).scalars().all())


def get_structure(db: Session, *, structure_id: int) -> OscillationStructure | None:
    stmt = select(OscillationStructure).where(OscillationStructure.id == structure_id).limit(1)
    return db.execute(stmt).scalars().first()


def create_structure(db: Session, *, symbol: str, interval: str, start_time: datetime | None) -> OscillationStructure:
    s = OscillationStructure(
        symbol=symbol,
        interval=interval,
        status="ACTIVE",
        x_points=[],
        y_points=[],
        engine_state=None,
        start_time=start_time,
        end_time=None,
    )
    db.add(s)
    db.flush()
    return s


def close_structure(
    db: Session,
    *,
    structure: OscillationStructure,
    end_time: datetime,
    close_reason: str,
    close_condition: dict,
):
    structure.status = "CLOSED"
    structure.end_time = end_time
    structure.close_reason = close_reason
    structure.close_condition = close_condition
    db.add(structure)

