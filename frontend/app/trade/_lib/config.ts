import type { Interval } from "./types";

export const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? "http://localhost:8000/api";
export const WS_BASE =
  process.env.NEXT_PUBLIC_WS_BASE ??
  API_BASE.replace(/^http:/, "ws:").replace(/^https:/, "wss:").replace(/\/api\/?$/, "");

export const BOLL_PERIOD = 400;
export const BOLL_MULT = 2;

export const BINANCE_FAPI_HTTP = "https://fapi.binance.com";
export const BINANCE_WS_HOSTS = ["fstream.binance.com", "fstream2.binance.com", "fstream3.binance.com"] as const;

export const DEFAULT_SYMBOLS = ["BTCUSDT", "ETHUSDT", "BNBUSDT"] as const;
export const INTERVALS: Interval[] = ["1m", "5m", "15m", "30m", "1h", "4h"];
