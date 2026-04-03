import { BINANCE_WS_HOSTS } from "./config";
import type { Interval } from "./types";

/** USD-M：K 线走 Market `/market/ws/` */
export function binanceKlineWsUrl(host: string, symbol: string, interval: Interval): string {
  const s = symbol.trim().toLowerCase();
  return `wss://${host}/ws/${s}@kline_${interval}`;
}

/** USD-M：聚合成交走 Market（字段 `e` 为 `aggTrade`） */
export function binanceAggTradeWsUrl(host: string, symbol: string): string {
  const s = symbol.trim().toLowerCase();
  return `wss://${host}/ws/${s}@aggTrade`;
}

export function binanceHostsRoundRobin(index: number): string {
  return BINANCE_WS_HOSTS[index % BINANCE_WS_HOSTS.length] ?? BINANCE_WS_HOSTS[0];
}
