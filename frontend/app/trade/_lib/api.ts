import { API_BASE, BINANCE_FAPI_HTTP, MARKET_API_BASE } from "./config";
import { intervalMs } from "./intervalMs";
import type { Interval } from "./types";
import type { StreamCandle } from "./types";

type OscPoint = { time?: string; price: number; kind?: "X" | "Y" };
export type ActiveOscillationStructure = {
  id: number;
  symbol: string;
  interval: Interval;
  start_time: string | null;
  updated_at: string | null;
  x_points: OscPoint[];
  y_points: OscPoint[];
};

export async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, { cache: "no-store" });
  if (!res.ok) throw new Error(await res.text());
  return (await res.json()) as T;
}

export async function marketApiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${MARKET_API_BASE}${path}`, { cache: "no-store" });
  if (!res.ok) throw new Error(await res.text());
  return (await res.json()) as T;
}

export async function fetchActiveOscillationStructures(
  symbol: string,
  intervals: Interval[] = ["4h", "1h", "30m", "15m", "5m", "1m"],
): Promise<ActiveOscillationStructure[]> {
  const q = intervals.join(",");
  return await marketApiGet<ActiveOscillationStructure[]>(
    `/trend/oscillation/active?symbol=${encodeURIComponent(symbol)}&intervals=${encodeURIComponent(q)}`,
  );
}

export function parseIsoAsUtcMs(s: string): number {
  const v = s.trim();
  if (!v) return NaN;
  const normalized = v.includes("T") ? v : v.replace(" ", "T");
  return Date.parse(normalized);
}

export async function fetchMarketKlines(symbol: string, interval: Interval, limit = 1500): Promise<StreamCandle[]> {
  const rows = await marketApiGet<
    {
      open_time: string;
      open_time_ms?: number;
      open: number;
      high: number;
      low: number;
      close: number;
      boll_up: number | null;
      boll_dn: number | null;
    }[]
  >(`/market/klines?symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(interval)}&limit=${limit}&ensure=true`);

  const candles: StreamCandle[] = [];
  for (const r of rows) {
    const ms =
      typeof r.open_time_ms === "number" && Number.isFinite(r.open_time_ms) && r.open_time_ms > 0
        ? r.open_time_ms
        : parseIsoAsUtcMs(r.open_time);
    if (!Number.isFinite(ms)) continue;
    candles.push({
      symbol,
      open_time_ms: ms,
      close_time_ms: ms,
      open: r.open,
      high: r.high,
      low: r.low,
      close: r.close,
      boll_up: r.boll_up,
      boll_dn: r.boll_dn,
    });
  }
  return candles;
}

export async function fetchMarketKlinesBefore(
  symbol: string,
  interval: Interval,
  beforeOpenTimeMs: number,
  limit = 1500,
): Promise<StreamCandle[]> {
  const before = Number(beforeOpenTimeMs);
  if (!Number.isFinite(before) || before <= 0) return [];
  const rows = await marketApiGet<
    {
      open_time: string;
      open_time_ms?: number;
      open: number;
      high: number;
      low: number;
      close: number;
      boll_up: number | null;
      boll_dn: number | null;
    }[]
  >(
    `/market/klines?symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(interval)}&limit=${limit}&before_ms=${before}&ensure=true`,
  );

  const candles: StreamCandle[] = [];
  for (const r of rows) {
    const ms =
      typeof r.open_time_ms === "number" && Number.isFinite(r.open_time_ms) && r.open_time_ms > 0
        ? r.open_time_ms
        : parseIsoAsUtcMs(r.open_time);
    if (!Number.isFinite(ms)) continue;
    candles.push({
      symbol,
      open_time_ms: ms,
      close_time_ms: ms,
      open: r.open,
      high: r.high,
      low: r.low,
      close: r.close,
      boll_up: r.boll_up,
      boll_dn: r.boll_dn,
    });
  }
  return candles;
}

/** 币安 REST 最后一根 = 当前周期 K（可未收盘） */
export async function fetchBinanceLatestKline(symbol: string, interval: Interval): Promise<StreamCandle | null> {
  try {
    const res = await fetch(
      `${BINANCE_FAPI_HTTP}/fapi/v1/klines?symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(interval)}&limit=2`,
      { cache: "no-store" },
    );
    if (!res.ok) return null;
    const raw = (await res.json()) as unknown;
    if (!Array.isArray(raw) || raw.length === 0) return null;
    const row = raw[raw.length - 1];
    if (!Array.isArray(row) || row.length < 9) return null;
    const openMs = Number(row[0]);
    if (!Number.isFinite(openMs) || openMs <= 0) return null;
    return {
      symbol,
      is_final: false,
      open_time_ms: openMs,
      close_time_ms: Number(row[6]) || openMs,
      open: row[1],
      high: row[2],
      low: row[3],
      close: row[4],
      volume: row[5],
      amount: row[7],
      num_trades: row[8],
    };
  } catch {
    return null;
  }
}

/**
 * 用交易所 REST 当前 K 对齐尾部；若库时间戳异常偏新则先弹出再合并，避免丢掉「当前未收盘」一根。
 */
export function mergeLatestOpenKlineFromExchange(candles: StreamCandle[], live: StreamCandle): StreamCandle[] {
  const out = candles.length ? [...candles] : [];
  while (out.length > 0 && out[out.length - 1]!.open_time_ms > live.open_time_ms) {
    out.pop();
  }
  if (out.length === 0) return [live];
  const last = out[out.length - 1]!;
  if (live.open_time_ms === last.open_time_ms) {
    return [...out.slice(0, -1), live];
  }
  if (live.open_time_ms > last.open_time_ms) {
    return [...out, live];
  }
  return out;
}

export async function fetchBinanceTickerPrice(symbol: string): Promise<number | null> {
  try {
    const res = await fetch(
      `${BINANCE_FAPI_HTTP}/fapi/v1/ticker/price?symbol=${encodeURIComponent(symbol)}`,
      { cache: "no-store" },
    );
    if (!res.ok) return null;
    const obj = (await res.json()) as unknown;
    if (typeof obj !== "object" || obj === null) return null;
    const p = Number((obj as Record<string, unknown>)["price"]);
    return Number.isFinite(p) ? p : null;
  } catch {
    return null;
  }
}

/**
 * 保证最后一根对应的 open_time 为「当前周期」起点（与币安 bucket 一致），避免只显示已收盘 K。
 * REST/kline 都不可用时，用 seedClose（最新 ticker 或上一根收盘价）生成本周期占位，再由 tick/kline 流更新。
 */
export function ensureCurrentPeriodTail(
  candles: StreamCandle[],
  symbol: string,
  interval: Interval,
  seedClose: number | null,
  nowMs: number = Date.now(),
): StreamCandle[] {
  const itv = intervalMs(interval);
  const bucketStart = Math.floor(nowMs / itv) * itv;

  const out = [...candles];
  while (out.length > 0 && out[out.length - 1]!.open_time_ms > bucketStart) {
    out.pop();
  }

  const seed = Number.isFinite(seedClose ?? NaN) ? (seedClose as number) : null;
  if (out.length === 0) {
    const p = seed ?? 0;
    return [
      {
        symbol,
        open_time_ms: bucketStart,
        close_time_ms: bucketStart,
        open: p,
        high: p,
        low: p,
        close: p,
        is_final: false,
      },
    ];
  }

  const last = out[out.length - 1]!;
  if (last.open_time_ms < bucketStart) {
    const p = seed ?? Number(last.close);
    out.push({
      symbol,
      open_time_ms: bucketStart,
      close_time_ms: bucketStart,
      open: p,
      high: p,
      low: p,
      close: p,
      is_final: false,
    });
  }

  return out;
}
