"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { formatUsdtAmount, parseUsdtFromBinanceAccount, type UsdtBalanceSummary } from "../../_lib/binanceUsdt";
import { API_BASE, DEFAULT_SYMBOLS, INTERVALS } from "../_lib/config";
import { apiGet } from "../_lib/api";
import { useTradeMarket } from "../_hooks/useTradeMarket";
import type { Interval } from "../_lib/types";
import type { PositionRisk, TradingAccount } from "../_lib/types";

function wsLabel(s: "connecting" | "open" | "closed") {
  if (s === "open") return "已连接";
  if (s === "connecting") return "连接中";
  return "已断开";
}

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
  const [leverage, setLeverage] = useState(20);
  const [applyLeverageOnOrder, setApplyLeverageOnOrder] = useState(false);
  const [qty, setQty] = useState(0.001);
  const [tp, setTp] = useState<number | "">("");
  const [sl, setSl] = useState<number | "">("");
  const [quickPct, setQuickPct] = useState(0.5);
  const [orderBusy, setOrderBusy] = useState(false);
  const [orderError, setOrderError] = useState("");
  const [position, setPosition] = useState<PositionRisk | null>(null);
  const [usdtSummary, setUsdtSummary] = useState<UsdtBalanceSummary | null>(null);
  const [balanceError, setBalanceError] = useState("");
  const [posBusy, setPosBusy] = useState(false);
  const [posError, setPosError] = useState("");
  const [leverageBusy, setLeverageBusy] = useState(false);
  const [leverageMsg, setLeverageMsg] = useState("");
  const [closePct, setClosePct] = useState(1);

  const refPrice = useMemo(() => {
    const p = tickPrice ?? lastPrice;
    return p != null && Number.isFinite(p) ? p : null;
  }, [tickPrice, lastPrice]);

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

  useEffect(() => {
    setUsdtSummary(null);
    setBalanceError("");
  }, [accountId]);

  const loadTradingSnapshot = useCallback(async () => {
    if (!accountId) {
      setPosition(null);
      setUsdtSummary(null);
      setBalanceError("");
      setPosError("");
      return;
    }
    setPosError("");
    setBalanceError("");
    const posP = apiGet<PositionRisk[]>(`/binance/accounts/${accountId}/positions`)
      .then((rows) => {
        const p = rows.find((r) => r.symbol === symbol) ?? null;
        setPosition(p);
        if (p?.leverage) {
          const lv = parseInt(String(p.leverage), 10);
          if (Number.isFinite(lv) && lv > 0) setLeverage(lv);
        }
      })
      .catch((e) => {
        setPosition(null);
        setPosError(`持仓: ${String(e)}`);
      });
    const accP = apiGet<unknown>(`/binance/accounts/${accountId}/account`)
      .then((raw) => {
        setUsdtSummary(parseUsdtFromBinanceAccount(raw));
        setBalanceError("");
      })
      .catch(() => {
        setUsdtSummary(null);
        setBalanceError("无法获取账户资金（/account）");
      });
    await Promise.all([posP, accP]);
  }, [accountId, symbol]);

  useEffect(() => {
    void loadTradingSnapshot();
    const t = window.setInterval(() => {
      void loadTradingSnapshot();
    }, 5000);
    return () => window.clearInterval(t);
  }, [loadTradingSnapshot]);

  const positionAmt = position ? parseFloat(position.positionAmt || "0") : 0;
  const hasPosition = Math.abs(positionAmt) > 0;

  async function submitSetLeverage() {
    if (!accountId || !Number.isFinite(leverage) || leverage < 1) return;
    setLeverageBusy(true);
    setLeverageMsg("");
    try {
      const res = await fetch(`${API_BASE}/trade/leverage`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ account_id: accountId, symbol, leverage: Math.round(leverage) }),
      });
      if (!res.ok) throw new Error(await res.text());
      setLeverageMsg("已提交交易所，稍后自动刷新持仓");
      await loadTradingSnapshot();
    } catch (e) {
      setLeverageMsg(String(e));
    } finally {
      setLeverageBusy(false);
    }
  }

  async function submitOrder(side: "BUY" | "SELL") {
    if (!accountId) return;
    setOrderBusy(true);
    setOrderError("");
    try {
      const body: Record<string, unknown> = {
        account_id: accountId,
        symbol,
        side,
        qty,
        take_profit_price: tp === "" ? null : tp,
        stop_loss_price: sl === "" ? null : sl,
      };
      if (applyLeverageOnOrder && leverage >= 1) body.leverage = Math.round(leverage);
      const res = await fetch(`${API_BASE}/trade/order/market`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(body),
      });
      if (!res.ok) throw new Error(await res.text());
      await loadTradingSnapshot();
    } catch (e) {
      setOrderError(String(e));
    } finally {
      setOrderBusy(false);
    }
  }

  async function closePosition(pct: number) {
    if (!accountId) return;
    setPosBusy(true);
    setPosError("");
    try {
      const res = await fetch(`${API_BASE}/trade/position/close`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ account_id: accountId, symbol, pct }),
      });
      if (!res.ok) throw new Error(await res.text());
      await loadTradingSnapshot();
    } catch (e) {
      setPosError(String(e));
    } finally {
      setPosBusy(false);
    }
  }

  function fillTpSlForLong() {
    const r = refPrice ?? (position?.markPrice ? parseFloat(position.markPrice) : null);
    if (r == null || !Number.isFinite(r)) return;
    const k = quickPct / 100;
    setTp(Number((r * (1 + k)).toFixed(8)));
    setSl(Number((r * (1 - k)).toFixed(8)));
  }

  function fillTpSlForShort() {
    const r = refPrice ?? (position?.markPrice ? parseFloat(position.markPrice) : null);
    if (r == null || !Number.isFinite(r)) return;
    const k = quickPct / 100;
    setTp(Number((r * (1 - k)).toFixed(8)));
    setSl(Number((r * (1 + k)).toFixed(8)));
  }

  return (
    <div className="flex min-h-0 flex-col gap-3 text-[#eaecef]">
      {/* 顶栏：行情与控制 */}
      <div className="shrink-0 rounded-xl border border-white/10 bg-white/5 px-3 py-2 sm:px-4 sm:py-3">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex min-w-0 flex-wrap items-center gap-2 sm:gap-3">
            <div className="flex items-baseline gap-2">
              <span className="text-lg font-semibold text-white">{symbol}</span>
              <span className="text-xs text-white/50">{interval}</span>
            </div>
            <div className="h-4 w-px bg-white/10 max-sm:hidden" />
            <div className="font-mono text-base font-medium text-white">
              {lastPrice == null ? "—" : lastPrice.toFixed(2)}
              <span className="ml-2 text-xs font-normal text-white/50">图表收</span>
            </div>
            <div className="font-mono text-sm text-emerald-200/90">
              {tickPrice == null ? "—" : tickPrice.toFixed(2)}
              <span className="ml-1 text-xs text-white/45">
                {tickTimeMs == null ? "" : `· ${new Date(tickTimeMs).toLocaleTimeString("zh-CN")}`}
              </span>
            </div>

            <select
              className="rounded-lg border border-white/10 bg-[#0b0e11] px-2 py-1.5 text-sm text-white"
              value={symbol}
              onChange={(e) => setSymbol(e.target.value)}
            >
              {DEFAULT_SYMBOLS.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>

            <div className="flex overflow-hidden rounded-lg border border-white/10 bg-[#0b0e11]">
              {INTERVALS.map((itv) => (
                <button
                  key={itv}
                  type="button"
                  className={`px-2.5 py-1.5 text-xs sm:text-sm ${
                    interval === itv ? "bg-white/10 text-white" : "text-white/65 hover:bg-white/5 hover:text-white"
                  }`}
                  onClick={() => setInterval(itv)}
                >
                  {itv}
                </button>
              ))}
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-white/55 sm:text-xs">
            <span>
              后端 WS:{" "}
              <span className={wsBackend === "open" ? "text-emerald-400" : wsBackend === "connecting" ? "text-amber-300" : "text-red-400"}>
                {wsLabel(wsBackend)}
              </span>
            </span>
            <span>
              K线:{" "}
              <span className={wsBinanceK === "open" ? "text-emerald-400" : wsBinanceK === "connecting" ? "text-amber-300" : "text-red-400"}>
                {wsLabel(wsBinanceK)}
              </span>
            </span>
            <span>
              成交:{" "}
              <span className={wsBinanceT === "open" ? "text-emerald-400" : wsBinanceT === "connecting" ? "text-amber-300" : "text-red-400"}>
                {wsLabel(wsBinanceT)}
              </span>
            </span>
            {binanceError ? <span className="text-red-300">{binanceError}</span> : null}
          </div>
        </div>
      </div>

      <div className="flex min-h-[min(720px,calc(100vh-10rem))] flex-1 flex-col gap-3 lg:flex-row lg:items-stretch">
        {/* 图表区 */}
        <div className="relative min-h-[420px] min-w-0 flex-1 overflow-hidden rounded-xl border border-white/10 bg-white/5 p-1 lg:min-h-[560px]">
          <div ref={chartContainerRef} className="min-h-[400px] w-full" />
          <div className="pointer-events-none absolute left-4 top-3 text-4xl font-semibold text-white/[0.04] lg:text-5xl">
            {symbol} {interval}
          </div>
        </div>

        {/* 右侧：手动交易 */}
        <aside className="flex w-full shrink-0 flex-col gap-3 lg:w-[380px] xl:w-[400px]">
          <div className="rounded-xl border border-white/10 bg-white/5 p-4">
            <h2 className="text-sm font-semibold text-white">下单</h2>
            <p className="mt-1 text-[11px] leading-relaxed text-white/50">市价开仓；止盈/止损以条件市价单挂出（与后端一致）。请在交易所确认品种最大杠杆。</p>

            <div className="mt-4 space-y-3">
              <label className="block">
                <span className="text-[11px] text-white/50">交易账户</span>
                <select
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                  value={accountId ?? ""}
                  onChange={(e) => setAccountId(e.target.value ? parseInt(e.target.value, 10) : null)}
                >
                  {accounts.length === 0 ? <option value="">无可用账户</option> : null}
                  {accounts.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.account_name}（{a.account_group}）
                    </option>
                  ))}
                </select>
              </label>

              {accountId ? (
                <div className="rounded-lg border border-emerald-500/25 bg-emerald-500/[0.07] p-3">
                  <div className="text-[11px] font-medium text-emerald-100/90">合约账户 USDT（约每 5s 刷新）</div>
                  <div className="mt-2 space-y-1.5 text-xs">
                    <div className="flex justify-between gap-2">
                      <span className="text-white/50">可用余额（可开新仓）</span>
                      <span className="font-mono text-emerald-50">
                        {formatUsdtAmount(usdtSummary?.availableBalance)} USDT
                      </span>
                    </div>
                    {usdtSummary?.walletBalance ? (
                      <div className="flex justify-between gap-2">
                        <span className="text-white/50">钱包余额（USDT 资产行）</span>
                        <span className="font-mono text-white/85">{formatUsdtAmount(usdtSummary.walletBalance)}</span>
                      </div>
                    ) : null}
                    {usdtSummary?.totalWalletBalance ? (
                      <div className="flex justify-between gap-2">
                        <span className="text-white/50">账户钱包余额合计</span>
                        <span className="font-mono text-white/80">{formatUsdtAmount(usdtSummary.totalWalletBalance)}</span>
                      </div>
                    ) : null}
                    {usdtSummary?.totalUnrealizedProfit != null ? (
                      <div className="flex justify-between gap-2">
                        <span className="text-white/50">未实现盈亏（账户）</span>
                        <span
                          className={`font-mono ${
                            parseFloat(usdtSummary.totalUnrealizedProfit) >= 0 ? "text-emerald-300" : "text-rose-300"
                          }`}
                        >
                          {formatUsdtAmount(usdtSummary.totalUnrealizedProfit)}
                        </span>
                      </div>
                    ) : null}
                    {usdtSummary?.maxWithdrawAmount ? (
                      <div className="flex justify-between gap-2">
                        <span className="text-white/50">最大可转出</span>
                        <span className="font-mono text-white/70">{formatUsdtAmount(usdtSummary.maxWithdrawAmount)}</span>
                      </div>
                    ) : null}
                  </div>
                  {balanceError ? (
                    <div className="mt-2 text-[11px] text-rose-200/90">{balanceError}</div>
                  ) : !usdtSummary ? (
                    <div className="mt-2 text-[11px] text-white/45">正在拉取资金…</div>
                  ) : null}
                </div>
              ) : null}

              <div className="rounded-lg border border-white/10 bg-[#0b0e11]/80 p-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-xs text-white/60">杠杆（全仓标的）</span>
                  {position?.leverage ? (
                    <span className="text-xs text-amber-200/90">当前 {position.leverage}x</span>
                  ) : (
                    <span className="text-xs text-white/40">当前 —</span>
                  )}
                </div>
                <div className="mt-2 flex gap-2">
                  <input
                    type="number"
                    min={1}
                    max={125}
                    className="min-w-0 flex-1 rounded-lg border border-white/10 bg-[#0b0e11] px-2 py-2 font-mono text-sm text-white"
                    value={leverage}
                    onChange={(e) => setLeverage(parseInt(e.target.value, 10) || 1)}
                  />
                  <button
                    type="button"
                    className="shrink-0 rounded-lg border border-amber-500/35 bg-amber-500/15 px-3 py-2 text-xs font-medium text-amber-100 hover:bg-amber-500/25 disabled:opacity-50"
                    disabled={!accountId || leverageBusy}
                    onClick={() => void submitSetLeverage()}
                  >
                    {leverageBusy ? "…" : "应用"}
                  </button>
                </div>
                {leverageMsg ? <div className="mt-2 text-[11px] text-white/70">{leverageMsg}</div> : null}
                <label className="mt-2 flex items-center gap-2 text-[11px] text-white/55">
                  <input
                    type="checkbox"
                    checked={applyLeverageOnOrder}
                    onChange={(e) => setApplyLeverageOnOrder(e.target.checked)}
                  />
                  开仓前先调杠杆（与上方倍数一致）
                </label>
              </div>

              <label className="block">
                <span className="text-[11px] text-white/50">数量（合约张数 / 币数量，与交易所最小步长一致）</span>
                <input
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 font-mono text-sm text-white"
                  type="number"
                  step="0.001"
                  min={0}
                  value={qty}
                  onChange={(e) => setQty(parseFloat(e.target.value) || 0)}
                />
              </label>

              <div className="rounded-lg border border-white/10 bg-[#0b0e11]/80 p-3">
                <div className="flex flex-wrap items-end justify-between gap-2">
                  <div>
                    <span className="text-[11px] text-white/50">快速填止盈止损（按参考价 ± 百分比）</span>
                    <div className="mt-1 flex items-center gap-2">
                      <input
                        type="number"
                        step={0.05}
                        min={0.01}
                        className="w-20 rounded border border-white/10 bg-[#0b0e11] px-2 py-1 font-mono text-xs text-white"
                        value={quickPct}
                        onChange={(e) => setQuickPct(parseFloat(e.target.value) || 0.5)}
                      />
                      <span className="text-[11px] text-white/45">%</span>
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-1">
                    <button
                      type="button"
                      className="rounded border border-emerald-500/30 bg-emerald-500/10 px-2 py-1 text-[11px] text-emerald-100 hover:bg-emerald-500/20"
                      onClick={fillTpSlForLong}
                    >
                      多向参考
                    </button>
                    <button
                      type="button"
                      className="rounded border border-rose-500/30 bg-rose-500/10 px-2 py-1 text-[11px] text-rose-100 hover:bg-rose-500/20"
                      onClick={fillTpSlForShort}
                    >
                      空向参考
                    </button>
                  </div>
                </div>
                <p className="mt-2 text-[10px] text-white/40">参考价优先用实时成交，否则用标记价。仅辅助输入，请核对后再下单。</p>
              </div>

              <div className="grid grid-cols-2 gap-2">
                <label>
                  <span className="text-[11px] text-white/50">止盈价</span>
                  <input
                    className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-2 py-2 font-mono text-sm text-white"
                    type="number"
                    step="0.01"
                    value={tp}
                    onChange={(e) => setTp(e.target.value === "" ? "" : parseFloat(e.target.value))}
                    placeholder="可选"
                  />
                </label>
                <label>
                  <span className="text-[11px] text-white/50">止损价</span>
                  <input
                    className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-2 py-2 font-mono text-sm text-white"
                    type="number"
                    step="0.01"
                    value={sl}
                    onChange={(e) => setSl(e.target.value === "" ? "" : parseFloat(e.target.value))}
                    placeholder="可选"
                  />
                </label>
              </div>

              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  className="rounded-lg bg-emerald-600 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-900/20 hover:bg-emerald-500 disabled:opacity-50"
                  onClick={() => void submitOrder("BUY")}
                  disabled={orderBusy || !accountId || qty <= 0}
                >
                  {orderBusy ? "提交中…" : "买入 / 开多"}
                </button>
                <button
                  type="button"
                  className="rounded-lg bg-rose-600 py-3 text-sm font-semibold text-white shadow-lg shadow-rose-900/20 hover:bg-rose-500 disabled:opacity-50"
                  onClick={() => void submitOrder("SELL")}
                  disabled={orderBusy || !accountId || qty <= 0}
                >
                  {orderBusy ? "提交中…" : "卖出 / 开空"}
                </button>
              </div>

              {orderError ? <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-100">{orderError}</div> : null}
            </div>
          </div>

          <div className="rounded-xl border border-white/10 bg-white/5 p-4">
            <div className="flex items-center justify-between gap-2">
              <h2 className="text-sm font-semibold text-white">持仓与平仓</h2>
              <button
                type="button"
                className="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[11px] text-white hover:bg-white/10 disabled:opacity-50"
                onClick={() => void loadTradingSnapshot()}
                disabled={!accountId || posBusy}
              >
                刷新
              </button>
            </div>
            <p className="mt-1 text-[11px] text-white/45">标的：{symbol}</p>

            <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
              <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-2.5">
                <div className="text-white/45">方向 / 数量</div>
                <div className="mt-1 font-mono text-white">
                  {hasPosition
                    ? `${positionAmt > 0 ? "多" : "空"} · ${position?.positionAmt ?? ""}`
                    : "无持仓"}
                </div>
              </div>
              <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-2.5">
                <div className="text-white/45">杠杆</div>
                <div className="mt-1 font-mono text-white">{position?.leverage ?? "—"}</div>
              </div>
              <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-2.5">
                <div className="text-white/45">开仓均价</div>
                <div className="mt-1 font-mono text-white">{position?.entryPrice ?? "—"}</div>
              </div>
              <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-2.5">
                <div className="text-white/45">标记价</div>
                <div className="mt-1 font-mono text-white">{position?.markPrice ?? "—"}</div>
              </div>
              <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-2.5">
                <div className="text-white/45">强平价</div>
                <div className="mt-1 font-mono text-amber-200/80">{position?.liquidationPrice ?? "—"}</div>
              </div>
              <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-2.5">
                <div className="text-white/45">保证金模式</div>
                <div className="mt-1 font-mono text-white">{position?.marginType ?? "—"}</div>
              </div>
            </div>

            <div className="mt-3 text-[11px] text-white/45">未实现盈亏</div>
            <div
              className={`font-mono text-lg ${
                position && parseFloat(position.unrealizedProfit || "0") >= 0 ? "text-emerald-400" : "text-rose-400"
              }`}
            >
              {position?.unrealizedProfit ?? "—"}
            </div>

            <div className="mt-4 border-t border-white/10 pt-3">
              <div className="text-[11px] text-white/50">按仓位比例市价平仓（reduceOnly）</div>
              <div className="mt-2 flex flex-wrap gap-1">
                {[
                  { p: 0.25, label: "25%" },
                  { p: 0.5, label: "50%" },
                  { p: 0.75, label: "75%" },
                  { p: 1, label: "全部" },
                ].map(({ p, label }) => (
                  <button
                    key={label}
                    type="button"
                    className={`rounded-lg border px-2.5 py-1.5 text-xs ${
                      closePct === p
                        ? "border-amber-500/50 bg-amber-500/15 text-amber-100"
                        : "border-white/10 bg-white/5 text-white/80 hover:bg-white/10"
                    }`}
                    onClick={() => setClosePct(p)}
                  >
                    {label}
                  </button>
                ))}
              </div>
              <button
                type="button"
                className="mt-3 w-full rounded-lg border border-amber-500/40 bg-amber-600/85 py-2.5 text-sm font-medium text-white hover:bg-amber-600 disabled:opacity-50"
                onClick={() => void closePosition(closePct)}
                disabled={!accountId || posBusy || !hasPosition}
              >
                {posBusy ? "平仓中…" : `市价平仓（${closePct >= 1 ? "100" : Math.round(closePct * 100)}%）`}
              </button>
            </div>

            {posError ? <div className="mt-3 text-xs text-rose-200">{posError}</div> : null}
          </div>
        </aside>
      </div>
    </div>
  );
}
