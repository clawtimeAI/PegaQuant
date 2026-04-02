import {
  CandlestickSeries,
  CrosshairMode,
  HistogramSeries,
  LineSeries,
  LineStyle,
  createChart,
  type IChartApi,
  type IPriceLine,
  type ISeriesApi,
  type UTCTimestamp,
} from "lightweight-charts";
import { BOLL_PERIOD } from "../_lib/config";
import { computeBollFromCloses } from "../_lib/boll";
import { intervalMs } from "../_lib/intervalMs";
import type { Interval } from "../_lib/types";
import type { StreamCandle } from "../_lib/types";

type LastCandle = {
  time: UTCTimestamp;
  open: number;
  high: number;
  low: number;
  close: number;
};

export type ChartDisplayCallbacks = {
  onLastPrice?: (price: number) => void;
  onTick?: (price: number, timeMs: number) => void;
};

/** 封装 lightweight-charts 与 K 线内存状态，与 React/WebSocket 解耦 */
export class TradeChartEngine {
  private symbol: string;
  private interval: Interval;
  private callbacks: ChartDisplayCallbacks;

  private chart: IChartApi | null = null;
  private candle: ISeriesApi<"Candlestick"> | null = null;
  private vol: ISeriesApi<"Histogram"> | null = null;
  private lineUp: ISeriesApi<"Line"> | null = null;
  private lineMb: ISeriesApi<"Line"> | null = null;
  private lineDn: ISeriesApi<"Line"> | null = null;
  private priceLine: IPriceLine | null = null;
  private ro: ResizeObserver | null = null;

  private lastCandle: LastCandle | null = null;
  private lastSeriesTime: number | null = null;
  private closeHistory: number[] = [];

  private lastPriceRaf: number | null = null;
  private pendingLastPrice: number | null = null;

  constructor(symbol: string, interval: Interval, callbacks: ChartDisplayCallbacks = {}) {
    this.symbol = symbol;
    this.interval = interval;
    this.callbacks = callbacks;
  }

  setContext(symbol: string, interval: Interval) {
    this.symbol = symbol;
    this.interval = interval;
  }

  mount(container: HTMLElement) {
    this.dispose();
    const chart = createChart(container, {
      height: 560,
      layout: {
        background: { color: "#0b0e11" },
        textColor: "#eaecef",
        fontFamily: "ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, Noto Sans",
        fontSize: 12,
      },
      rightPriceScale: {
        borderVisible: false,
        scaleMargins: { top: 0.12, bottom: 0.22 },
      },
      timeScale: {
        borderVisible: false,
        timeVisible: true,
        secondsVisible: false,
      },
      grid: {
        vertLines: { color: "rgba(255,255,255,0.06)" },
        horzLines: { color: "rgba(255,255,255,0.06)" },
      },
      crosshair: { mode: CrosshairMode.Magnet },
      localization: { locale: "zh-CN" },
    });

    const candle = chart.addSeries(CandlestickSeries, {
      upColor: "#0ecb81",
      downColor: "#f6465d",
      borderVisible: false,
      wickUpColor: "#0ecb81",
      wickDownColor: "#f6465d",
      lastValueVisible: false,
      priceLineVisible: false,
    });

    const vol = chart.addSeries(HistogramSeries, {
      color: "rgba(255,255,255,0.18)",
      priceScaleId: "",
      priceFormat: { type: "volume" },
    });

    const up = chart.addSeries(LineSeries, { color: "rgba(137,207,240,0.95)", lineWidth: 1 });
    const mb = chart.addSeries(LineSeries, { color: "rgba(220,220,220,0.7)", lineWidth: 1 });
    const dn = chart.addSeries(LineSeries, { color: "rgba(255,127,80,0.95)", lineWidth: 1 });

    vol.priceScale().applyOptions({ scaleMargins: { top: 0.82, bottom: 0.0 } });

    this.chart = chart;
    this.candle = candle;
    this.vol = vol;
    this.lineUp = up;
    this.lineMb = mb;
    this.lineDn = dn;

    this.ro = new ResizeObserver((entries) => {
      const entry = entries[0];
      if (!entry) return;
      chart.applyOptions({ width: Math.floor(entry.contentRect.width) });
      chart.timeScale().fitContent();
    });
    this.ro.observe(container);
  }

  dispose() {
    this.ro?.disconnect();
    this.ro = null;
    this.chart?.remove();
    this.chart = null;
    this.candle = null;
    this.vol = null;
    this.lineUp = null;
    this.lineMb = null;
    this.lineDn = null;
    this.priceLine = null;
    this.lastCandle = null;
    this.lastSeriesTime = null;
    this.closeHistory = [];
    if (this.lastPriceRaf != null) {
      cancelAnimationFrame(this.lastPriceRaf);
      this.lastPriceRaf = null;
    }
    this.pendingLastPrice = null;
  }

  private removeAllPriceLines() {
    const series = this.candle;
    if (!series) return;
    for (const pl of series.priceLines()) {
      try {
        series.removePriceLine(pl);
      } catch {
        /* ignore */
      }
    }
    this.priceLine = null;
  }

  private scheduleLastPriceUi(price: number) {
    this.pendingLastPrice = price;
    if (this.lastPriceRaf != null) return;
    this.lastPriceRaf = requestAnimationFrame(() => {
      this.lastPriceRaf = null;
      const p = this.pendingLastPrice;
      this.pendingLastPrice = null;
      if (p != null) this.callbacks.onLastPrice?.(p);
    });
  }

  private updatePriceLine(price: number) {
    const series = this.candle;
    if (!series || !Number.isFinite(price)) return;
    const existing = this.priceLine;
    if (existing) {
      try {
        existing.applyOptions({ price, title: "" });
        return;
      } catch {
        this.priceLine = null;
      }
    }
    this.removeAllPriceLines();
    this.priceLine = series.createPriceLine({
      price,
      color: "rgba(255,215,0,0.9)",
      lineWidth: 1,
      lineStyle: LineStyle.Dashed,
      axisLabelVisible: true,
      title: "",
    });
  }

  applySnapshot(candles: StreamCandle[]) {
    const series = this.candle;
    if (!series) return;

    this.removeAllPriceLines();

    const cData = candles.map((c) => ({
      time: Math.floor(c.open_time_ms / 1000) as UTCTimestamp,
      open: Number(c.open),
      high: Number(c.high),
      low: Number(c.low),
      close: Number(c.close),
    }));

    series.setData(cData);

    if (cData.length > 0) {
      const last = cData[cData.length - 1]!;
      this.lastCandle = last;
      this.lastSeriesTime = Number(last.time);
      this.callbacks.onLastPrice?.(last.close);
      this.updatePriceLine(last.close);
    } else {
      this.lastCandle = null;
      this.lastSeriesTime = null;
    }

    const upData: { time: UTCTimestamp; value: number }[] = [];
    const mbData: { time: UTCTimestamp; value: number }[] = [];
    const dnData: { time: UTCTimestamp; value: number }[] = [];
    const closes: number[] = [];
    for (const c of cData) {
      closes.push(c.close);
      const b = computeBollFromCloses(closes);
      if (!b) continue;
      upData.push({ time: c.time, value: b.up });
      mbData.push({ time: c.time, value: b.mb });
      dnData.push({ time: c.time, value: b.dn });
    }
    this.closeHistory = closes;
    this.lineUp?.setData(upData);
    this.lineMb?.setData(mbData);
    this.lineDn?.setData(dnData);

    const vData = candles.map((c) => ({
      time: Math.floor(c.open_time_ms / 1000) as UTCTimestamp,
      value: Number(c.volume ?? 0),
      color: Number(c.close) >= Number(c.open) ? "rgba(14,203,129,0.35)" : "rgba(246,70,93,0.35)",
    }));
    this.vol?.setData(vData);

    this.chart?.timeScale().fitContent();
  }

  applyStream(c: StreamCandle) {
    const series = this.candle;
    if (!series) return;

    const t = Math.floor(c.open_time_ms / 1000) as UTCTimestamp;
    if (this.lastSeriesTime != null && Number(t) < this.lastSeriesTime) return;

    const next = {
      time: t,
      open: Number(c.open),
      high: Number(c.high),
      low: Number(c.low),
      close: Number(c.close),
    };

    const last = this.lastCandle;
    if (last && next.time < last.time) return;

    const isNewCandle = !last || next.time > last.time;

    try {
      const hasBars = series.data().length > 0;
      if (last && next.time === last.time) {
        this.lastCandle = next;
        if (!hasBars) series.setData([next]);
        else series.update(next);
      } else if (!last || next.time > last.time) {
        this.lastCandle = next;
        if (!hasBars) series.setData([next]);
        else series.update(next);
      }
      this.lastSeriesTime = Number(next.time);
    } catch {
      return;
    }

    this.scheduleLastPriceUi(next.close);
    this.updatePriceLine(next.close);

    try {
      const closes = this.closeHistory;
      if (last && next.time === last.time) {
        if (closes.length > 0) closes[closes.length - 1] = next.close;
      } else if (!last || next.time > last.time) {
        closes.push(next.close);
        if (closes.length > BOLL_PERIOD + 200) {
          closes.splice(0, closes.length - (BOLL_PERIOD + 200));
        }
      }
      const b = computeBollFromCloses(closes);
      if (b) {
        this.lineUp?.update({ time: t, value: b.up });
        this.lineMb?.update({ time: t, value: b.mb });
        this.lineDn?.update({ time: t, value: b.dn });
      }

      if (this.vol && c.volume != null) {
        this.vol.update({
          time: t,
          value: Number(c.volume ?? 0),
          color: Number(c.close) >= Number(c.open) ? "rgba(14,203,129,0.35)" : "rgba(246,70,93,0.35)",
        });
      }
    } catch {
      /* 布林/量失败不影响 K 线 */
    }

    if (isNewCandle) {
      this.chart?.timeScale().scrollToRealTime();
    }
  }

  /** 用成交时间戳推进当前周期 OHLC（与币安 K 流互补） */
  applyTick(price: number, tsMs: number) {
    this.callbacks.onTick?.(price, tsMs);

    const series = this.candle;
    if (!series) return;

    const itvMs = intervalMs(this.interval);
    let last = this.lastCandle;

    if (!last) {
      const tail = series.data().at(-1);
      if (tail && typeof tail === "object" && "open" in tail && "high" in tail) {
        const o = tail as { time: UTCTimestamp; open: number; high: number; low: number; close: number };
        last = { time: o.time, open: o.open, high: o.high, low: o.low, close: o.close };
        this.lastCandle = last;
        this.lastSeriesTime = Number(o.time);
      } else {
        const candleStartMs = Math.floor(tsMs / itvMs) * itvMs;
        this.applyStream({
          symbol: this.symbol,
          open_time_ms: candleStartMs,
          close_time_ms: candleStartMs,
          open: price,
          high: price,
          low: price,
          close: price,
        });
        return;
      }
    }

    const candleStartSec = (Math.floor(tsMs / itvMs) * itvMs) / 1000;
    if (candleStartSec < Number(last.time)) return;

    const merged =
      candleStartSec === Number(last.time)
        ? {
            time: last.time,
            open: last.open,
            high: Math.max(last.high, price),
            low: Math.min(last.low, price),
            close: price,
          }
        : {
            time: candleStartSec as UTCTimestamp,
            open: price,
            high: price,
            low: price,
            close: price,
          };

    this.applyStream({
      symbol: this.symbol,
      open_time_ms: Number(merged.time) * 1000,
      close_time_ms: Number(merged.time) * 1000,
      open: merged.open,
      high: merged.high,
      low: merged.low,
      close: merged.close,
    });
  }
}
