from fastapi import APIRouter

from app.api.binance import router as binance_router
from app.api.market import router as market_router
from app.api.trade import router as trade_router
from app.api.trend import router as trend_router

api_router = APIRouter(prefix="/api")
api_router.include_router(trend_router)
api_router.include_router(binance_router)
api_router.include_router(market_router)
api_router.include_router(trade_router)
