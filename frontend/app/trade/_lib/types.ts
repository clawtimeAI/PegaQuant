export type Interval = "1m" | "5m" | "15m" | "30m" | "1h" | "4h";

export type StreamCandle = {
  symbol: string;
  is_final?: boolean;
  open_time_ms: number;
  close_time_ms: number;
  open_time?: string;
  close_time?: string;
  open: number | string;
  high: number | string;
  low: number | string;
  close: number | string;
  volume?: number | string;
  amount?: number | string;
  num_trades?: number | string;
  buy_volume?: number | string;
  buy_amount?: number | string;
  boll_up?: number | null;
  boll_mb?: number | null;
  boll_dn?: number | null;
};

export type TradingAccount = {
  id: number;
  account_name: string;
  account_group: "SHORT" | "MID" | "LONG";
  api_key: string;
  is_active: boolean;
};

export type PositionRisk = {
  symbol: string;
  positionAmt: string;
  entryPrice?: string;
  markPrice?: string;
  unrealizedProfit?: string;
  leverage?: string;
  liquidationPrice?: string;
  marginType?: string;
};

export type WsConnState = "connecting" | "open" | "closed";
