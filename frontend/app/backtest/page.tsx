"use client";

import { useEffect, useMemo, useState } from "react";

type Mode = "short" | "long" | "both";

type RangeResp = {
  ok: boolean;
  symbol: string;
  ranges: Record<
    string,
    { min_time: string; max_time: string; min_ms: number; max_ms: number }
  >;
};

type BacktestRunResp = {
  ok: boolean;
  run_id?: number | null;
  saved?: 0 | 1;
  saved_equity?: 0 | 1;
  warnings?: string[];
  params?: Record<string, unknown>;
  summary?: {
    trades: number;
    closed_trades: number;
    wins: number;
    losses: number;
    win_rate: number;
    pnl: number;
    max_drawdown: number;
    final_cash: number;
  };
};

type RunsResp = {
  ok: boolean;
  rows: Array<{
    id: number;
    symbol: string;
    mode: string;
    start_ms: number;
    end_ms: number;
    effective_start_ms: number;
    effective_end_ms: number;
    summary: Record<string, unknown> | null;
    created_at: string | null;
  }>;
};

type RunGetResp = {
  ok: boolean;
  row: {
    id: number;
    symbol: string;
    mode: string;
    start_ms: number;
    end_ms: number;
    effective_start_ms: number;
    effective_end_ms: number;
    params: Record<string, unknown> | null;
    summary: Record<string, unknown> | null;
    warnings: unknown;
    states: Record<string, unknown> | null;
    created_at: string | null;
  };
};

type TradesResp = {
  ok: boolean;
  rows: Array<{
    seq: number;
    type: string;
    time: string;
    side: string | null;
    reason: string | null;
    price: number | null;
    qty: number | null;
    fee: number | null;
    pnl: number | null;
    plan_id: number | null;
    plan: {
      plan_id: number;
      created_time: string;
      created_close_ts: number;
      active_from_ts: number;
      expires_ts: number;
      side: string;
      kind: string;
      tf_trigger: string;
      tf_plan: string;
      trigger_open_time: string;
      trigger_close_ts: number;
      plan: Record<string, unknown> | null;
    } | null;
  }>;
};

type PlansResp = {
  ok: boolean;
  rows: Array<{
    plan_id: number;
    created_time: string;
    created_close_ts: number;
    active_from_ts: number;
    expires_ts: number;
    side: string;
    kind: string;
    tf_trigger: string;
    tf_plan: string;
    trigger_open_time: string;
    trigger_close_ts: number;
    plan: Record<string, unknown> | null;
  }>;
};

type EquityResp = {
  ok: boolean;
  rows: Array<{ seq: number; time: string; equity: number }>;
};

const WEBMAN_BASE = process.env.NEXT_PUBLIC_WEBMAN_BASE ?? "http://localhost:8787";

async function webGet<T>(path: string): Promise<T> {
  const res = await fetch(`${WEBMAN_BASE}${path}`, { cache: "no-store" });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return (await res.json()) as T;
}

function toDatetimeLocalValue(ms: number) {
  const d = new Date(ms);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  const yyyy = d.getFullYear();
  const mm = pad(d.getMonth() + 1);
  const dd = pad(d.getDate());
  const hh = pad(d.getHours());
  const mi = pad(d.getMinutes());
  return `${yyyy}-${mm}-${dd}T${hh}:${mi}`;
}

function parseDatetimeLocalMs(v: string): number | null {
  if (!v) return null;
  const ms = Date.parse(v);
  return Number.isFinite(ms) ? ms : null;
}

function formatMs(ms?: number | null) {
  if (!ms) return "-";
  const d = new Date(ms);
  if (Number.isNaN(d.getTime())) return String(ms);
  return d.toLocaleString();
}

export default function BacktestPage() {
  const [symbol, setSymbol] = useState("BTCUSDT");
  const [mode, setMode] = useState<Mode>("short");

  const [range, setRange] = useState<RangeResp | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const [startLocal, setStartLocal] = useState<string>("");
  const [endLocal, setEndLocal] = useState<string>("");

  const [initialEquity, setInitialEquity] = useState("10000");
  const [riskPct, setRiskPct] = useState("0.005");
  const [feeBps, setFeeBps] = useState("4");
  const [slippageBps, setSlippageBps] = useState("1");
  const [confirmBars, setConfirmBars] = useState("30");
  const [enable4hPreplan, setEnable4hPreplan] = useState(true);
  const [saveEquity, setSaveEquity] = useState(false);

  const [lastRun, setLastRun] = useState<BacktestRunResp | null>(null);

  const [runs, setRuns] = useState<RunsResp | null>(null);
  const [runsOffset, setRunsOffset] = useState(0);
  const [selectedRunId, setSelectedRunId] = useState<number | null>(null);
  const [selectedRun, setSelectedRun] = useState<RunGetResp | null>(null);
  const [plans, setPlans] = useState<PlansResp | null>(null);
  const [selectedPlanId, setSelectedPlanId] = useState<number | null>(null);
  const [trades, setTrades] = useState<TradesResp | null>(null);
  const [equity, setEquity] = useState<EquityResp | null>(null);
  const [tradeSortBy, setTradeSortBy] = useState<"id" | "pnl">("pnl");
  const [tradeSortDir, setTradeSortDir] = useState<"asc" | "desc">("desc");

  async function loadRange() {
    if (!symbol.trim()) return;
    setBusy(true);
    setError("");
    try {
      const r = await webGet<RangeResp>(`/api/backtest/range?symbol=${encodeURIComponent(symbol.trim())}`);
      setRange(r.ok ? r : null);
      const r1m = r.ranges?.["1m"];
      if (r1m) {
        const end = r1m.max_ms;
        const start = Math.max(r1m.min_ms, end - 7 * 24 * 60 * 60 * 1000);
        setStartLocal(toDatetimeLocalValue(start));
        setEndLocal(toDatetimeLocalValue(end));
      }
    } catch (e) {
      setRange(null);
      setError(String(e));
    } finally {
      setBusy(false);
    }
  }

  async function loadRuns(nextOffset: number) {
    const qs = new URLSearchParams({
      limit: "50",
      offset: String(nextOffset),
    });
    if (symbol.trim()) qs.set("symbol", symbol.trim().toUpperCase());
    const r = await webGet<RunsResp>(`/api/backtest/runs?${qs.toString()}`);
    setRuns(r.ok ? r : null);
    setRunsOffset(nextOffset);
  }

  async function runBacktest() {
    const startMs = parseDatetimeLocalMs(startLocal);
    const endMs = parseDatetimeLocalMs(endLocal);
    if (!symbol.trim() || !startMs || !endMs || endMs <= startMs) {
      setError("start/end 时间无效");
      return;
    }
    setBusy(true);
    setError("");
    try {
      const qs = new URLSearchParams({
        symbol: symbol.trim().toUpperCase(),
        start_ms: String(startMs),
        end_ms: String(endMs),
        mode,
        initial_equity: initialEquity,
        risk_pct: riskPct,
        fee_bps: feeBps,
        slippage_bps: slippageBps,
        confirm_bars: confirmBars,
        enable_4h_preplan: enable4hPreplan ? "1" : "0",
        save: "1",
        save_equity: saveEquity ? "1" : "0",
      });
      const r = await webGet<BacktestRunResp>(`/api/backtest/run?${qs.toString()}`);
      setLastRun(r.ok ? r : null);
      await loadRuns(0);
      if (r.run_id) {
        setSelectedRunId(r.run_id);
      }
    } catch (e) {
      setError(String(e));
      setLastRun(null);
    } finally {
      setBusy(false);
    }
  }

  async function loadSelectedRun(id: number) {
    setBusy(true);
    setError("");
    try {
      const r = await webGet<RunGetResp>(`/api/backtest/run/get?id=${id}`);
      setSelectedRun(r.ok ? r : null);
      const p = await webGet<PlansResp>(`/api/backtest/run/plans?id=${id}&limit=2000&offset=0`);
      setPlans(p.ok ? p : null);
      setSelectedPlanId(null);
      const t = await webGet<TradesResp>(`/api/backtest/run/trades?id=${id}&limit=2000&offset=0&with_plan=1`);
      setTrades(t.ok ? t : null);
      if (saveEquity) {
        const eq = await webGet<EquityResp>(`/api/backtest/run/equity?id=${id}&limit=20000&offset=0`);
        setEquity(eq.ok ? eq : null);
      } else {
        setEquity(null);
      }
    } catch (e) {
      setSelectedRun(null);
      setPlans(null);
      setSelectedPlanId(null);
      setTrades(null);
      setEquity(null);
      setError(String(e));
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    loadRange();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    loadRuns(0).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (selectedRunId) {
      loadSelectedRun(selectedRunId).catch(() => {});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedRunId]);

  const selectedPlan = useMemo(() => {
    if (!selectedPlanId) return null;
    return plans?.rows?.find((p) => p.plan_id === selectedPlanId) ?? null;
  }, [plans, selectedPlanId]);

  const analysis = useMemo(() => {
    const rows = trades?.rows ?? [];
    let feeSum = 0;
    let closePnlSum = 0;
    let closes = 0;
    let tp = 0;
    let sl = 0;
    let otherClose = 0;
    for (const t of rows) {
      feeSum += Number(t.fee ?? 0);
      if (t.type === "CLOSE") {
        closes += 1;
        const p = Number(t.pnl ?? 0);
        closePnlSum += p;
        if (typeof t.reason === "string" && t.reason.startsWith("TP")) tp += 1;
        else if (t.reason === "SL") sl += 1;
        else otherClose += 1;
      }
    }
    const closeWinRate = closes > 0 ? tp / closes : 0;
    const avgPnl = closes > 0 ? closePnlSum / closes : 0;
    return { feeSum, closePnlSum, closes, tp, sl, otherClose, closeWinRate, avgPnl };
  }, [trades]);

  const sortedTrades = useMemo(() => {
    const rows = trades?.rows ?? [];
    const withPnl = (v: unknown): number | null => (typeof v === "number" && Number.isFinite(v) ? v : null);
    return rows
      .slice()
      .sort((a, b) => {
        if (tradeSortBy === "id") {
          return tradeSortDir === "asc" ? a.seq - b.seq : b.seq - a.seq;
        }
        const ap = withPnl(a.pnl);
        const bp = withPnl(b.pnl);
        if (ap === null && bp === null) return tradeSortDir === "asc" ? a.seq - b.seq : b.seq - a.seq;
        if (ap === null) return 1;
        if (bp === null) return -1;
        const d = tradeSortDir === "asc" ? ap - bp : bp - ap;
        if (d !== 0) return d;
        return tradeSortDir === "asc" ? a.seq - b.seq : b.seq - a.seq;
      });
  }, [trades, tradeSortBy, tradeSortDir]);

  return (
    <div className="space-y-5">
      <div className="rounded-xl border border-white/10 bg-white/5 p-5">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="space-y-1">
            <h1 className="text-xl font-semibold tracking-tight">回测</h1>
            <p className="text-sm text-white">基于历史 K 线进行回测，支持入库与结果分析。</p>
          </div>
          <div className="flex items-center gap-2">
            <button
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
              onClick={loadRange}
              disabled={busy}
            >
              刷新数据范围
            </button>
            <button
              className="rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-sm font-medium text-white hover:bg-amber-400/15 disabled:opacity-50"
              onClick={runBacktest}
              disabled={busy}
            >
              运行回测
            </button>
          </div>
        </div>

        {error ? (
          <div className="mt-4 rounded-lg border border-white/10 bg-white/5 p-3 text-sm text-white">{error}</div>
        ) : null}

        <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
          <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
            <div className="text-xs text-white">交易对</div>
            <input
              className="mt-2 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
              value={symbol}
              onChange={(e) => setSymbol(e.target.value)}
              placeholder="BTCUSDT"
            />
            <div className="mt-2 text-xs text-white/70">
              连接：{WEBMAN_BASE}
            </div>
          </div>

          <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
            <div className="text-xs text-white">回测窗口</div>
            <div className="mt-2 grid grid-cols-1 gap-2">
              <label className="text-xs text-white/70">
                start
                <input
                  type="datetime-local"
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                  value={startLocal}
                  onChange={(e) => setStartLocal(e.target.value)}
                />
              </label>
              <label className="text-xs text-white/70">
                end
                <input
                  type="datetime-local"
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                  value={endLocal}
                  onChange={(e) => setEndLocal(e.target.value)}
                />
              </label>
            </div>
            <div className="mt-2 text-xs text-white/70">
              1m 数据：{range?.ranges?.["1m"] ? `${formatMs(range.ranges["1m"].min_ms)} → ${formatMs(range.ranges["1m"].max_ms)}` : "-"}
            </div>
          </div>

          <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
            <div className="text-xs text-white">参数</div>
            <div className="mt-2 grid grid-cols-2 gap-2">
              <label className="text-xs text-white/70">
                mode
                <select
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                  value={mode}
                  onChange={(e) => setMode(e.target.value as Mode)}
                >
                  <option value="short">short</option>
                  <option value="long">long</option>
                  <option value="both">both</option>
                </select>
              </label>
              <label className="text-xs text-white/70">
                初始资金
                <input
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                  value={initialEquity}
                  onChange={(e) => setInitialEquity(e.target.value)}
                />
              </label>
              <label className="text-xs text-white/70">
                risk_pct
                <input
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                  value={riskPct}
                  onChange={(e) => setRiskPct(e.target.value)}
                />
              </label>
              <label className="text-xs text-white/70">
                fee_bps
                <input
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                  value={feeBps}
                  onChange={(e) => setFeeBps(e.target.value)}
                />
              </label>
              <label className="text-xs text-white/70">
                slippage_bps
                <input
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                  value={slippageBps}
                  onChange={(e) => setSlippageBps(e.target.value)}
                />
              </label>
              <label className="text-xs text-white/70">
                confirm_bars
                <input
                  className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
                  value={confirmBars}
                  onChange={(e) => setConfirmBars(e.target.value)}
                />
              </label>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-3 text-xs text-white/70">
              <label className="inline-flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={enable4hPreplan}
                  onChange={(e) => setEnable4hPreplan(e.target.checked)}
                />
                enable_4h_preplan
              </label>
              <label className="inline-flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={saveEquity}
                  onChange={(e) => setSaveEquity(e.target.checked)}
                />
                save_equity
              </label>
            </div>
          </div>
        </div>

        {lastRun?.ok && lastRun.summary ? (
          <div className="mt-4 rounded-lg border border-white/10 bg-[#0b0e11] p-3 text-sm text-white">
            <div className="flex flex-wrap items-center gap-3">
              <div className="font-medium">本次回测</div>
              <div>run_id：{lastRun.run_id ?? "-"}</div>
              <div>saved：{String(lastRun.saved ?? 0)}</div>
              <div>trades：{lastRun.summary.trades}</div>
              <div>closed：{lastRun.summary.closed_trades}</div>
              <div>win_rate：{(lastRun.summary.win_rate * 100).toFixed(2)}%</div>
              <div>pnl：{lastRun.summary.pnl.toFixed(2)}</div>
              <div>dd：{(lastRun.summary.max_drawdown * 100).toFixed(2)}%</div>
              <div>final：{lastRun.summary.final_cash.toFixed(2)}</div>
            </div>
            {lastRun.warnings?.length ? (
              <div className="mt-2 text-xs text-amber-200">{lastRun.warnings.join(" | ")}</div>
            ) : null}
          </div>
        ) : null}
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div className="rounded-xl border border-white/10 bg-white/5 md:col-span-1">
          <div className="flex items-center justify-between border-b border-white/10 p-4 text-sm font-medium">
            <div>回测记录</div>
            <div className="flex items-center gap-2">
              <button
                className="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs hover:bg-white/10 disabled:opacity-50"
                onClick={() => loadRuns(Math.max(0, runsOffset - 50))}
                disabled={busy || runsOffset <= 0}
              >
                上一页
              </button>
              <button
                className="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs hover:bg-white/10 disabled:opacity-50"
                onClick={() => loadRuns(runsOffset + 50)}
                disabled={busy}
              >
                下一页
              </button>
            </div>
          </div>
          <div className="max-h-[620px] overflow-auto">
            {!runs?.rows?.length ? (
              <div className="p-4 text-sm text-white">暂无回测记录</div>
            ) : (
              <table className="w-full text-left text-xs">
                <thead className="sticky top-0 bg-[#0b0e11] text-white">
                  <tr className="border-b border-white/10">
                    <th className="px-3 py-2">id</th>
                    <th className="px-3 py-2">mode</th>
                    <th className="px-3 py-2">pnl</th>
                    <th className="px-3 py-2">dd</th>
                  </tr>
                </thead>
                <tbody>
                  {runs.rows.map((r) => {
                    const active = selectedRunId === r.id;
                    const pnl = typeof r.summary?.["pnl"] === "number" ? (r.summary["pnl"] as number) : null;
                    const dd =
                      typeof r.summary?.["max_drawdown"] === "number"
                        ? (r.summary["max_drawdown"] as number)
                        : null;
                    return (
                      <tr
                        key={r.id}
                        className={`border-b border-white/5 hover:bg-white/5 ${active ? "bg-amber-500/10" : ""}`}
                        onClick={() => setSelectedRunId(r.id)}
                        role="button"
                      >
                        <td className="px-3 py-2 font-mono">{r.id}</td>
                        <td className="px-3 py-2">{r.mode}</td>
                        <td className="px-3 py-2 font-mono">{pnl !== null ? pnl.toFixed(2) : "-"}</td>
                        <td className="px-3 py-2 font-mono">{dd !== null ? `${(dd * 100).toFixed(2)}%` : "-"}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>

        <div className="rounded-xl border border-white/10 bg-white/5 md:col-span-2">
          <div className="border-b border-white/10 p-4 text-sm font-medium">分析</div>
          {!selectedRunId ? (
            <div className="p-4 text-sm text-white">请选择左侧某条回测记录</div>
          ) : (
            <div className="space-y-4 p-4">
              <div className="rounded-lg border border-white/10 bg-[#0b0e11] p-3 text-sm text-white">
                <div className="flex flex-wrap items-center gap-3">
                  <div className="font-medium">Run #{selectedRunId}</div>
                  <div>窗口：{formatMs(selectedRun?.row?.effective_start_ms)} → {formatMs(selectedRun?.row?.effective_end_ms)}</div>
                  <div>closes：{analysis.closes}</div>
                  <div>TP：{analysis.tp}</div>
                  <div>SL：{analysis.sl}</div>
                  <div>fee：{analysis.feeSum.toFixed(2)}</div>
                  <div>avg_pnl/close：{analysis.avgPnl.toFixed(4)}</div>
                  <div>TP率：{(analysis.closeWinRate * 100).toFixed(2)}%</div>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div className="rounded-lg border border-white/10 bg-[#0b0e11] md:col-span-2">
                  <div className="flex items-center justify-between border-b border-white/10 px-3 py-2 text-xs font-medium text-white">
                    <div>计划列表</div>
                    <div className="text-xs text-white/70">
                      {plans?.rows?.length ? `共 ${plans.rows.length} 条` : "0 条"}
                    </div>
                  </div>
                  <div className="max-h-72 overflow-auto">
                    {!plans?.rows?.length ? (
                      <div className="p-3 text-xs text-white/70">暂无计划（旧 run 或未开启 save）</div>
                    ) : (
                      <table className="w-full text-left text-xs">
                        <thead className="sticky top-0 bg-[#0b0e11] text-white">
                          <tr className="border-b border-white/10">
                            <th className="px-3 py-2">plan_id</th>
                            <th className="px-3 py-2">tf</th>
                            <th className="px-3 py-2">kind</th>
                            <th className="px-3 py-2">side</th>
                            <th className="px-3 py-2">trigger_k</th>
                            <th className="px-3 py-2">created_k(1m)</th>
                            <th className="px-3 py-2">entry</th>
                            <th className="px-3 py-2">sl</th>
                            <th className="px-3 py-2">tp_steps</th>
                          </tr>
                        </thead>
                        <tbody>
                          {plans.rows.map((p) => {
                            const active = selectedPlanId === p.plan_id;
                            const entry = typeof p.plan?.["entry_price"] === "number" ? (p.plan["entry_price"] as number) : null;
                            const sl = typeof p.plan?.["sl"] === "number" ? (p.plan["sl"] as number) : null;
                            const stepsRaw = p.plan?.["tp_steps"];
                            const steps = Array.isArray(stepsRaw) ? (stepsRaw as Array<Record<string, unknown>>) : [];
                            const stepsText =
                              steps.length > 0
                                ? steps
                                    .map((s) => {
                                      const t = String(s["target"] ?? "").trim();
                                      const pct = Number(s["pct"] ?? NaN);
                                      if (!t || !Number.isFinite(pct)) return null;
                                      return `${t} ${(pct * 100).toFixed(0)}%`;
                                    })
                                    .filter((x): x is string => Boolean(x))
                                    .join(", ")
                                : "-";
                            return (
                              <tr
                                key={p.plan_id}
                                className={`border-b border-white/5 hover:bg-white/5 ${active ? "bg-amber-500/10" : ""}`}
                                onClick={() => setSelectedPlanId(p.plan_id)}
                                role="button"
                              >
                                <td className="px-3 py-2 font-mono">{p.plan_id}</td>
                                <td className="px-3 py-2">{p.tf_trigger}</td>
                                <td className="px-3 py-2">{p.kind}</td>
                                <td className="px-3 py-2">{p.side}</td>
                                <td className="px-3 py-2">{p.trigger_open_time}</td>
                                <td className="px-3 py-2 font-mono">{p.created_close_ts}</td>
                                <td className="px-3 py-2 font-mono">{entry !== null ? entry.toFixed(2) : "-"}</td>
                                <td className="px-3 py-2 font-mono">{sl !== null ? sl.toFixed(2) : "-"}</td>
                                <td className="px-3 py-2 font-mono">{stepsText}</td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    )}
                  </div>
                  {selectedPlan ? (
                    <details className="border-t border-white/10 p-3">
                      <summary className="cursor-pointer text-xs font-medium text-white">
                        Plan #{selectedPlan.plan_id} 详情
                      </summary>
                      <pre className="mt-2 max-h-72 overflow-auto rounded bg-white/5 p-2 text-xs text-white">
                        {JSON.stringify(selectedPlan, null, 2)}
                      </pre>
                    </details>
                  ) : null}
                </div>

                <div className="rounded-lg border border-white/10 bg-[#0b0e11] md:col-span-2">
                  <div className="flex items-center justify-between border-b border-white/10 px-3 py-2 text-xs font-medium text-white">
                    <div>交易明细</div>
                    <div className="flex items-center gap-2">
                      <div className="text-xs text-white/70">{sortedTrades.length ? `共 ${sortedTrades.length} 笔` : "0 笔"}</div>
                      <button
                        className="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs hover:bg-white/10"
                        onClick={() => {
                          setTradeSortBy("id");
                          setTradeSortDir((v) => (tradeSortBy === "id" ? (v === "asc" ? "desc" : "asc") : "asc"));
                        }}
                        type="button"
                      >
                        id {tradeSortBy === "id" ? (tradeSortDir === "asc" ? "↑" : "↓") : ""}
                      </button>
                      <button
                        className="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs hover:bg-white/10"
                        onClick={() => {
                          setTradeSortBy("pnl");
                          setTradeSortDir((v) => (tradeSortBy === "pnl" ? (v === "asc" ? "desc" : "asc") : "desc"));
                        }}
                        type="button"
                      >
                        pnl {tradeSortBy === "pnl" ? (tradeSortDir === "asc" ? "↑" : "↓") : ""}
                      </button>
                    </div>
                  </div>
                  <div className="max-h-[520px] overflow-auto">
                    {!sortedTrades.length ? (
                      <div className="p-3 text-xs text-white/70">暂无交易记录</div>
                    ) : (
                      <table className="w-full min-w-[980px] text-left text-xs">
                        <thead className="sticky top-0 bg-[#0b0e11] text-white">
                          <tr className="border-b border-white/10">
                            <th className="px-3 py-2">seq</th>
                            <th className="px-3 py-2">type</th>
                            <th className="px-3 py-2">time</th>
                            <th className="px-3 py-2">side</th>
                            <th className="px-3 py-2">reason</th>
                            <th className="px-3 py-2">price</th>
                            <th className="px-3 py-2">qty</th>
                            <th className="px-3 py-2">fee</th>
                            <th className="px-3 py-2">pnl</th>
                            <th className="px-3 py-2">plan_id</th>
                            <th className="px-3 py-2">trigger_k</th>
                          </tr>
                        </thead>
                        <tbody>
                          {sortedTrades.map((t) => {
                            const pnl = typeof t.pnl === "number" ? t.pnl : null;
                            const pnlClass =
                              pnl === null
                                ? "text-white/70"
                                : pnl > 0
                                  ? "text-emerald-200"
                                  : pnl < 0
                                    ? "text-amber-200"
                                    : "text-white";
                            return (
                              <tr key={t.seq} className="border-b border-white/5 hover:bg-white/5">
                                <td className="px-3 py-2 font-mono">{t.seq}</td>
                                <td className="px-3 py-2">{t.type}</td>
                                <td className="px-3 py-2">{t.time}</td>
                                <td className="px-3 py-2">{t.side ?? "-"}</td>
                                <td className="px-3 py-2">{t.reason ?? "-"}</td>
                                <td className="px-3 py-2 font-mono">{t.price !== null ? t.price.toFixed(4) : "-"}</td>
                                <td className="px-3 py-2 font-mono">{t.qty !== null ? t.qty.toFixed(6) : "-"}</td>
                                <td className="px-3 py-2 font-mono">{t.fee !== null ? t.fee.toFixed(6) : "-"}</td>
                                <td className={`px-3 py-2 font-mono ${pnlClass}`}>{pnl !== null ? pnl.toFixed(6) : "-"}</td>
                                <td className="px-3 py-2 font-mono">{t.plan_id ?? "-"}</td>
                                <td className="px-3 py-2">{t.plan?.trigger_open_time ?? "-"}</td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    )}
                  </div>
                </div>
              </div>

              {equity?.rows?.length ? (
                <details className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
                  <summary className="cursor-pointer text-xs font-medium text-white">
                    equity_curve（已入库，共 {equity.rows.length} 点）
                  </summary>
                  <pre className="mt-2 max-h-72 overflow-auto rounded bg-white/5 p-2 text-xs text-white">
                    {JSON.stringify(equity.rows.slice(0, 50), null, 2)}
                  </pre>
                </details>
              ) : null}

              <details className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
                <summary className="cursor-pointer text-xs font-medium text-white">run 参数 / summary / warnings</summary>
                <pre className="mt-2 max-h-72 overflow-auto rounded bg-white/5 p-2 text-xs text-white">
                  {JSON.stringify(
                    {
                      created_at: selectedRun?.row?.created_at,
                      params: selectedRun?.row?.params,
                      summary: selectedRun?.row?.summary,
                      warnings: selectedRun?.row?.warnings,
                    },
                    null,
                    2,
                  )}
                </pre>
              </details>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
