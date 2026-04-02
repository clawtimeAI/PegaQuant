"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { API_BASE, DEFAULT_SYMBOLS, INTERVALS } from "../_lib/config";
import { apiGet } from "../_lib/api";
import { useTradeMarket } from "../_hooks/useTradeMarket";
import type { Interval } from "../_lib/types";
import type { PositionRisk, TradingAccount } from "../_lib/types";

export function TradeWorkspace() {
  const [symbol, setSymbol] = useState("BTCUSDT");
  const [interval, setInterval] = useState<Interval>("1m");
  const chartContainerRef = useRef<HTMLDivElement | null>(null);

  const { lastPrice, tickPrice, tickTimeMs, wsBackend, wsBinanceK, wsBinanceT, binanceError } = useTradeMarket(
    symbol,
    interval,
    chartContainerRef,
  );

  const [accounts, setAccounts] = useState<TradingAccount[]>([]);
  const [accountId, setAccountId] = useState<number | null>(null);
  const [qty, setQty] = useState(0.001);
  const [tp, setTp] = useState<number | "">("");
  const [sl, setSl] = useState<number | "">("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [position, setPosition] = useState<PositionRisk | null>(null);
  const [posBusy, setPosBusy] = useState(false);
  const [posError, setPosError] = useState("");

  const loadAccounts = useCallback(async () => {
    try {
      const rows = await apiGet<TradingAccount[]>("/binance/accounts?include_inactive=false");
      setAccounts(rows);
      setAccountId((prev) => (prev && rows.some((r) => r.id === prev) ? prev : rows[0]?.id ?? null));
    } catch {
      setAccounts([]);
      setAccountId(null);
    }
  }, []);

  useEffect(() => {
    void loadAccounts();
  }, [loadAccounts]);

  const loadPosition = useCallback(async () => {
    if (!accountId) {
      setPosition(null);
      return;
    }
    try {
      setPosError("");
      const rows = await apiGet<PositionRisk[]>(`/binance/accounts/${accountId}/positions`);
      setPosition(rows.find((r) => r.symbol === symbol) ?? null);
    } catch (e) {
      setPosition(null);
      setPosError(String(e));
    }
  }, [accountId, symbol]);

  useEffect(() => {
    void loadPosition();
    const t = window.setInterval(() => {
      void loadPosition();
    }, 5000);
    return () => window.clearInterval(t);
  }, [loadPosition]);

  async function submitOrder(side: "BUY" | "SELL") {
    if (!accountId) return;
    setBusy(true);
    setError("");
    try {
      const res = await fetch(`${API_BASE}/trade/order/market`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          account_id: accountId,
          symbol,
          side,
          qty,
          take_profit_price: tp === "" ? null : tp,
          stop_loss_price: sl === "" ? null : sl,
        }),
      });
      if (!res.ok) throw new Error(await res.text());
    } catch (e) {
      setError(String(e));
    } finally {
      setBusy(false);
    }
  }

  async function closePosition() {
    if (!accountId) return;
    setPosBusy(true);
    setPosError("");
    try {
      const res = await fetch(`${API_BASE}/trade/position/close`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ account_id: accountId, symbol, pct: 1 }),
      });
      if (!res.ok) throw new Error(await res.text());
      await loadPosition();
    } catch (e) {
      setPosError(String(e));
    } finally {
      setPosBusy(false);
    }
  }

  const wsLabel = (s: typeof wsBackend) =>
    s === "open" ? "已连接" : s === "connecting" ? "连接中" : "已断开";

  return (
    <div className="space-y-5">
      <div className="rounded-xl border border-white/10 bg-white/5 p-4">
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2">
            <div className="text-sm font-semibold text-white">{symbol}</div>
            <div className="text-sm text-white/70">{interval}</div>
            <div className="text-sm text-white/70">·</div>
            <div className="text-sm text-white/80">{lastPrice == null ? "--" : lastPrice.toFixed(2)}</div>
          </div>

          <div className="mx-2 h-4 w-px bg-white/10" />

          <select
            className="rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
            value={symbol}
            onChange={(e) => setSymbol(e.target.value)}
          >
            {DEFAULT_SYMBOLS.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>

          <div className="flex items-center overflow-hidden rounded-lg border border-white/10 bg-[#0b0e11]">
            {INTERVALS.map((itv) => (
              <button
                key={itv}
                type="button"
                className={`px-3 py-2 text-sm ${interval === itv ? "bg-white/10 text-white" : "text-white/70 hover:bg-white/5 hover:text-white"}`}
                onClick={() => setInterval(itv)}
              >
                {itv}
              </button>
            ))}
          </div>

          <div className="mx-2 h-4 w-px bg-white/10" />

          <div className="flex items-center gap-2 text-sm text-white/70">
            <span>WS</span>
            <span className={wsBackend === "open" ? "text-green-400" : wsBackend === "connecting" ? "text-amber-300" : "text-red-400"}>
              {wsLabel(wsBackend)}
            </span>
          </div>
          <div className="flex items-center gap-2 text-sm text-white/70">
            <span>BINANCE K</span>
            <span className={wsBinanceK === "open" ? "text-green-400" : wsBinanceK === "connecting" ? "text-amber-300" : "text-red-400"}>
              {wsLabel(wsBinanceK)}
            </span>
          </div>
          <div className="flex items-center gap-2 text-sm text-white/70">
            <span>BINANCE T</span>
            <span className={wsBinanceT === "open" ? "text-green-400" : wsBinanceT === "connecting" ? "text-amber-300" : "text-red-400"}>
              {wsLabel(wsBinanceT)}
            </span>
          </div>

          <div className="flex items-center gap-2 text-sm text-white/70">
            <span>最新价</span>
            <span className="text-white/90">
              {tickPrice == null ? "--" : tickPrice.toFixed(2)}
              {tickTimeMs == null ? "" : ` · ${new Date(tickTimeMs).toLocaleTimeString()}`}
            </span>
          </div>

          {binanceError ? <div className="text-xs text-red-300">{binanceError}</div> : null}
        </div>
      </div>

      <div className="rounded-xl border border-white/10 bg-white/5 p-4">
        <div className="flex flex-wrap items-center gap-3">
          <select
            className="rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
            value={accountId ?? ""}
            onChange={(e) => setAccountId(parseInt(e.target.value, 10))}
          >
            {accounts.length === 0 ? <option value="">无可用账户</option> : null}
            {accounts.map((a) => (
              <option key={a.id} value={a.id}>
                {a.account_name} ({a.account_group})
              </option>
            ))}
          </select>
          <span className="text-sm text-white/80">数量</span>
          <input
            className="w-28 rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
            type="number"
            step="0.001"
            value={qty}
            onChange={(e) => setQty(parseFloat(e.target.value))}
          />
          <span className="text-sm text-white/80">止盈</span>
          <input
            className="w-28 rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
            type="number"
            step="0.1"
            value={tp}
            onChange={(e) => setTp(e.target.value === "" ? "" : parseFloat(e.target.value))}
            placeholder="可选"
          />
          <span className="text-sm text-white/80">止损</span>
          <input
            className="w-28 rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
            type="number"
            step="0.1"
            value={sl}
            onChange={(e) => setSl(e.target.value === "" ? "" : parseFloat(e.target.value))}
            placeholder="可选"
          />
          <button
            type="button"
            className="rounded-lg border border-white/10 bg-green-600/80 px-3 py-2 text-sm text-white hover:bg-green-600 disabled:opacity-50"
            onClick={() => void submitOrder("BUY")}
            disabled={busy || !accountId || qty <= 0}
          >
            开多
          </button>
          <button
            type="button"
            className="rounded-lg border border-white/10 bg-red-600/80 px-3 py-2 text-sm text-white hover:bg-red-600 disabled:opacity-50"
            onClick={() => void submitOrder("SELL")}
            disabled={busy || !accountId || qty <= 0}
          >
            开空
          </button>
        </div>
      </div>

      <div className="relative rounded-xl border border-white/10 bg-white/5 p-1">
        <div ref={chartContainerRef} />
        <div className="pointer-events-none absolute left-5 top-4 text-5xl font-semibold text-white/5">
          {symbol} {interval}
        </div>
      </div>

      <div className="rounded-xl border border-white/10 bg-white/5 p-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="text-sm font-semibold text-white">当前持仓（{symbol}）</div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white hover:bg-white/10 disabled:opacity-50"
              onClick={() => void loadPosition()}
              disabled={!accountId || posBusy}
            >
              刷新
            </button>
            <button
              type="button"
              className="rounded-lg border border-white/10 bg-amber-600/80 px-3 py-2 text-sm text-white hover:bg-amber-600 disabled:opacity-50"
              onClick={() => void closePosition()}
              disabled={
                !accountId || posBusy || !position || Math.abs(parseFloat(position.positionAmt || "0")) <= 0
              }
            >
              一键平仓
            </button>
          </div>
        </div>

        <div className="mt-3 grid grid-cols-2 gap-3 text-sm text-white/80 md:grid-cols-4">
          <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
            <div className="text-white/50">方向/数量</div>
            <div className="mt-1 text-white">
              {position
                ? `${parseFloat(position.positionAmt) > 0 ? "LONG" : parseFloat(position.positionAmt) < 0 ? "SHORT" : "FLAT"} · ${position.positionAmt}`
                : "--"}
            </div>
          </div>
          <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
            <div className="text-white/50">开仓均价</div>
            <div className="mt-1 text-white">{position?.entryPrice ?? "--"}</div>
          </div>
          <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
            <div className="text-white/50">标记价格</div>
            <div className="mt-1 text-white">{position?.markPrice ?? "--"}</div>
          </div>
          <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
            <div className="text-white/50">未实现盈亏</div>
            <div
              className={
                position && parseFloat(position.unrealizedProfit || "0") >= 0 ? "mt-1 text-green-400" : "mt-1 text-red-400"
              }
            >
              {position?.unrealizedProfit ?? "--"}
            </div>
          </div>
        </div>

        {posError ? <div className="mt-3 text-sm text-white">{posError}</div> : null}
      </div>

      {error ? <div className="text-sm text-white">{error}</div> : null}
    </div>
  );
}
