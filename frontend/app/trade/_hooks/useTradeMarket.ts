import { useCallback, useEffect, useLayoutEffect, useRef, useState, type RefObject } from "react";
import { TradeChartEngine } from "../_chart/TradeChartEngine";
import {
  ensureCurrentPeriodTail,
  fetchBinanceLatestKline,
  fetchBinanceTickerPrice,
  fetchActiveOscillationStructures,
  fetchMarketKlinesBefore,
  fetchMarketKlines,
  mergeLatestOpenKlineFromExchange,
} from "../_lib/api";
import { binanceAggTradeWsUrl, binanceHostsRoundRobin, binanceKlineWsUrl } from "../_lib/binanceUrls";
import { WS_BASE } from "../_lib/config";
import { isRecord } from "../_lib/record";
import type { Interval } from "../_lib/types";
import type { StreamCandle } from "../_lib/types";

const TICK_SOURCE = process.env.NEXT_PUBLIC_TICK_SOURCE ?? "binance";
const KLINE_SOURCE = process.env.NEXT_PUBLIC_KLINE_SOURCE ?? "binance";

type WsStatus = "connecting" | "open" | "closed";

/**
 * 图表挂载、历史加载、后端 WS、币安 K/成交 WS 的统一编排。
 * 设计要点：引擎实例与 DOM 同生命周期；币安 K 连通时跳过后端 kline_stream，避免回退覆盖最后一根。
 */
export function useTradeMarket(
  symbol: string,
  interval: Interval,
  containerRef: RefObject<HTMLDivElement | null>,
  options: { showBoll?: boolean; showKeyPoints?: boolean } = {},
) {
  const engineRef = useRef<TradeChartEngine | null>(null);
  const snapshotRef = useRef<StreamCandle[]>([]);
  const loadingMoreRef = useRef(false);
  const showBoll = options.showBoll ?? true;
  const showKeyPoints = options.showKeyPoints ?? true;

  const [lastPrice, setLastPrice] = useState<number | null>(null);
  const [tickPrice, setTickPrice] = useState<number | null>(null);
  const [tickTimeMs, setTickTimeMs] = useState<number | null>(null);
  const [wsBackend, setWsBackend] = useState<WsStatus>("connecting");
  const [wsBinanceK, setWsBinanceK] = useState<WsStatus>(() => (KLINE_SOURCE === "binance" ? "connecting" : "closed"));
  const [wsBinanceT, setWsBinanceT] = useState<WsStatus>(() => (TICK_SOURCE === "binance" ? "connecting" : "closed"));
  const [binanceError, setBinanceError] = useState("");

  const binanceKlineLiveRef = useRef(false);
  const subRef = useRef<{ symbol: string; interval: Interval } | null>(null);

  const wsRef = useRef<WebSocket | null>(null);
  const connectWsRef = useRef<(() => void) | null>(null);
  const reconnectRef = useRef<{ timer: ReturnType<typeof setTimeout> | null; backoffMs: number }>({
    timer: null,
    backoffMs: 800,
  });

  const binanceKlineRef = useRef<WebSocket | null>(null);
  const binanceKConnectRef = useRef<(() => void) | null>(null);
  const binanceKReconnectRef = useRef<{ timer: ReturnType<typeof setTimeout> | null; backoffMs: number }>({
    timer: null,
    backoffMs: 800,
  });
  const binanceKManualCloseRef = useRef(false);
  const binanceKHostIdxRef = useRef(0);
  const binanceKLastConnectAtRef = useRef<number | null>(null);

  const binanceTradeRef = useRef<WebSocket | null>(null);
  const binanceTradeConnectRef = useRef<(() => void) | null>(null);
  const binanceTradeReconnectRef = useRef<{ timer: ReturnType<typeof setTimeout> | null; backoffMs: number }>({
    timer: null,
    backoffMs: 800,
  });
  const binanceTradeManualCloseRef = useRef(false);
  const binanceTradeHostIdxRef = useRef(0);
  const binanceTradeLastConnectAtRef = useRef<number | null>(null);

  /** 挂载 / 切换周期：重建引擎与图表 DOM */
  useLayoutEffect(() => {
    const el = containerRef.current;
    if (!el) return;

    const engine = new TradeChartEngine(symbol, interval, {
      onLastPrice: (p) => setLastPrice(p),
      onTick: (p, ts) => {
        setTickPrice(p);
        setTickTimeMs(ts);
      },
    }, { showBoll });
    engine.mount(el);
    engine.setNeedMoreHistoryHandler((beforeOpenTimeMs) => {
      const eng = engineRef.current;
      if (!eng) return;
      if (loadingMoreRef.current) return;
      const curr = snapshotRef.current;
      if (!curr.length) return;
      if (Number(curr[0]!.open_time_ms) !== Number(beforeOpenTimeMs)) return;
      loadingMoreRef.current = true;
      fetchMarketKlinesBefore(symbol, interval, beforeOpenTimeMs, 1500)
        .then((older) => {
          if (!older.length) return;
          const first = curr[0]!.open_time_ms;
          let end = older.length - 1;
          while (end >= 0 && older[end]!.open_time_ms >= first) end--;
          if (end < 0) return;
          const trimmed = older.slice(0, end + 1);
          const next = [...trimmed, ...curr];
          snapshotRef.current = next;
          eng.applySnapshot(next, { fitContent: false, keepRangeOffset: trimmed.length });
        })
        .finally(() => {
          loadingMoreRef.current = false;
        });
    });
    engineRef.current = engine;

    return () => {
      engine.dispose();
      engineRef.current = null;
    };
  }, [symbol, interval, containerRef, showBoll]);

  /** symbol / interval 变化时同步引擎上下文（新引擎已在 layout 里创建，此项兜底若以后复用单例） */
  useEffect(() => {
    engineRef.current?.setContext(symbol, interval);
  }, [symbol, interval]);

  const applySnapshotMerged = useCallback(
    async (rows: StreamCandle[]) => {
      const engine = engineRef.current;
      if (!engine) return;
      const [live, tickerPrice] = await Promise.all([
        fetchBinanceLatestKline(symbol, interval),
        fetchBinanceTickerPrice(symbol),
      ]);

      let merged = live ? mergeLatestOpenKlineFromExchange(rows, live) : [...rows];
      const seed =
        tickerPrice ??
        (merged.length > 0 ? Number(merged[merged.length - 1]!.close) : null);
      merged = ensureCurrentPeriodTail(merged, symbol, interval, seed);

      snapshotRef.current = merged;
      engine.applySnapshot(merged);
    },
    [interval, symbol],
  );

  const refreshOscillationLines = useCallback(async () => {
    const engine = engineRef.current;
    if (!engine) return;
    if (!showKeyPoints) {
      engine.setOscillationKeyPriceLines([]);
      return;
    }
    try {
      const rows = await fetchActiveOscillationStructures(symbol, ["4h", "1h", "30m", "15m", "5m", "1m"]);
      const levels: { interval: Interval; price: number }[] = [];
      for (const r of rows) {
        const itv = r.interval;
        const xs = Array.isArray(r.x_points) ? r.x_points : [];
        const ys = Array.isArray(r.y_points) ? r.y_points : [];
        const tail = (arr: { price: number }[]) => arr.slice(Math.max(0, arr.length - 6));
        for (const p of tail(xs)) levels.push({ interval: itv, price: Number(p.price) });
        for (const p of tail(ys)) levels.push({ interval: itv, price: Number(p.price) });
      }
      engine.setOscillationKeyPriceLines(levels);
    } catch {
      engine.setOscillationKeyPriceLines([]);
    }
  }, [showKeyPoints, symbol]);

  const loadHttpSnapshot = useCallback(async () => {
    try {
      const rows = await fetchMarketKlines(symbol, interval);
      await applySnapshotMerged(rows);
      await refreshOscillationLines();
    } catch {
      /* 静默；图表可能由 WS 引导 */
    }
  }, [applySnapshotMerged, interval, refreshOscillationLines, symbol]);

  useEffect(() => {
    void loadHttpSnapshot();
  }, [loadHttpSnapshot]);

  useEffect(() => {
    void refreshOscillationLines();
    if (!showKeyPoints) return;
    const timer = setInterval(() => void refreshOscillationLines(), 60_000);
    return () => clearInterval(timer);
  }, [refreshOscillationLines, showKeyPoints]);

  const sendSub = useCallback((ws: WebSocket, s: string, itv: Interval) => {
    ws.send(JSON.stringify({ type: "subscribe_kline", symbols: [s], intervals: [itv] }));
  }, []);

  const sendUnsub = useCallback((ws: WebSocket, s: string, itv: Interval) => {
    ws.send(JSON.stringify({ type: "unsubscribe_kline", symbols: [s], intervals: [itv] }));
  }, []);

  const connectBackendWs = useCallback(() => {
    if (wsRef.current && (wsRef.current.readyState === WebSocket.OPEN || wsRef.current.readyState === WebSocket.CONNECTING)) {
      return;
    }
    setWsBackend("connecting");
    const ws = new WebSocket(`${WS_BASE}/ws/klines`);
    wsRef.current = ws;

    ws.onopen = () => {
      setWsBackend("open");
      reconnectRef.current.backoffMs = 800;
      sendSub(ws, symbol, interval);
      subRef.current = { symbol, interval };
    };

    ws.onmessage = (evt) => {
      let obj: unknown;
      try {
        obj = JSON.parse(evt.data);
      } catch {
        return;
      }
      if (!isRecord(obj)) return;
      const t = obj["type"];
      if (t === "kline_snapshot") {
        if (obj["symbol"] !== symbol || obj["interval"] !== interval) return;
        const data = obj["data"];
        void applySnapshotMerged(Array.isArray(data) ? (data as StreamCandle[]) : []);
        return;
      }
      if (t === "kline_stream") {
        if (binanceKlineLiveRef.current) return;
        if (obj["symbol"] !== symbol || obj["interval"] !== interval) return;
        const data = obj["data"];
        if (!isRecord(data)) return;
        engineRef.current?.applyStream(data as unknown as StreamCandle);
        return;
      }
      if (t === "tick") {
        const s = String(obj["symbol"] ?? "").trim().toUpperCase();
        if (s !== symbol) return;
        const p = Number(obj["price"] ?? NaN);
        const ts = Number(obj["time_ms"] ?? Date.now());
        if (!Number.isFinite(p) || !Number.isFinite(ts)) return;
        engineRef.current?.applyTick(p, ts);
      }
    };

    ws.onclose = () => {
      setWsBackend("closed");
      if (reconnectRef.current.timer != null) clearTimeout(reconnectRef.current.timer);
      const next = Math.min(reconnectRef.current.backoffMs, 8000);
      reconnectRef.current.backoffMs = Math.min(next * 2, 8000);
      reconnectRef.current.timer = setTimeout(() => connectWsRef.current?.(), next);
    };

    ws.onerror = () => setWsBackend("closed");
  }, [applySnapshotMerged, interval, sendSub, symbol]);

  useEffect(() => {
    connectWsRef.current = connectBackendWs;
  }, [connectBackendWs]);

  useEffect(() => {
    const t = setTimeout(() => connectBackendWs(), 0);
    const rec = reconnectRef.current;
    return () => {
      clearTimeout(t);
      if (rec.timer != null) clearTimeout(rec.timer);
      rec.timer = null;
      rec.backoffMs = 800;
      const w = wsRef.current;
      wsRef.current = null;
      if (w) w.close();
    };
  }, [connectBackendWs]);

  useEffect(() => {
    const w = wsRef.current;
    if (!w || w.readyState !== WebSocket.OPEN) return;
    const prev = subRef.current;
    if (prev && (prev.symbol !== symbol || prev.interval !== interval)) {
      sendUnsub(w, prev.symbol, prev.interval);
    }
    sendSub(w, symbol, interval);
    subRef.current = { symbol, interval };
  }, [interval, sendSub, sendUnsub, symbol]);

  const connectBinanceKline = useCallback(() => {
    if (
      binanceKlineRef.current &&
      (binanceKlineRef.current.readyState === WebSocket.OPEN || binanceKlineRef.current.readyState === WebSocket.CONNECTING)
    ) {
      return;
    }
    setWsBinanceK("connecting");
    setBinanceError("");
    binanceKManualCloseRef.current = false;
    const host = binanceHostsRoundRobin(binanceKHostIdxRef.current);
    binanceKLastConnectAtRef.current = Date.now();
    const ws = new WebSocket(binanceKlineWsUrl(host, symbol, interval));
    binanceKlineRef.current = ws;

    ws.onopen = () => {
      setWsBinanceK("open");
      binanceKlineLiveRef.current = true;
      binanceKReconnectRef.current.backoffMs = 800;
    };

    ws.onmessage = (evt) => {
      let obj: unknown;
      try {
        obj = JSON.parse(evt.data);
      } catch {
        return;
      }
      if (!isRecord(obj)) return;
      if (obj["e"] !== "kline") return;
      const k = obj["k"];
      if (!isRecord(k)) return;
      const s = String(obj["s"] ?? "").trim().toUpperCase();
      if (s !== symbol) return;
      const candle: StreamCandle = {
        symbol: s,
        is_final: Boolean(k["x"]),
        open_time_ms: Number(k["t"]),
        close_time_ms: Number(k["T"]),
        open: String(k["o"] ?? "0"),
        high: String(k["h"] ?? "0"),
        low: String(k["l"] ?? "0"),
        close: String(k["c"] ?? "0"),
        volume: String(k["v"] ?? "0"),
        amount: String(k["q"] ?? "0"),
        num_trades: Number(k["n"] ?? 0),
        buy_volume: String(k["V"] ?? "0"),
        buy_amount: String(k["Q"] ?? "0"),
      };
      if (!Number.isFinite(candle.open_time_ms) || candle.open_time_ms <= 0) return;
      engineRef.current?.applyStream(candle);
    };

    ws.onclose = (ce) => {
      setWsBinanceK("closed");
      binanceKlineLiveRef.current = false;
      if (!binanceKManualCloseRef.current) {
        const t0 = binanceKLastConnectAtRef.current;
        if (t0 != null && Date.now() - t0 < 2500) binanceKHostIdxRef.current += 1;
        if (ce.code || ce.reason) setBinanceError(`BINANCE_KLINE_WS_CLOSE ${ce.code} ${ce.reason}`.trim());
      }
      if (binanceKReconnectRef.current.timer != null) clearTimeout(binanceKReconnectRef.current.timer);
      const next = Math.min(binanceKReconnectRef.current.backoffMs, 8000);
      binanceKReconnectRef.current.backoffMs = Math.min(next * 2, 8000);
      binanceKReconnectRef.current.timer = setTimeout(() => binanceKConnectRef.current?.(), next);
    };

    ws.onerror = (e) => {
      setWsBinanceK("closed");
      binanceKlineLiveRef.current = false;
      setBinanceError(String((e as unknown as { message?: string }).message ?? "BINANCE_KLINE_WS_ERROR"));
    };
  }, [interval, symbol]);

  useEffect(() => {
    binanceKConnectRef.current = connectBinanceKline;
  }, [connectBinanceKline]);

  useEffect(() => {
    if (KLINE_SOURCE !== "binance") {
      binanceKlineLiveRef.current = false;
      const w = binanceKlineRef.current;
      binanceKlineRef.current = null;
      if (w) {
        binanceKManualCloseRef.current = true;
        w.close();
      }
      return undefined;
    }
    const t = setTimeout(() => connectBinanceKline(), 0);
    const rec = binanceKReconnectRef.current;
    return () => {
      if (KLINE_SOURCE !== "binance") return;
      clearTimeout(t);
      if (rec.timer != null) clearTimeout(rec.timer);
      rec.timer = null;
      rec.backoffMs = 800;
      const w = binanceKlineRef.current;
      binanceKlineRef.current = null;
      if (w) {
        binanceKManualCloseRef.current = true;
        w.close();
      }
    };
  }, [connectBinanceKline, interval, symbol]);

  const connectBinanceTrade = useCallback(() => {
    if (
      binanceTradeRef.current &&
      (binanceTradeRef.current.readyState === WebSocket.OPEN || binanceTradeRef.current.readyState === WebSocket.CONNECTING)
    ) {
      return;
    }
    setWsBinanceT("connecting");
    setBinanceError("");
    binanceTradeManualCloseRef.current = false;
    const host = binanceHostsRoundRobin(binanceTradeHostIdxRef.current);
    binanceTradeLastConnectAtRef.current = Date.now();
    const ws = new WebSocket(binanceAggTradeWsUrl(host, symbol));
    binanceTradeRef.current = ws;

    ws.onopen = () => {
      setWsBinanceT("open");
      binanceTradeReconnectRef.current.backoffMs = 800;
    };

    ws.onmessage = (evt) => {
      let obj: unknown;
      try {
        obj = JSON.parse(evt.data);
      } catch {
        return;
      }
      if (!isRecord(obj)) return;
      const msgEvent = obj["e"];
      if (msgEvent !== "trade" && msgEvent !== "aggTrade") return;
      const s = String(obj["s"] ?? "").trim().toUpperCase();
      if (s !== symbol) return;
      const price = Number(obj["p"] ?? NaN);
      const ts = Number(obj["T"] ?? NaN);
      if (!Number.isFinite(price) || !Number.isFinite(ts)) return;
      engineRef.current?.applyTick(price, ts);
    };

    ws.onclose = (ce) => {
      setWsBinanceT("closed");
      if (!binanceTradeManualCloseRef.current) {
        const t0 = binanceTradeLastConnectAtRef.current;
        if (t0 != null && Date.now() - t0 < 2500) binanceTradeHostIdxRef.current += 1;
        if (ce.code || ce.reason) setBinanceError(`BINANCE_TRADE_WS_CLOSE ${ce.code} ${ce.reason}`.trim());
      }
      if (binanceTradeReconnectRef.current.timer != null) clearTimeout(binanceTradeReconnectRef.current.timer);
      const next = Math.min(binanceTradeReconnectRef.current.backoffMs, 8000);
      binanceTradeReconnectRef.current.backoffMs = Math.min(next * 2, 8000);
      binanceTradeReconnectRef.current.timer = setTimeout(() => binanceTradeConnectRef.current?.(), next);
    };

    ws.onerror = (e) => {
      setWsBinanceT("closed");
      setBinanceError(String((e as unknown as { message?: string }).message ?? "BINANCE_TRADE_WS_ERROR"));
    };
  }, [symbol]);

  useEffect(() => {
    binanceTradeConnectRef.current = connectBinanceTrade;
  }, [connectBinanceTrade]);

  useEffect(() => {
    if (TICK_SOURCE !== "binance") return;
    const t = setTimeout(() => connectBinanceTrade(), 0);
    const rec = binanceTradeReconnectRef.current;
    return () => {
      if (TICK_SOURCE !== "binance") return;
      clearTimeout(t);
      if (rec.timer != null) clearTimeout(rec.timer);
      rec.timer = null;
      rec.backoffMs = 800;
      const w = binanceTradeRef.current;
      binanceTradeRef.current = null;
      if (w) {
        binanceTradeManualCloseRef.current = true;
        w.close();
      }
    };
  }, [connectBinanceTrade, symbol]);

  /** 成交 WS 不通时用 ticker 轮询兜底 tick */
  useEffect(() => {
    if (TICK_SOURCE !== "binance") return;
    if (wsBinanceT !== "closed") return;
    let stopped = false;
    const tick = async () => {
      try {
        const res = await fetch(
          `https://fapi.binance.com/fapi/v1/ticker/price?symbol=${encodeURIComponent(symbol)}`,
          { cache: "no-store" },
        );
        if (!res.ok) return;
        const obj = (await res.json()) as unknown;
        if (!isRecord(obj)) return;
        const p = Number(obj["price"] ?? NaN);
        if (!Number.isFinite(p)) return;
        if (stopped) return;
        engineRef.current?.applyTick(p, Date.now());
      } catch {
        /* ignore */
      }
    };
    void tick();
    const timer = setInterval(() => void tick(), 2000);
    return () => {
      stopped = true;
      clearInterval(timer);
    };
  }, [symbol, wsBinanceT]);

  return {
    lastPrice,
    tickPrice,
    tickTimeMs,
    wsBackend,
    wsBinanceK,
    wsBinanceT,
    binanceError,
  };
}
