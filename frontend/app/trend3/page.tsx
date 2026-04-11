"use client";

import { useEffect, useMemo, useState } from "react";

type Interval = "1m" | "5m" | "15m" | "30m" | "1h" | "4h";

type KeyPoint = { time: string; price: number; kind?: string; label?: string };

type StructureV3 = {
  id: number;
  symbol: string;
  interval: string;
  status: string;
  x_points: KeyPoint[];
  y_points: KeyPoint[];
  close_reason?: string | null;
  close_condition?: Record<string, unknown> | null;
  engine_state?: Record<string, unknown> | null;
  start_time?: string | null;
  end_time?: string | null;
  created_at: string;
  updated_at: string;
};

type RunnerStatus = {
  running: boolean;
  dry_run?: boolean;
  mode?: string;
  symbols?: string[];
  webman_base_url?: string;
  last_poll_ts?: number | null;
  last_emit_ok_ts?: number | null;
  last_exec_ok_ts?: number | null;
  last_error?: string | null;
};

function joinUrl(base: string, path: string) {
  const b = base.endsWith("/") ? base.slice(0, -1) : base;
  const p = path.startsWith("/") ? path : `/${path}`;
  return `${b}${p}`;
}

const API_BASE_CANDIDATES = [
  process.env.NEXT_PUBLIC_API_BASE,
  "/api",
  "http://localhost:8000/api",
  "http://127.0.0.1:8000/api",
].filter((x): x is string => Boolean(x && String(x).trim()));

async function apiPostNoBody<T>(path: string): Promise<T> {
  let lastErr: unknown = null;
  for (const base of API_BASE_CANDIDATES) {
    try {
      const url = joinUrl(base, path);
      const res = await fetch(url, { method: "POST" });
      if (!res.ok) {
        lastErr = new Error(`${url} -> HTTP ${res.status}`);
        continue;
      }
      return (await res.json()) as T;
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr instanceof Error ? lastErr : new Error("fetch failed");
}

const INTERVALS: Interval[] = ["1m", "5m", "15m", "30m", "1h", "4h"];

async function apiGet<T>(path: string): Promise<T> {
  let lastErr: unknown = null;
  for (const base of API_BASE_CANDIDATES) {
    try {
      const url = joinUrl(base, path);
      const res = await fetch(url, { cache: "no-store" });
      if (!res.ok) {
        lastErr = new Error(`${url} -> HTTP ${res.status}`);
        continue;
      }
      return (await res.json()) as T;
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr instanceof Error ? lastErr : new Error("fetch failed");
}

async function apiPost<T>(path: string, body: unknown): Promise<T> {
  let lastErr: unknown = null;
  for (const base of API_BASE_CANDIDATES) {
    try {
      const url = joinUrl(base, path);
      const res = await fetch(url, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(body),
      });
      if (!res.ok) {
        lastErr = new Error(`${url} -> HTTP ${res.status}`);
        continue;
      }
      return (await res.json()) as T;
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr instanceof Error ? lastErr : new Error("fetch failed");
}

function formatTs(v?: string | null) {
  if (!v) return "-";
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return v;
  return d.toLocaleString();
}

function formatEpochSec(v?: number | null) {
  if (!v) return "-";
  const d = new Date(v * 1000);
  if (Number.isNaN(d.getTime())) return String(v);
  return d.toLocaleString();
}

export default function Trend3Page() {
  const [interval, setIntervalValue] = useState<Interval>("1h");
  const [symbols, setSymbols] = useState<string[]>([]);
  const [symbol, setSymbol] = useState<string>("");
  const [status, setStatus] = useState<string>("");

  const [structures, setStructures] = useState<StructureV3[]>([]);
  const [structuresLimit, setStructuresLimit] = useState<number>(50);
  const [structuresOffset, setStructuresOffset] = useState<number>(0);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const selected = useMemo(
    () => structures.find((s) => s.id === selectedId) ?? null,
    [selectedId, structures],
  );
  const counts = useMemo(() => {
    let active = 0;
    let closed = 0;
    for (const s of structures) {
      if (s.status === "ACTIVE") active += 1;
      else if (s.status === "CLOSED") closed += 1;
    }
    return { active, closed, total: structures.length };
  }, [structures]);

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>("");

  const [runner, setRunner] = useState<RunnerStatus | null>(null);
  const [runnerBusy, setRunnerBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setError("");
    apiGet<string[]>(`/trend3/symbols?interval=${encodeURIComponent(interval)}`)
      .then((rows) => {
        if (cancelled) return;
        setSymbols(rows);
        setSymbol((prev) => (prev && rows.includes(prev) ? prev : rows[0] ?? ""));
      })
      .catch((e) => {
        if (cancelled) return;
        setError(String(e));
        setSymbols([]);
        setSymbol("");
      });
    return () => {
      cancelled = true;
    };
  }, [interval]);

  async function loadStructures() {
    if (!symbol) return;
    setBusy(true);
    setError("");
    try {
      const qs = new URLSearchParams({
        symbol,
        interval,
        limit: String(structuresLimit),
        offset: String(structuresOffset),
      });
      if (status) qs.set("status", status);
      const rows = await apiGet<StructureV3[]>(`/trend3/structures?${qs.toString()}`);
      setStructures(rows);
      setSelectedId(rows[0]?.id ?? null);
    } catch (e) {
      setError(String(e));
      setStructures([]);
      setSelectedId(null);
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    if (!symbol) return;
    if (structuresOffset !== 0) {
      setStructuresOffset(0);
      return;
    }
    void loadStructures();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [symbol, interval, status]);

  useEffect(() => {
    void loadStructures();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [structuresOffset, structuresLimit]);

  async function loadRunnerStatus() {
    try {
      const st = await apiGet<RunnerStatus>("/trend3/runner/status");
      setRunner(st);
    } catch {
      setRunner(null);
    }
  }

  useEffect(() => {
    loadRunnerStatus();
    const t = globalThis.setInterval(loadRunnerStatus, 2000);
    return () => clearInterval(t);
  }, []);

  async function runEngine() {
    if (!symbol) return;
    setBusy(true);
    setError("");
    try {
      await apiPost<{ ok: boolean }>(`/trend3/run`, {
        symbol,
        interval,
      });
      await loadStructures();
    } catch (e) {
      setError(String(e));
    } finally {
      setBusy(false);
    }
  }

  async function startRunner() {
    setRunnerBusy(true);
    setError("");
    try {
      const qs = new URLSearchParams({ dry_run: "true", mode: "both" });
      const st = await apiPostNoBody<RunnerStatus>(`/trend3/runner/start?${qs.toString()}`);
      setRunner(st);
    } catch (e) {
      setError(String(e));
    } finally {
      setRunnerBusy(false);
    }
  }

  async function stopRunner() {
    setRunnerBusy(true);
    setError("");
    try {
      const st = await apiPostNoBody<RunnerStatus>("/trend3/runner/stop");
      setRunner(st);
    } catch (e) {
      setError(String(e));
    } finally {
      setRunnerBusy(false);
    }
  }

  return (
    <div className="space-y-5">
      <div className="rounded-xl border border-white/10 bg-white/5 p-5">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="space-y-1">
            <h1 className="text-xl font-semibold tracking-tight">趋势 V3</h1>
            <p className="text-sm text-white">加载 oscillation_structures_v3 数据。</p>
          </div>
          <div className="flex items-center gap-2">
            <button
              className="rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-sm font-medium text-white hover:bg-amber-400/15 disabled:opacity-50"
              onClick={runEngine}
              disabled={!symbol || busy}
            >
              刷新识别
            </button>
            <button
              className="rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-400/15 disabled:opacity-50"
              onClick={startRunner}
              disabled={runnerBusy}
              title="启动策略 Runner（dry-run）"
            >
              启动策略
            </button>
            <button
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
              onClick={stopRunner}
              disabled={runnerBusy}
              title="停止策略 Runner"
            >
              停止策略
            </button>
            <button
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm hover:bg-white/10 disabled:opacity-50"
              onClick={loadStructures}
              disabled={!symbol || busy}
            >
              刷新列表
            </button>
          </div>
        </div>

        <div className="mt-3 rounded-lg border border-white/10 bg-[#0b0e11] p-3 text-xs text-white">
          <div className="flex flex-wrap items-center gap-3">
            <div className="font-medium">策略 Runner</div>
            <div>状态：{runner?.running ? "RUNNING" : "STOPPED"}</div>
            <div>dry_run：{runner?.dry_run ? "true" : "false"}</div>
            <div>mode：{runner?.mode ?? "-"}</div>
            <div>最后轮询：{formatEpochSec(runner?.last_poll_ts ?? null)}</div>
            <div>最后产出：{formatEpochSec(runner?.last_emit_ok_ts ?? null)}</div>
            <div>最后执行：{formatEpochSec(runner?.last_exec_ok_ts ?? null)}</div>
          </div>
          {runner?.last_error ? <div className="mt-2 text-amber-200">{runner.last_error}</div> : null}
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <div className="inline-flex rounded-lg bg-white/5 p-1 text-sm">
            {INTERVALS.map((itv) => (
              <button
                key={itv}
                className={`rounded-md px-3 py-1.5 ${
                  interval === itv ? "bg-[#0b0e11] text-white" : "text-white hover:bg-white/10"
                }`}
                onClick={() => setIntervalValue(itv)}
                disabled={busy}
              >
                {itv}
              </button>
            ))}
          </div>

          <div className="flex items-center gap-2">
            <div className="text-sm text-white">交易对</div>
            <select
              className="min-w-44 rounded-lg border border-white/10 bg-[#0b0e11] px-3 py-2 text-sm text-white"
              value={symbol}
              onChange={(e) => setSymbol(e.target.value)}
            >
              {symbols.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
          </div>

          <div className="inline-flex rounded-lg bg-white/5 p-1 text-sm">
            <button
              className={`rounded-md px-3 py-1.5 ${
                status === "" ? "bg-[#0b0e11] text-white" : "text-white hover:bg-white/10"
              }`}
              onClick={() => setStatus("")}
              disabled={busy}
            >
              全部
            </button>
            <button
              className={`rounded-md px-3 py-1.5 ${
                status === "ACTIVE" ? "bg-[#0b0e11] text-white" : "text-white hover:bg-white/10"
              }`}
              onClick={() => setStatus("ACTIVE")}
              disabled={busy}
            >
              ACTIVE
            </button>
            <button
              className={`rounded-md px-3 py-1.5 ${
                status === "CLOSED" ? "bg-[#0b0e11] text-white" : "text-white hover:bg-white/10"
              }`}
              onClick={() => setStatus("CLOSED")}
              disabled={busy}
            >
              CLOSED
            </button>
          </div>

          <div className="text-sm text-white">
            当前 {symbol || "-"} / {interval} · 本页 {structures.length} 条（offset {structuresOffset}）
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div className="rounded-xl border border-white/10 bg-white/5 p-4">
          <div className="text-xs text-white">结构总数</div>
          <div className="mt-1 text-2xl font-semibold">{counts.total}</div>
        </div>
        <div className="rounded-xl border border-white/10 bg-white/5 p-4">
          <div className="text-xs text-white">ACTIVE</div>
          <div className="mt-1 text-2xl font-semibold text-white">{counts.active}</div>
        </div>
        <div className="rounded-xl border border-white/10 bg-white/5 p-4">
          <div className="text-xs text-white">CLOSED</div>
          <div className="mt-1 text-2xl font-semibold">{counts.closed}</div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
        {error ? (
          <div className="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-white md:col-span-4">
            {error}
          </div>
        ) : null}

        <div className="rounded-xl border border-white/10 bg-white/5 md:col-span-2">
          <div className="flex items-center justify-between gap-3 border-b border-white/10 p-4 text-sm font-medium">
            <div>结构列表</div>
            <div className="flex items-center gap-2">
              <label className="inline-flex items-center gap-2 text-xs font-normal text-white/70">
                每页
                <select
                  className="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs text-white"
                  value={structuresLimit}
                  onChange={(e) => setStructuresLimit(Number(e.target.value) || 50)}
                  disabled={busy}
                >
                  <option value={50}>50</option>
                  <option value={100}>100</option>
                  <option value={200}>200</option>
                </select>
              </label>
              <button
                className="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs hover:bg-white/10 disabled:opacity-50"
                onClick={() => setStructuresOffset(Math.max(0, structuresOffset - structuresLimit))}
                disabled={busy || structuresOffset <= 0}
              >
                上一页
              </button>
              <button
                className="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs hover:bg-white/10 disabled:opacity-50"
                onClick={() => setStructuresOffset(structuresOffset + structuresLimit)}
                disabled={busy || structures.length < structuresLimit}
              >
                下一页
              </button>
            </div>
          </div>
          <div className="max-h-[520px] overflow-auto">
            {structures.length === 0 ? (
              <div className="p-4 text-sm text-white">暂无数据</div>
            ) : (
              <table className="w-full text-left text-sm">
                <thead className="sticky top-0 bg-[#0b0e11]">
                  <tr className="border-b border-white/10 text-xs text-white">
                    <th className="px-3 py-2">ID</th>
                    <th className="px-3 py-2">状态</th>
                    <th className="px-3 py-2">开始</th>
                    <th className="px-3 py-2">结束</th>
                    <th className="px-3 py-2">X/Y</th>
                  </tr>
                </thead>
                <tbody>
                  {structures.map((s) => {
                    const active = s.id === selectedId;
                    return (
                      <tr
                        key={s.id}
                        className={`border-b border-white/5 hover:bg-white/5 ${active ? "bg-amber-500/10" : ""}`}
                        onClick={() => setSelectedId(s.id)}
                        role="button"
                      >
                        <td className="px-3 py-2 font-mono text-xs">{s.id}</td>
                        <td className="px-3 py-2">{s.status}</td>
                        <td className="px-3 py-2">{formatTs(s.start_time)}</td>
                        <td className="px-3 py-2">{formatTs(s.end_time)}</td>
                        <td className="px-3 py-2">
                          {s.x_points?.length ?? 0}/{s.y_points?.length ?? 0}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>

        <div className="rounded-xl border border-white/10 bg-white/5 md:col-span-2">
          <div className="border-b border-white/10 p-4 text-sm font-medium">结构详情</div>
          {!selected ? (
            <div className="p-4 text-sm text-white">请选择左侧结构</div>
          ) : (
            <div className="space-y-4 p-3 text-sm">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <div className="text-xs text-white">结构 ID</div>
                  <div className="font-mono">{selected.id}</div>
                </div>
                <div>
                  <div className="text-xs text-white">状态</div>
                  <div>{selected.status}</div>
                </div>
                <div className="col-span-2">
                  <div className="text-xs text-white">时间范围</div>
                  <div>
                    {formatTs(selected.start_time)} → {formatTs(selected.end_time)}
                  </div>
                </div>
                <div className="col-span-2">
                  <div className="text-xs text-white">关闭原因</div>
                  <div>{selected.close_reason ?? "-"}</div>
                </div>
                <div className="col-span-2">
                  <div className="text-xs text-white">X/Y 数量</div>
                  <div>
                    {(selected.x_points?.length ?? 0)}/{(selected.y_points?.length ?? 0)}
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div className="rounded-lg border border-white/10 bg-[#0b0e11]">
                  <div className="border-b border-white/10 px-3 py-2 text-xs font-medium text-white">X 点（下轨）</div>
                  <div className="max-h-56 overflow-auto">
                    {selected.x_points?.length ? (
                      <table className="w-full text-left text-xs">
                        <thead className="sticky top-0 bg-[#0b0e11] text-white">
                          <tr className="border-b border-white/10">
                            <th className="px-3 py-2">label</th>
                            <th className="px-3 py-2">time</th>
                            <th className="px-3 py-2">price</th>
                          </tr>
                        </thead>
                        <tbody>
                          {selected.x_points.map((p, idx) => (
                            <tr key={`${p.time}-${p.label ?? idx}`} className="border-b border-white/5 hover:bg-white/5">
                              <td className="px-3 py-2 font-mono text-[11px] text-white/70">{p.label ?? `X${idx + 1}`}</td>
                              <td className="px-3 py-2">{formatTs(p.time)}</td>
                              <td className="px-3 py-2 font-mono">{p.price}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    ) : (
                      <div className="px-3 py-2 text-xs text-white">暂无</div>
                    )}
                  </div>
                </div>

                <div className="rounded-lg border border-white/10 bg-[#0b0e11]">
                  <div className="border-b border-white/10 px-3 py-2 text-xs font-medium text-white">Y 点（上轨）</div>
                  <div className="max-h-56 overflow-auto">
                    {selected.y_points?.length ? (
                      <table className="w-full text-left text-xs">
                        <thead className="sticky top-0 bg-[#0b0e11] text-white">
                          <tr className="border-b border-white/10">
                            <th className="px-3 py-2">label</th>
                            <th className="px-3 py-2">time</th>
                            <th className="px-3 py-2">price</th>
                          </tr>
                        </thead>
                        <tbody>
                          {selected.y_points.map((p, idx) => (
                            <tr key={`${p.time}-${p.label ?? idx}`} className="border-b border-white/5 hover:bg-white/5">
                              <td className="px-3 py-2 font-mono text-[11px] text-white/70">{p.label ?? `Y${idx + 1}`}</td>
                              <td className="px-3 py-2">{formatTs(p.time)}</td>
                              <td className="px-3 py-2 font-mono">{p.price}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    ) : (
                      <div className="px-3 py-2 text-xs text-white">暂无</div>
                    )}
                  </div>
                </div>
              </div>

              <details className="rounded-lg border border-white/10 bg-[#0b0e11] p-3">
                <summary className="cursor-pointer text-xs font-medium">close_condition / engine_state</summary>
                <pre className="mt-2 overflow-auto rounded bg-white/5 p-2 text-xs text-white">
                  {JSON.stringify(
                    {
                      close_condition: selected.close_condition,
                      engine_state: selected.engine_state,
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
