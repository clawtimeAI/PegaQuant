from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field
from sqlalchemy.orm import Session

from app.database import get_db
from app.models import TradeExecution
from app.repositories.trading_account_repo import get_trading_account
from app.services.binance_usdm_client import BinanceUSDMClient, BinanceUSDMError
from app.services.crypto_service import decrypt_secret

router = APIRouter(prefix="/trade", tags=["trade"])


class MarketOrderIn(BaseModel):
    account_id: int
    symbol: str
    side: str  # BUY / SELL
    qty: float
    leverage: int | None = None
    take_profit_price: float | None = None
    stop_loss_price: float | None = None


class ClosePositionIn(BaseModel):
    account_id: int
    symbol: str
    pct: float = Field(1.0, ge=0.0, le=1.0)


@router.post("/order/market")
def place_market_order(body: MarketOrderIn, db: Session = Depends(get_db)):
    acc = get_trading_account(db, owner_user_id=1, account_id=body.account_id)
    if acc is None or not acc.is_active:
        raise HTTPException(status_code=404, detail="account not found or inactive")
    client = BinanceUSDMClient(api_key=acc.api_key, api_secret=decrypt_secret(acc.encrypted_secret))

    try:
        order = client.order_create(symbol=body.symbol, side=body.side, type="MARKET", quantity=body.qty)
    except BinanceUSDMError as e:
        raise HTTPException(status_code=400, detail=e.body) from None

    tp_res = None
    sl_res = None
    if body.take_profit_price:
        try:
            tp_res = client.order_create(
                symbol=body.symbol,
                side="SELL" if body.side == "BUY" else "BUY",
                type="TAKE_PROFIT_MARKET",
                stop_price=body.take_profit_price,
                reduce_only=True,
                close_position=True,
                working_type="CONTRACT_PRICE",
            )
        except BinanceUSDMError as e:
            tp_res = {"error": e.body}
    if body.stop_loss_price:
        try:
            sl_res = client.order_create(
                symbol=body.symbol,
                side="SELL" if body.side == "BUY" else "BUY",
                type="STOP_MARKET",
                stop_price=body.stop_loss_price,
                reduce_only=True,
                close_position=True,
                working_type="CONTRACT_PRICE",
            )
        except BinanceUSDMError as e:
            sl_res = {"error": e.body}

    exec_row = TradeExecution(
        account_id=acc.id,
        symbol=body.symbol,
        side=body.side,
        qty=body.qty,
        leverage=body.leverage or 1,
        entry_order_id=str(order.get("orderId")),
        tp_order_id=str(tp_res.get("orderId")) if isinstance(tp_res, dict) else None,
        sl_order_id=str(sl_res.get("orderId")) if isinstance(sl_res, dict) else None,
        entry_price=float(order.get("avgPrice") or 0) if isinstance(order, dict) else None,
        take_profit_price=body.take_profit_price,
        stop_loss_price=body.stop_loss_price,
        response_payload={"entry": order, "tp": tp_res, "sl": sl_res},
    )
    db.add(exec_row)
    db.commit()
    db.refresh(exec_row)
    return {"ok": True, "execution_id": exec_row.id, "entry_order": order, "tp_order": tp_res, "sl_order": sl_res}


@router.post("/position/close")
def close_position(body: ClosePositionIn, db: Session = Depends(get_db)):
    acc = get_trading_account(db, owner_user_id=1, account_id=body.account_id)
    if acc is None or not acc.is_active:
        raise HTTPException(status_code=404, detail="account not found or inactive")

    client = BinanceUSDMClient(api_key=acc.api_key, api_secret=decrypt_secret(acc.encrypted_secret))
    try:
        positions = client.positions()
    except BinanceUSDMError as e:
        raise HTTPException(status_code=400, detail=e.body) from None

    p = next((x for x in positions if str(x.get("symbol") or "").upper() == body.symbol.upper()), None)
    if p is None:
        return {"ok": True, "skipped": True, "reason": "position not found"}

    try:
        amt = float(p.get("positionAmt") or 0.0)
    except Exception:
        amt = 0.0

    if abs(amt) <= 0.0:
        return {"ok": True, "skipped": True, "reason": "no position"}

    pct = float(body.pct or 1.0)
    qty = abs(amt) * pct
    if qty <= 0.0:
        return {"ok": True, "skipped": True, "reason": "qty <= 0"}

    side = "SELL" if amt > 0 else "BUY"
    try:
        order = client.order_create(
            symbol=body.symbol,
            side=side,
            type="MARKET",
            quantity=qty,
            reduce_only=True,
        )
    except BinanceUSDMError as e:
        raise HTTPException(status_code=400, detail=e.body) from None

    return {"ok": True, "symbol": body.symbol, "side": side, "qty": qty, "order": order}
