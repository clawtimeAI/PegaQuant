from fastapi import APIRouter

from app.api.binance import router as binance_router
from app.api.market import router as market_router
from app.api.trade import router as trade_router
from app.api.trend import router as trend_router
from app.api.trend import router2 as trend2_router
from app.api.trend import router3 as trend3_router
from app.api.trend import router4 as trend4_router
from app.api.trend import router_bt as backtest_router
from app.api.trend import router_sig as signal_router

api_router = APIRouter(prefix="/api")
api_router.include_router(trend_router)
api_router.include_router(trend2_router)
api_router.include_router(trend3_router)
api_router.include_router(trend4_router)
api_router.include_router(backtest_router)
api_router.include_router(signal_router)
api_router.include_router(binance_router)
api_router.include_router(market_router)
api_router.include_router(trade_router)
