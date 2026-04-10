from __future__ import annotations

import asyncio
import json
import time
import urllib.parse
import urllib.request
from typing import Any, Callable

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models import TradeExecution
from app.repositories.trading_account_repo import get_trading_account
from app.services.binance_usdm_client import BinanceUSDMClient, BinanceUSDMError
from app.services.crypto_service import decrypt_secret
from app.settings import settings


def _http_get_json(url: str, *, timeout: float) -> Any:
    req = urllib.request.Request(url=url, method="GET")
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8")
    return json.loads(raw)


def _binance_ticker_price(*, symbol: str, timeout: float = 5.0) -> float | None:
    q = urllib.parse.urlencode({"symbol": symbol.upper()})
    url = f"{settings.binance_usdm_base_url.rstrip('/')}/fapi/v1/ticker/price?{q}"
    try:
        obj = _http_get_json(url, timeout=timeout)
    except Exception:
        return None
    if not isinstance(obj, dict):
        return None
    try:
        return float(obj.get("price") or 0.0)
    except Exception:
        return None


def _clamp(v: float, lo: float, hi: float) -> float:
    return lo if v < lo else hi if v > hi else v


class StrategyRunnerService:
    def __init__(self, *, session_factory: Callable[[], Session]):
        self._session_factory = session_factory
        self._task: asyncio.Task[None] | None = None
        self._running = False

        self.last_error: str | None = None
        self.last_poll_ts: float | None = None
        self.last_emit_ok_ts: float | None = None
        self.last_exec_ok_ts: float | None = None

    def is_running(self) -> bool:
        return self._running and self._task is not None and not self._task.done()

    async def start(self) -> None:
        if self.is_running():
            return
        self._running = True
        self._task = asyncio.create_task(self._run_loop())

    async def stop(self) -> None:
        self._running = False
        t = self._task
        if t is not None:
            try:
                await asyncio.wait_for(t, timeout=5.0)
            except Exception:
                pass
        self._task = None

    def status(self) -> dict[str, Any]:
        return {
            "running": self.is_running(),
            "dry_run": bool(settings.strategy_dry_run),
            "mode": str(settings.strategy_mode),
            "symbols": [s for s in (x.strip() for x in settings.strategy_symbols.split(",")) if s],
            "webman_base_url": str(settings.strategy_webman_base_url),
            "last_poll_ts": self.last_poll_ts,
            "last_emit_ok_ts": self.last_emit_ok_ts,
            "last_exec_ok_ts": self.last_exec_ok_ts,
            "last_error": self.last_error,
        }

    async def _run_loop(self) -> None:
        while self._running:
            self.last_poll_ts = time.time()
            try:
                await self._tick()
            except Exception as e:
                self.last_error = f"{type(e).__name__}: {e}"
            await asyncio.sleep(max(0.2, float(settings.strategy_poll_interval_sec)))

    async def _tick(self) -> None:
        symbols = [s for s in (x.strip() for x in settings.strategy_symbols.split(",")) if s]
        for symbol in symbols:
            plans = await self._fetch_plans(symbol=symbol)
            if not plans:
                continue
            self.last_emit_ok_ts = time.time()
            for plan in plans:
                try:
                    await self._maybe_execute_plan(plan)
                except Exception as e:
                    self.last_error = f"{type(e).__name__}: {e}"

    async def _fetch_plans(self, *, symbol: str) -> list[dict[str, Any]]:
        base = settings.strategy_webman_base_url.rstrip("/")
        params = {
            "symbol": symbol.upper(),
            "mode": settings.strategy_mode,
            "refresh": 1,
            "enable_4h_preplan": 1,
        }
        q = urllib.parse.urlencode(params)
        url = f"{base}/api/strategy/emit?{q}"
        try:
            obj = await asyncio.to_thread(_http_get_json, url, timeout=5.0)
        except Exception:
            return []
        if not isinstance(obj, dict):
            return []
        if not obj.get("ok"):
            return []
        plans = obj.get("plans") or []
        if not isinstance(plans, list):
            return []
        out: list[dict[str, Any]] = []
        for p in plans:
            if isinstance(p, dict):
                out.append(p)
        return out

    def _plan_already_executed(self, *, db: Session, plan_id: str) -> bool:
        stmt = select(TradeExecution.id).where(TradeExecution.request_id == plan_id).limit(1)
        return db.execute(stmt).scalar_one_or_none() is not None

    def _has_open_position(self, *, client: BinanceUSDMClient, symbol: str) -> bool:
        try:
            positions = client.positions()
        except BinanceUSDMError:
            return False
        p = next((x for x in positions if str(x.get("symbol") or "").upper() == symbol.upper()), None)
        if p is None:
            return False
        try:
            amt = float(p.get("positionAmt") or 0.0)
        except Exception:
            amt = 0.0
        return abs(amt) > 0.0

    async def _maybe_execute_plan(self, plan: dict[str, Any]) -> None:
        plan_id = str(plan.get("id") or "").strip()
        symbol = str(plan.get("symbol") or "").upper().strip()
        side = str(plan.get("side") or "").upper().strip()
        if not plan_id or not symbol or side not in {"LONG", "SHORT"}:
            return

        now = int(time.time())
        try:
            expires_ts = int(plan.get("expires_ts") or 0)
        except Exception:
            expires_ts = 0
        if expires_ts > 0 and now >= expires_ts:
            return

        with self._session_factory() as db:
            if self._plan_already_executed(db=db, plan_id=plan_id):
                return
            acc = get_trading_account(db, owner_user_id=1, account_id=int(settings.strategy_account_id))
            if acc is None or not acc.is_active:
                return
            client = BinanceUSDMClient(api_key=acc.api_key, api_secret=decrypt_secret(acc.encrypted_secret))
            if self._has_open_position(client=client, symbol=symbol):
                return

        price = await asyncio.to_thread(_binance_ticker_price, symbol=symbol, timeout=5.0)
        if price is None or price <= 0:
            return

        ez = plan.get("entry_zone") or []
        try:
            ez_lo = float(ez[0])
            ez_hi = float(ez[1])
        except Exception:
            return
        if ez_lo > ez_hi:
            ez_lo, ez_hi = ez_hi, ez_lo
        if not (ez_lo <= price <= ez_hi):
            return

        try:
            entry = float(plan.get("entry_price") or 0.0)
            sl = float(plan.get("sl") or 0.0)
            tp = float(plan.get("tp") or 0.0)
            risk_mul = float(plan.get("risk_mul") or 1.0)
        except Exception:
            return

        dist = abs(entry - sl)
        if dist <= 0:
            return

        risk_usdt = float(settings.strategy_risk_usdt) * risk_mul
        if risk_usdt <= 0:
            return

        qty = risk_usdt / dist
        max_notional = float(settings.strategy_max_notional_usdt)
        if max_notional > 0 and qty * price > max_notional:
            qty = max_notional / price
        qty = max(0.0, round(qty, int(settings.strategy_qty_precision)))
        if qty <= 0:
            return

        if settings.strategy_dry_run:
            self.last_exec_ok_ts = time.time()
            return

        with self._session_factory() as db:
            if self._plan_already_executed(db=db, plan_id=plan_id):
                return
            acc = get_trading_account(db, owner_user_id=1, account_id=int(settings.strategy_account_id))
            if acc is None or not acc.is_active:
                return
            client = BinanceUSDMClient(api_key=acc.api_key, api_secret=decrypt_secret(acc.encrypted_secret))
            if self._has_open_position(client=client, symbol=symbol):
                return

            leverage = int(settings.strategy_leverage)
            if leverage > 0:
                try:
                    client.change_leverage(symbol=symbol, leverage=leverage)
                except BinanceUSDMError as e:
                    self.last_error = f"set_leverage_failed: {e.body}"
                    return

            order_side = "BUY" if side == "LONG" else "SELL"
            try:
                entry_res = client.order_create(symbol=symbol, side=order_side, type="MARKET", quantity=qty)
            except BinanceUSDMError as e:
                self.last_error = f"entry_failed: {e.body}"
                return

            tp_res = None
            sl_res = None
            close_side = "SELL" if order_side == "BUY" else "BUY"
            if tp and tp > 0:
                try:
                    tp_res = client.order_create(
                        symbol=symbol,
                        side=close_side,
                        type="TAKE_PROFIT_MARKET",
                        stop_price=tp,
                        reduce_only=True,
                        close_position=True,
                        working_type="CONTRACT_PRICE",
                    )
                except BinanceUSDMError as e:
                    tp_res = {"error": e.body}
            if sl and sl > 0:
                try:
                    sl_res = client.order_create(
                        symbol=symbol,
                        side=close_side,
                        type="STOP_MARKET",
                        stop_price=sl,
                        reduce_only=True,
                        close_position=True,
                        working_type="CONTRACT_PRICE",
                    )
                except BinanceUSDMError as e:
                    sl_res = {"error": e.body}

            exec_row = TradeExecution(
                account_id=acc.id,
                symbol=symbol,
                side=order_side,
                qty=qty,
                leverage=leverage or 1,
                entry_order_id=str(entry_res.get("orderId")),
                tp_order_id=str(tp_res.get("orderId")) if isinstance(tp_res, dict) else None,
                sl_order_id=str(sl_res.get("orderId")) if isinstance(sl_res, dict) else None,
                entry_price=float(entry_res.get("avgPrice") or 0) if isinstance(entry_res, dict) else None,
                take_profit_price=tp,
                stop_loss_price=sl,
                request_id=plan_id,
                response_payload={"entry": entry_res, "tp": tp_res, "sl": sl_res, "plan": plan},
            )
            db.add(exec_row)
            db.commit()

        self.last_exec_ok_ts = time.time()

