from __future__ import annotations

from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session

from app.database import get_db
from app.repositories.trading_account_repo import (
    create_trading_account,
    delete_trading_account,
    get_trading_account,
    list_trading_accounts,
    set_trading_account_active,
)
from app.schemas import TradingAccountCreateIn, TradingAccountOut
from app.services.binance_usdm_client import BinanceUSDMClient, BinanceUSDMError
from app.services.crypto_service import decrypt_secret, encrypt_secret

router = APIRouter(prefix="/binance", tags=["binance"])


OWNER_USER_ID = 1


def _client_from_account(*, api_key: str, encrypted_secret: str) -> BinanceUSDMClient:
    api_secret = decrypt_secret(encrypted_secret)
    return BinanceUSDMClient(api_key=api_key, api_secret=api_secret)


@router.get("/accounts", response_model=list[TradingAccountOut])
def get_accounts(
    include_inactive: bool = Query(True),
    db: Session = Depends(get_db),
):
    rows = list_trading_accounts(db, owner_user_id=OWNER_USER_ID, include_inactive=include_inactive)
    return rows


@router.post("/accounts", response_model=TradingAccountOut)
def create_account(body: TradingAccountCreateIn, db: Session = Depends(get_db)):
    try:
        encrypted = encrypt_secret(body.api_secret)
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e)) from None

    if body.validate_credentials:
        try:
            BinanceUSDMClient(api_key=body.api_key, api_secret=body.api_secret).account()
        except BinanceUSDMError as e:
            raise HTTPException(status_code=400, detail=f"binance_auth_failed: {e.body}") from None
        except Exception as e:
            raise HTTPException(status_code=400, detail=f"binance_request_failed: {e}") from None

    row = create_trading_account(
        db,
        owner_user_id=OWNER_USER_ID,
        account_name=body.account_name,
        account_group=body.account_group,
        api_key=body.api_key,
        encrypted_secret=encrypted,
        is_active=body.is_active,
        exchange_name="binanceusdm",
    )
    db.commit()
    db.refresh(row)
    return row


@router.patch("/accounts/{account_id}/active", response_model=TradingAccountOut)
def set_account_active(account_id: int, is_active: bool = Query(...), db: Session = Depends(get_db)):
    row = set_trading_account_active(db, owner_user_id=OWNER_USER_ID, account_id=account_id, is_active=is_active)
    if row is None:
        raise HTTPException(status_code=404, detail="not found")
    db.commit()
    db.refresh(row)
    return row


@router.delete("/accounts/{account_id}")
def remove_account(account_id: int, db: Session = Depends(get_db)):
    ok = delete_trading_account(db, owner_user_id=OWNER_USER_ID, account_id=account_id)
    if not ok:
        raise HTTPException(status_code=404, detail="not found")
    db.commit()
    return {"ok": True}


@router.get("/accounts/{account_id}/account")
def account_info(account_id: int, db: Session = Depends(get_db)):
    row = get_trading_account(db, owner_user_id=OWNER_USER_ID, account_id=account_id)
    if row is None:
        raise HTTPException(status_code=404, detail="not found")
    try:
        return _client_from_account(api_key=row.api_key, encrypted_secret=row.encrypted_secret).account()
    except BinanceUSDMError as e:
        raise HTTPException(status_code=400, detail=e.body) from None


@router.get("/accounts/{account_id}/positions")
def positions(account_id: int, db: Session = Depends(get_db)):
    row = get_trading_account(db, owner_user_id=OWNER_USER_ID, account_id=account_id)
    if row is None:
        raise HTTPException(status_code=404, detail="not found")
    try:
        return _client_from_account(api_key=row.api_key, encrypted_secret=row.encrypted_secret).positions()
    except BinanceUSDMError as e:
        raise HTTPException(status_code=400, detail=e.body) from None


def _to_ms(v: datetime | None) -> int | None:
    if v is None:
        return None
    return int(v.timestamp() * 1000)


@router.get("/accounts/{account_id}/trades")
def trades(
    account_id: int,
    symbol: str = Query(...),
    start_time: datetime | None = Query(None),
    end_time: datetime | None = Query(None),
    limit: int = Query(200, ge=1, le=1000),
    db: Session = Depends(get_db),
):
    row = get_trading_account(db, owner_user_id=OWNER_USER_ID, account_id=account_id)
    if row is None:
        raise HTTPException(status_code=404, detail="not found")
    try:
        return _client_from_account(api_key=row.api_key, encrypted_secret=row.encrypted_secret).user_trades(
            symbol=symbol,
            start_time_ms=_to_ms(start_time),
            end_time_ms=_to_ms(end_time),
            limit=limit,
        )
    except BinanceUSDMError as e:
        raise HTTPException(status_code=400, detail=e.body) from None


@router.get("/accounts/{account_id}/income")
def income(
    account_id: int,
    income_type: str | None = Query(None),
    start_time: datetime | None = Query(None),
    end_time: datetime | None = Query(None),
    limit: int = Query(1000, ge=1, le=1000),
    db: Session = Depends(get_db),
):
    row = get_trading_account(db, owner_user_id=OWNER_USER_ID, account_id=account_id)
    if row is None:
        raise HTTPException(status_code=404, detail="not found")
    try:
        return _client_from_account(api_key=row.api_key, encrypted_secret=row.encrypted_secret).income_history(
            income_type=income_type,
            start_time_ms=_to_ms(start_time),
            end_time_ms=_to_ms(end_time),
            limit=limit,
        )
    except BinanceUSDMError as e:
        raise HTTPException(status_code=400, detail=e.body) from None


@router.get("/accounts/{account_id}/orders")
def orders(
    account_id: int,
    symbol: str = Query(...),
    start_time: datetime | None = Query(None),
    end_time: datetime | None = Query(None),
    limit: int = Query(200, ge=1, le=1000),
    db: Session = Depends(get_db),
):
    row = get_trading_account(db, owner_user_id=OWNER_USER_ID, account_id=account_id)
    if row is None:
        raise HTTPException(status_code=404, detail="not found")
    try:
        return _client_from_account(api_key=row.api_key, encrypted_secret=row.encrypted_secret).all_orders(
            symbol=symbol,
            start_time_ms=_to_ms(start_time),
            end_time_ms=_to_ms(end_time),
            limit=limit,
        )
    except BinanceUSDMError as e:
        raise HTTPException(status_code=400, detail=e.body) from None
