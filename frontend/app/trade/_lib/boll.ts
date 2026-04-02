import { BOLL_MULT, BOLL_PERIOD } from "./config";

export function computeBollFromCloses(closes: number[]): { up: number; mb: number; dn: number } | null {
  if (closes.length < BOLL_PERIOD) return null;
  const window = closes.slice(-BOLL_PERIOD);
  const mb = window.reduce((a, b) => a + b, 0) / BOLL_PERIOD;
  const varr = window.reduce((a, x) => a + (x - mb) ** 2, 0) / BOLL_PERIOD;
  const sd = Math.sqrt(varr);
  return { mb, up: mb + BOLL_MULT * sd, dn: mb - BOLL_MULT * sd };
}
