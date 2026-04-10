"use client";

import { useEffect, useMemo, useState } from "react";

type Interval = "1m" | "5m" | "15m" | "30m" | "1h" | "4h";

type AbcStructure = {
  id: number;
  symbol: string;
  interval: string;
  status: string;
  direction?: string | null;
  last_state?: string | null;
  a_point?: Record<string, unknown> | null;
  a_confirm_time?: string | null;
  b_points: Array<Record<string, unknown>>;
  c_points: Array<Record<string, unknown>>;
  close_reason?: string | null;
  close_condition?: Record<string, unknown> | null;
  engine_state?: Record<string, unknown> | null;
  start_time?: string | null;
  end_time?: string | null;
  created_at: string;
  updated_at: string;
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

function formatTs(v?: string | null) {
  if (!v) return "-";
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return v;
  return d.toLocaleString();
}

function formatJson(v: unknown) {
  try {
    return JSON.stringify(v, null, 2);
  } catch {
    return String(v);
  }
}

export default function AbcPage() {
  const [interval, setIntervalValue] = useState<Interval>("1h");
  const [symbols, setSymbols] = useState<string[]>([]);
  const [symbol, setSymbol] = useState<string>("");
  const [status, setStatus] = useState<string>("");

  const [structures, setStructures] = useState<AbcStructure[]>([]);
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

  useEffect(() => {
    let cancelled = false;
    setError("");
    apiGet<string[]>(`/trend/abc/symbols?interval=${encodeURIComponent(interval)}`)
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
      const rows = await apiGet<AbcStructure[]>(`/trend/abc/structures?${qs.toString()}`);
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

  return (
    <div className="space-y-5 text-white">
      <div className="rounded-xl border border-white/10 bg-white/5 p-5">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="space-y-1">
            <h1 className="text-xl font-semibold tracking-tight">ABC 形态</h1>
            <p className="text-sm text-white">按交易对与周期查看 ABC 三角结构体数据。</p>
          </div>
          <div className="flex items-center gap-2">
            <button
              className="rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-sm font-medium text-white hover:bg-amber-400/15 disabled:opacity-50"
              onClick={() => void loadStructures()}
              disabled={!symbol || busy}
            >
              刷新
            </button>
          </div>
        </div>

        <div className="mt-4 grid gap-3 md:grid-cols-4">
          <div className="space-y-1 md:col-span-4">
            <div className="text-xs text-white/60">周期</div>
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
          </div>

          <label className="space-y-1">
            <div className="text-xs text-white/60">交易对</div>
            <select
              className="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm"
              value={symbol}
              onChange={(e) => setSymbol(e.target.value)}
            >
              {symbols.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
          </label>

          <div className="space-y-1">
            <div className="text-xs text-white/60">状态</div>
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
          </div>

          <div className="space-y-1">
            <div className="text-xs text-white/60">统计</div>
            <div className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm">
              {counts.active} active / {counts.closed} closed / {counts.total} total
            </div>
          </div>
        </div>

        {error ? <div className="mt-3 text-sm text-red-200">{error}</div> : null}
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <div className="rounded-xl border border-white/10 bg-white/5 p-5">
          <div className="flex items-center justify-between gap-3">
            <h2 className="text-base font-semibold">结构列表</h2>
            <div className="flex items-center gap-2">
              <label className="inline-flex items-center gap-2 text-xs text-white/60">
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
              <div className="text-xs text-white/60">
                {busy ? "加载中…" : `offset ${structuresOffset}`}
              </div>
            </div>
          </div>
          <div className="mt-3 overflow-auto rounded-lg border border-white/10">
            <table className="w-full text-left text-sm">
              <thead className="bg-white/5 text-xs text-white/60">
                <tr>
                  <th className="px-3 py-2">ID</th>
                  <th className="px-3 py-2">状态</th>
                  <th className="px-3 py-2">方向</th>
                  <th className="px-3 py-2">状态机</th>
                  <th className="px-3 py-2">更新时间</th>
                </tr>
              </thead>
              <tbody>
                {structures.map((s) => {
                  const active = s.id === selectedId;
                  return (
                    <tr
                      key={s.id}
                      className={`cursor-pointer border-t border-white/10 hover:bg-white/5 ${active ? "bg-white/10" : ""}`}
                      onClick={() => setSelectedId(s.id)}
                    >
                      <td className="px-3 py-2 font-mono">{s.id}</td>
                      <td className="px-3 py-2">{s.status}</td>
                      <td className="px-3 py-2">{s.direction ?? "-"}</td>
                      <td className="px-3 py-2">{s.last_state ?? "-"}</td>
                      <td className="px-3 py-2">{formatTs(s.updated_at)}</td>
                    </tr>
                  );
                })}
                {structures.length === 0 ? (
                  <tr>
                    <td className="px-3 py-3 text-sm text-white/60" colSpan={5}>
                      暂无数据
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>
        </div>

        <div className="rounded-xl border border-white/10 bg-white/5 p-5">
          <h2 className="text-base font-semibold">结构详情</h2>
          {selected ? (
            <div className="mt-3 space-y-4">
              <div className="grid gap-3 md:grid-cols-2">
                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="text-xs text-white/60">时间</div>
                  <div className="mt-1 text-sm">
                    <div>
                      <span className="text-white/60">start:</span> {formatTs(selected.start_time)}
                    </div>
                    <div>
                      <span className="text-white/60">end:</span> {formatTs(selected.end_time)}
                    </div>
                    <div>
                      <span className="text-white/60">A confirm:</span> {formatTs(selected.a_confirm_time)}
                    </div>
                  </div>
                </div>

                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="text-xs text-white/60">关闭信息</div>
                  <div className="mt-1 text-sm">
                    <div>
                      <span className="text-white/60">reason:</span> {selected.close_reason ?? "-"}
                    </div>
                    <div>
                      <span className="text-white/60">status:</span> {selected.status}
                    </div>
                  </div>
                </div>
              </div>

              <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                <div className="text-xs text-white/60">A 点</div>
                <pre className="mt-2 max-h-64 overflow-auto rounded bg-black/30 p-3 text-xs">
                  {formatJson(selected.a_point ?? null)}
                </pre>
              </div>

              <div className="grid gap-3 md:grid-cols-2">
                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="text-xs text-white/60">B 点列表</div>
                  <pre className="mt-2 max-h-64 overflow-auto rounded bg-black/30 p-3 text-xs">
                    {formatJson(selected.b_points ?? [])}
                  </pre>
                </div>
                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="text-xs text-white/60">C 点列表</div>
                  <pre className="mt-2 max-h-64 overflow-auto rounded bg-black/30 p-3 text-xs">
                    {formatJson(selected.c_points ?? [])}
                  </pre>
                </div>
              </div>

              <div className="grid gap-3 md:grid-cols-2">
                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="text-xs text-white/60">close_condition</div>
                  <pre className="mt-2 max-h-64 overflow-auto rounded bg-black/30 p-3 text-xs">
                    {formatJson(selected.close_condition ?? null)}
                  </pre>
                </div>
                <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                  <div className="text-xs text-white/60">engine_state</div>
                  <pre className="mt-2 max-h-64 overflow-auto rounded bg-black/30 p-3 text-xs">
                    {formatJson(selected.engine_state ?? null)}
                  </pre>
                </div>
              </div>
            </div>
          ) : (
            <div className="mt-3 text-sm text-white/60">请选择一个结构查看详情。</div>
          )}
        </div>
      </div>
    </div>
  );
}
