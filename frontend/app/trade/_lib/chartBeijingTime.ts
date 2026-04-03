import { TickMarkType, type Time } from "lightweight-charts";

/** K 线数据时间为 UTC Unix 秒；轴标签统一按东八区显示 */
const TZ = "Asia/Shanghai";

function asUtcMs(time: Time): number | null {
  if (typeof time === "number") return time * 1000;
  return null;
}

/** 十字光标等：完整日期时间（北京时间） */
export function chartTimeFormatterBeijing(time: Time): string {
  const ms = asUtcMs(time);
  if (ms == null) return String(time);
  return new Intl.DateTimeFormat("zh-CN", {
    timeZone: TZ,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(ms));
}

/**
 * 底部时间轴刻度（需控制长度，避免重叠；时间为 UTC 点换算后的北京时间）
 */
export function chartTickMarkFormatterBeijing(time: Time, tickMarkType: TickMarkType): string | null {
  const ms = asUtcMs(time);
  if (ms == null) return null;
  const d = new Date(ms);
  const base = { timeZone: TZ, hour12: false } as const;

  switch (tickMarkType) {
    case TickMarkType.Year:
      return new Intl.DateTimeFormat("zh-CN", { ...base, year: "numeric" }).format(d);
    case TickMarkType.Month:
      return new Intl.DateTimeFormat("zh-CN", { ...base, year: "2-digit", month: "2-digit" }).format(d);
    case TickMarkType.DayOfMonth:
      return new Intl.DateTimeFormat("zh-CN", { ...base, month: "2-digit", day: "2-digit" }).format(d);
    case TickMarkType.TimeWithSeconds:
      return new Intl.DateTimeFormat("zh-CN", { ...base, hour: "2-digit", minute: "2-digit", second: "2-digit" }).format(d);
    case TickMarkType.Time:
    default:
      return new Intl.DateTimeFormat("zh-CN", { ...base, hour: "2-digit", minute: "2-digit" }).format(d);
  }
}
