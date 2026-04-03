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
  type LineWidth,
  type UTCTimestamp,
} from "lightweight-charts";
import { BOLL_PERIOD } from "../_lib/config";
import { computeBollFromCloses } from "../_lib/boll";
import { chartTickMarkFormatterBeijing, chartTimeFormatterBeijing } from "../_lib/chartBeijingTime";
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
  private showBoll: boolean;

  private chart: IChartApi | null = null;
  private candle: ISeriesApi<"Candlestick"> | null = null;
  private vol: ISeriesApi<"Histogram"> | null = null;
  private lineUp: ISeriesApi<"Line"> | null = null;
  private lineMb: ISeriesApi<"Line"> | null = null;
  private lineDn: ISeriesApi<"Line"> | null = null;
  private priceLine: IPriceLine | null = null;
  private oscLines: IPriceLine[] = [];
  private onNeedMoreHistory: ((beforeOpenTimeMs: number) => void) | null = null;
  private firstOpenTimeMs: number | null = null;
  private lastNeedMoreAt: number = 0;
  private visibleRangeHandler: ((range: { from: number; to: number } | null) => void) | null = null;
  private ro: ResizeObserver | null = null;

  private lastCandle: LastCandle | null = null;
  private lastSeriesTime: number | null = null;
  private closeHistory: number[] = [];

  private lastPriceRaf: number | null = null;
  private pendingLastPrice: number | null = null;

  constructor(
    symbol: string,
    interval: Interval,
    callbacks: ChartDisplayCallbacks = {},
    options: { showBoll?: boolean } = {},
  ) {
    this.symbol = symbol;
    this.interval = interval;
    this.callbacks = callbacks;
    this.showBoll = options.showBoll ?? true;
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
        tickMarkFormatter: chartTickMarkFormatterBeijing,
        /** 最后一根 K 与图表右边缘保留空隙（像素），避免贴边 */
        rightOffsetPixels: 96,
      },
      grid: {
        vertLines: { color: "rgba(255,255,255,0.06)" },
        horzLines: { color: "rgba(255,255,255,0.06)" },
      },
      crosshair: { mode: CrosshairMode.Normal },
      localization: {
        locale: "zh-CN",
        dateFormat: "yyyy-MM-dd",
        timeFormatter: chartTimeFormatterBeijing,
      },
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

    vol.priceScale().applyOptions({ scaleMargins: { top: 0.82, bottom: 0.0 } });

    this.chart = chart;
    this.candle = candle;
    this.vol = vol;
    if (this.showBoll) {
      const bollOpts = { lineWidth: 1 as const, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false };
      this.lineUp = chart.addSeries(LineSeries, { ...bollOpts, color: "rgba(137,207,240,0.95)" });
      this.lineMb = chart.addSeries(LineSeries, { ...bollOpts, color: "rgba(220,220,220,0.7)" });
      this.lineDn = chart.addSeries(LineSeries, { ...bollOpts, color: "rgba(255,127,80,0.95)" });
    } else {
      this.lineUp = null;
      this.lineMb = null;
      this.lineDn = null;
    }

    this.ro = new ResizeObserver((entries) => {
      const entry = entries[0];
      if (!entry) return;
      chart.applyOptions({ width: Math.floor(entry.contentRect.width) });
    });
    this.ro.observe(container);

    this.visibleRangeHandler = (range) => {
      if (!range) return;
      const cb = this.onNeedMoreHistory;
      const before = this.firstOpenTimeMs;
      if (!cb || before == null) return;
      if (range.from > 10) return;
      const now = Date.now();
      if (now - this.lastNeedMoreAt < 800) return;
      this.lastNeedMoreAt = now;
      cb(before);
    };
    chart.timeScale().subscribeVisibleLogicalRangeChange(this.visibleRangeHandler);
  }

  dispose() {
    if (this.chart && this.visibleRangeHandler) {
      try {
        this.chart.timeScale().unsubscribeVisibleLogicalRangeChange(this.visibleRangeHandler);
      } catch {
        /* ignore */
      }
    }
    this.visibleRangeHandler = null;
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
    this.oscLines = [];
    this.onNeedMoreHistory = null;
    this.firstOpenTimeMs = null;
    this.lastNeedMoreAt = 0;
    this.lastCandle = null;
    this.lastSeriesTime = null;
    this.closeHistory = [];
    if (this.lastPriceRaf != null) {
      cancelAnimationFrame(this.lastPriceRaf);
      this.lastPriceRaf = null;
    }
    this.pendingLastPrice = null;
  }

  private removeLastPriceLine() {
    const series = this.candle;
    if (!series) return;
    const pl = this.priceLine;
    if (pl) {
      try {
        series.removePriceLine(pl);
      } catch {
        /* ignore */
      }
    }
    this.priceLine = null;
  }

  private removeOscLines() {
    const series = this.candle;
    if (!series) return;
    for (const pl of this.oscLines) {
      try {
        series.removePriceLine(pl);
      } catch {
        /* ignore */
      }
    }
    this.oscLines = [];
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
    this.removeLastPriceLine();
    this.priceLine = series.createPriceLine({
      price,
      color: "rgba(255,215,0,0.9)",
      lineWidth: 1,
      lineStyle: LineStyle.Solid,
      axisLabelVisible: true,
      title: "",
    });
  }

  setNeedMoreHistoryHandler(cb: ((beforeOpenTimeMs: number) => void) | null) {
    this.onNeedMoreHistory = cb;
  }

  applySnapshot(candles: StreamCandle[], options: { fitContent?: boolean; keepRangeOffset?: number } = {}) {
    const series = this.candle;
    if (!series) return;

    this.removeLastPriceLine();

    const cData = candles.map((c) => ({
      time: Math.floor(c.open_time_ms / 1000) as UTCTimestamp,
      open: Number(c.open),
      high: Number(c.high),
      low: Number(c.low),
      close: Number(c.close),
    }));

    const keep = options.fitContent === false ? this.chart?.timeScale().getVisibleLogicalRange() ?? null : null;
    series.setData(cData);
    if (keep && this.chart) {
      try {
        const shift = Number(options.keepRangeOffset ?? 0);
        if (Number.isFinite(shift) && shift !== 0) {
          this.chart.timeScale().setVisibleLogicalRange({ from: keep.from + shift, to: keep.to + shift });
        } else {
          this.chart.timeScale().setVisibleLogicalRange(keep);
        }
      } catch {
        /* ignore */
      }
    }

    if (cData.length > 0) {
      const last = cData[cData.length - 1]!;
      this.lastCandle = last;
      this.lastSeriesTime = Number(last.time);
      this.callbacks.onLastPrice?.(last.close);
      this.updatePriceLine(last.close);
      this.firstOpenTimeMs = candles[0] ? Number(candles[0].open_time_ms) : null;
    } else {
      this.lastCandle = null;
      this.lastSeriesTime = null;
      this.firstOpenTimeMs = null;
    }

    const closes: number[] = [];
    if (this.showBoll && this.lineUp && this.lineMb && this.lineDn) {
      const upData: { time: UTCTimestamp; value: number }[] = [];
      const mbData: { time: UTCTimestamp; value: number }[] = [];
      const dnData: { time: UTCTimestamp; value: number }[] = [];
      for (const c of cData) {
        closes.push(c.close);
        const b = computeBollFromCloses(closes);
        if (!b) continue;
        upData.push({ time: c.time, value: b.up });
        mbData.push({ time: c.time, value: b.mb });
        dnData.push({ time: c.time, value: b.dn });
      }
      this.lineUp.setData(upData);
      this.lineMb.setData(mbData);
      this.lineDn.setData(dnData);
    } else {
      for (const c of cData) closes.push(c.close);
    }
    this.closeHistory = closes;

    const vData = candles.map((c) => ({
      time: Math.floor(c.open_time_ms / 1000) as UTCTimestamp,
      value: Number(c.volume ?? 0),
      color: Number(c.close) >= Number(c.open) ? "rgba(14,203,129,0.35)" : "rgba(246,70,93,0.35)",
    }));
    this.vol?.setData(vData);

    if (options.fitContent !== false) {
      this.chart?.timeScale().fitContent();
    }
  }

  setOscillationKeyPriceLines(input: { interval: Interval; price: number }[]) {
    const series = this.candle;
    if (!series) return;
    this.removeOscLines();

    const styleByInterval: Record<Interval, { color: string; width: LineWidth }> = {
      "4h": { color: "rgba(186,85,211,1)", width: 4 },
      "1h": { color: "rgba(64,156,255,1)", width: 3 },
      "30m": { color: "rgba(14,203,129,0.95)", width: 3 },
      "15m": { color: "rgba(255,215,0,0.95)", width: 2 },
      "5m": { color: "rgba(255,127,80,0.9)", width: 2 },
      "1m": { color: "rgba(246,70,93,0.9)", width: 2 },
    };

    const order: Interval[] = ["4h", "1h", "30m", "15m", "5m", "1m"];
    const grouped = new Map<Interval, number[]>();
    for (const itv of order) grouped.set(itv, []);
    for (const r of input) {
      if (!Number.isFinite(r.price)) continue;
      const arr = grouped.get(r.interval);
      if (!arr) continue;
      arr.push(r.price);
    }

    for (const itv of order) {
      const prices = grouped.get(itv) ?? [];
      const seen = new Set<string>();
      for (const p of prices) {
        const key = p.toFixed(6);
        if (seen.has(key)) continue;
        seen.add(key);
        const st = styleByInterval[itv];
        const pl = series.createPriceLine({
          price: p,
          color: st.color,
          lineWidth: st.width,
          lineStyle: LineStyle.Dashed,
          axisLabelVisible: true,
          title: itv,
        });
        this.oscLines.push(pl);
      }
    }
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
      if (!this.showBoll || !this.lineUp || !this.lineMb || !this.lineDn) {
        if (this.vol && c.volume != null) {
          this.vol.update({
            time: t,
            value: Number(c.volume ?? 0),
            color: Number(c.close) >= Number(c.open) ? "rgba(14,203,129,0.35)" : "rgba(246,70,93,0.35)",
          });
        }
        return;
      }
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
        this.lineUp.update({ time: t, value: b.up });
        this.lineMb.update({ time: t, value: b.mb });
        this.lineDn.update({ time: t, value: b.dn });
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
