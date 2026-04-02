import type { Interval } from "./types";

export function intervalMs(itv: Interval): number {
  if (itv === "1m") return 60_000;
  if (itv === "5m") return 5 * 60_000;
  if (itv === "15m") return 15 * 60_000;
  if (itv === "30m") return 30 * 60_000;
  if (itv === "1h") return 60 * 60_000;
  return 4 * 60 * 60_000;
}
