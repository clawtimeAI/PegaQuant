from __future__ import annotations

import hashlib
import hmac
import json
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Literal

from app.settings import settings


HttpMethod = Literal["GET", "POST", "DELETE"]


class BinanceUSDMError(RuntimeError):
    def __init__(self, *, status: int, body: str):
        super().__init__(f"Binance HTTP {status}: {body}")
        self.status = status
        self.body = body


class BinanceUSDMClient:
    def __init__(self, *, api_key: str, api_secret: str, base_url: str | None = None):
        self.api_key = api_key
        self.api_secret = api_secret
        self.base_url = (base_url or settings.binance_usdm_base_url).rstrip("/")
        self._time_offset_ms = 0
        self._time_offset_inited = False
        self._time_offset_updated_ms = 0

    def _sign(self, query: str) -> str:
        return hmac.new(self.api_secret.encode("utf-8"), query.encode("utf-8"), hashlib.sha256).hexdigest()

    def _sync_time_offset(self) -> None:
        try:
            t0 = time.time() * 1000.0
            req = urllib.request.Request(url=f"{self.base_url}/fapi/v1/time", method="GET")
            with urllib.request.urlopen(req, timeout=5) as resp:
                raw = resp.read().decode("utf-8")
            t1 = time.time() * 1000.0
            data = json.loads(raw)
        except Exception:
            return

        server_ms = int(data.get("serverTime") or 0)
        if server_ms <= 0:
            return

        local_mid_ms = (t0 + t1) / 2.0
        self._time_offset_ms = int(server_ms - local_mid_ms)
        self._time_offset_inited = True
        self._time_offset_updated_ms = int(time.time() * 1000)

    def _timestamp_ms(self) -> int:
        now = int(time.time() * 1000)
        if self._time_offset_inited and now - self._time_offset_updated_ms > 10 * 60 * 1000:
            self._sync_time_offset()
        if not self._time_offset_inited:
            self._sync_time_offset()
        return now + int(self._time_offset_ms) - 500

    def _is_timestamp_error(self, body: str) -> bool:
        try:
            obj = json.loads(body)
        except Exception:
            return False
        if not isinstance(obj, dict):
            return False
        return obj.get("code") == -1021

    def request(
        self,
        method: HttpMethod,
        path: str,
        *,
        params: dict[str, Any] | None = None,
        signed: bool = True,
    ) -> Any:
        def _do() -> Any:
            p = dict(params or {})
            headers = {"X-MBX-APIKEY": self.api_key}

            if signed:
                p.setdefault("recvWindow", settings.binance_recv_window)
                p["timestamp"] = self._timestamp_ms()

            query = urllib.parse.urlencode(p, doseq=True)
            if signed:
                sig = self._sign(query)
                query = f"{query}&signature={sig}" if query else f"signature={sig}"

            url = f"{self.base_url}{path}"
            if query:
                url = f"{url}?{query}"

            req = urllib.request.Request(url=url, method=method, headers=headers)
            try:
                with urllib.request.urlopen(req, timeout=15) as resp:
                    data = resp.read().decode("utf-8")
                    try:
                        return json.loads(data)
                    except Exception:
                        return data
            except urllib.error.HTTPError as e:
                body = e.read().decode("utf-8") if e.fp is not None else ""
                raise BinanceUSDMError(status=int(e.code), body=body) from None

        try:
            return _do()
        except BinanceUSDMError as e:
            if signed and self._is_timestamp_error(e.body):
                self._sync_time_offset()
                return _do()
            raise

    def ping(self) -> dict[str, Any]:
        return self.request("GET", "/fapi/v1/ping", signed=False)

    def time(self) -> dict[str, Any]:
        return self.request("GET", "/fapi/v1/time", signed=False)

    def account(self) -> dict[str, Any]:
        return self.request("GET", "/fapi/v2/account", signed=True)

    def positions(self) -> list[dict[str, Any]]:
        return self.request("GET", "/fapi/v2/positionRisk", signed=True)

    def klines(
        self,
        *,
        symbol: str,
        interval: str,
        limit: int = 1500,
        start_time_ms: int | None = None,
        end_time_ms: int | None = None,
    ) -> list[list[Any]]:
        params: dict[str, Any] = {"symbol": symbol, "interval": interval, "limit": limit}
        if start_time_ms is not None:
            params["startTime"] = start_time_ms
        if end_time_ms is not None:
            params["endTime"] = end_time_ms
        return self.request("GET", "/fapi/v1/klines", params=params, signed=False)

    def user_trades(
        self,
        *,
        symbol: str,
        start_time_ms: int | None = None,
        end_time_ms: int | None = None,
        limit: int = 200,
    ) -> list[dict[str, Any]]:
        params: dict[str, Any] = {"symbol": symbol, "limit": limit}
        if start_time_ms is not None:
            params["startTime"] = start_time_ms
        if end_time_ms is not None:
            params["endTime"] = end_time_ms
        return self.request("GET", "/fapi/v1/userTrades", params=params, signed=True)

    def income_history(
        self,
        *,
        income_type: str | None = None,
        start_time_ms: int | None = None,
        end_time_ms: int | None = None,
        limit: int = 1000,
    ) -> list[dict[str, Any]]:
        params: dict[str, Any] = {"limit": limit}
        if income_type:
            params["incomeType"] = income_type
        if start_time_ms is not None:
            params["startTime"] = start_time_ms
        if end_time_ms is not None:
            params["endTime"] = end_time_ms
        return self.request("GET", "/fapi/v1/income", params=params, signed=True)

    def all_orders(
        self,
        *,
        symbol: str,
        start_time_ms: int | None = None,
        end_time_ms: int | None = None,
        limit: int = 200,
    ) -> list[dict[str, Any]]:
        params: dict[str, Any] = {"symbol": symbol, "limit": limit}
        if start_time_ms is not None:
            params["startTime"] = start_time_ms
        if end_time_ms is not None:
            params["endTime"] = end_time_ms
        return self.request("GET", "/fapi/v1/allOrders", params=params, signed=True)

    def order_create(
        self,
        *,
        symbol: str,
        side: Literal["BUY", "SELL"],
        type: str,
        quantity: float | None = None,
        price: float | None = None,
        stop_price: float | None = None,
        reduce_only: bool | None = None,
        close_position: bool | None = None,
        time_in_force: Literal["GTC", "IOC", "FOK"] | None = None,
        working_type: Literal["CONTRACT_PRICE", "MARK_PRICE"] | None = None,
        position_side: Literal["BOTH", "LONG", "SHORT"] | None = None,
    ) -> dict[str, Any]:
        params: dict[str, Any] = {
            "symbol": symbol,
            "side": side,
            "type": type,
        }
        if quantity is not None:
            params["quantity"] = quantity
        if price is not None:
            params["price"] = price
        if stop_price is not None:
            params["stopPrice"] = stop_price
        if reduce_only is not None:
            params["reduceOnly"] = "true" if reduce_only else "false"
        if close_position is not None:
            params["closePosition"] = "true" if close_position else "false"
        if time_in_force is not None:
            params["timeInForce"] = time_in_force
        if working_type is not None:
            params["workingType"] = working_type
        if position_side is not None:
            params["positionSide"] = position_side
        return self.request("POST", "/fapi/v1/order", params=params, signed=True)

    def change_leverage(self, *, symbol: str, leverage: int) -> dict[str, Any]:
        return self.request(
            "POST",
            "/fapi/v1/leverage",
            params={"symbol": symbol, "leverage": int(leverage)},
            signed=True,
        )
