from __future__ import annotations

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models import TradingAccount


def list_trading_accounts(
    db: Session,
    *,
    owner_user_id: int,
    exchange_name: str = "binanceusdm",
    include_inactive: bool = True,
) -> list[TradingAccount]:
    stmt = select(TradingAccount).where(
        TradingAccount.owner_user_id == owner_user_id,
        TradingAccount.exchange_name == exchange_name,
    )
    if not include_inactive:
        stmt = stmt.where(TradingAccount.is_active.is_(True))
    stmt = stmt.order_by(TradingAccount.id.desc())
    return list(db.execute(stmt).scalars().all())


def get_trading_account(db: Session, *, owner_user_id: int, account_id: int) -> TradingAccount | None:
    stmt = (
        select(TradingAccount)
        .where(TradingAccount.id == account_id, TradingAccount.owner_user_id == owner_user_id)
        .limit(1)
    )
    return db.execute(stmt).scalars().first()


def create_trading_account(
    db: Session,
    *,
    owner_user_id: int,
    account_name: str,
    account_group: str,
    api_key: str,
    encrypted_secret: str,
    exchange_name: str = "binanceusdm",
    is_active: bool = True,
) -> TradingAccount:
    row = TradingAccount(
        owner_user_id=owner_user_id,
        account_name=account_name,
        account_group=account_group,
        exchange_name=exchange_name,
        api_key=api_key,
        encrypted_secret=encrypted_secret,
        is_active=is_active,
    )
    db.add(row)
    db.flush()
    return row


def set_trading_account_active(
    db: Session,
    *,
    owner_user_id: int,
    account_id: int,
    is_active: bool,
) -> TradingAccount | None:
    row = get_trading_account(db, owner_user_id=owner_user_id, account_id=account_id)
    if row is None:
        return None
    row.is_active = is_active
    db.add(row)
    db.flush()
    return row


def delete_trading_account(db: Session, *, owner_user_id: int, account_id: int) -> bool:
    row = get_trading_account(db, owner_user_id=owner_user_id, account_id=account_id)
    if row is None:
        return False
    db.delete(row)
    db.flush()
    return True

