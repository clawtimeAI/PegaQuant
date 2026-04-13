"""
布林带震荡策略 v2
===============
修复的Bug：
  1. 止损必须强制执行，保证金归零即平仓（防止数字溢出）
  2. 信号去重：同一根K线只触发一次
  3. 长期账户不应在1m步进时反复检查（只在对应周期K线收盘时才检查）

新增过滤条件（"什么时候不该做"）：
  A. mouth_state==1（开口）才入场
  B. 布林带宽度（bw/boll_mb）过滤：带宽太窄（<阈值）跳过
  C. 震荡结构体确认：必须处于 a_point 已形成的有效震荡区间内
  D. 价格必须在带内（close在上下轨之间）才入场，排除趋势行情
  E. 连续止损冷却：连续2次止损后暂停该账户N根K线
"""
from __future__ import annotations
from dataclasses import dataclass, field
from typing import Optional, List
from enum import Enum
import pandas as pd
import numpy as np


class Direction(str, Enum):
    LONG  = "LONG"
    SHORT = "SHORT"


class AccountType(str, Enum):
    SHORT = "short"   # 1m, 5m
    MID   = "mid"     # 15m, 30m
    LONG  = "long"    # 1h, 4h


ACCOUNT_INTERVALS = {
    AccountType.SHORT: ["1m", "5m"],
    AccountType.MID:   ["15m"],
    AccountType.LONG:  ["1h"],
}

# 仓位参数
POSITION_RATIO = 0.50
LEVERAGE       = 20
TAKER_FEE      = 0.0004

# 连续止损后冷却的K线数（针对该账户主周期）
COOLDOWN_BARS_AFTER_SL = 10

# 布林带宽度阈值（bw = (up-dn)/mb），低于此值认为带太窄不做
BW_MIN = {
    "1m":  0.008,
    "5m":  0.010,
    "15m": 0.012,
    "30m": 0.014,
    "1h":  0.016,
    "4h":  0.020,
}

# 止损 ATR 倍数
SL_ATR_MULT = 2.0


def calc_atr(df: pd.DataFrame, period: int = 14) -> pd.Series:
    h, l, c = df["high"], df["low"], df["close"]
    pc = c.shift(1)
    tr = pd.concat([(h - l), (h - pc).abs(), (l - pc).abs()], axis=1).max(axis=1)
    return tr.ewm(span=period, adjust=False).mean()


@dataclass
class Signal:
    direction:   Direction
    entry_price: float
    stop_loss:   float
    take_profit: float
    interval:    str
    account:     AccountType
    open_time:   pd.Timestamp
    boll_up:     float
    boll_dn:     float
    boll_mb:     float
    bw:          float
    atr:         float
    reason:      str = ""


def _find_sl_from_xy(
    direction: Direction,
    entry: float,
    x_pts: list,
    y_pts: list,
    atr: float,
) -> float:
    """
    做多止损 = 最近X点低价 - SL_ATR_MULT*ATR
    做空止损 = 最近Y点高价 + SL_ATR_MULT*ATR
    没有XY点则退回 entry ± 2*ATR
    """
    buf = atr * SL_ATR_MULT
    if direction == Direction.LONG:
        prices = [p["price"] for p in x_pts if isinstance(p, dict) and "price" in p]
        if prices:
            return min(prices) - buf
        return entry - buf
    else:
        prices = [p["price"] for p in y_pts if isinstance(p, dict) and "price" in p]
        if prices:
            return max(prices) + buf
        return entry + buf


def check_entry(
    row: pd.Series,
    prev_row: pd.Series,
    interval: str,
    account: AccountType,
    x_pts: list,
    y_pts: list,
    has_a_point: bool,
    atr: float,
    require_mouth_open: bool = True,
    require_a_point: bool = True,
    stop_loss_pct: float = 0.01,
    min_rr: float = 1.5,
) -> Optional[Signal]:
    """
    入场条件检查（全部通过才开仓）：

    必须通过：
      1. boll 均有效
      2. mouth_state==1（开口）
      3. bw >= BW_MIN[interval]（带宽足够，排除窄幅盘整）
      4. has_a_point（震荡结构A点已形成，说明在有效震荡区间内）
      5. close 在布林带内（做震荡，不追破位）
      6. low<=boll_dn 触碰下轨(做多) 或 high>=boll_up 触碰上轨(做空)
      7. 止损在入场价的合理位置
    """
    bu = row.get("boll_up")
    bm = row.get("boll_mb")
    bd = row.get("boll_dn")
    bw = row.get("bw")
    mouth = int(row.get("mouth_state", 0))
    close = float(row["close"])
    high  = float(row["high"])
    low   = float(row["low"])

    # 1. 指标有效
    if pd.isna(bu) or pd.isna(bm) or pd.isna(bd) or pd.isna(bw):
        return None
    bu, bm, bd, bw = float(bu), float(bm), float(bd), float(bw)
    if bm == 0:
        return None

    # 2. 布林口开口
    if require_mouth_open and mouth != 1:
        return None

    # 3. 带宽过滤
    if bw < BW_MIN.get(interval, 0.010):
        return None

    # 4. 震荡结构A点确认
    if require_a_point and not has_a_point:
        return None

    # 5. close 必须在布林带内（不追趋势突破）
    if close < bd or close > bu:
        return None

    # 6. 触碰条件
    touch_lower = low  <= bd
    touch_upper = high >= bu

    if not touch_lower and not touch_upper:
        return None
    # 同时触碰：看收盘偏向哪侧
    if touch_lower and touch_upper:
        if close >= bm:
            touch_lower = False
        else:
            touch_upper = False

    direction = Direction.LONG if touch_lower else Direction.SHORT

    # 7. 止损/止盈（v2优化）
    # 止损：入场价固定百分比
    sl = close * (1.0 - stop_loss_pct) if direction == Direction.LONG else close * (1.0 + stop_loss_pct)
    # 止盈：当前周期布林带上下轨（开仓时先按当前值计算，后续由引擎动态更新）
    tp = bu if direction == Direction.LONG else bd

    if direction == Direction.LONG:
        if tp <= close:
            return None      # 止盈空间不足
        rr = (tp - close) / (close - sl) if (close - sl) > 0 else 0.0
    else:
        if tp >= close:
            return None
        rr = (close - tp) / (sl - close) if (sl - close) > 0 else 0.0

    # 盈亏比必须 >= 1.5
    if rr < float(min_rr):
        return None

    return Signal(
        direction=direction,
        entry_price=close,
        stop_loss=sl,
        take_profit=tp,
        interval=interval,
        account=account,
        open_time=row.name if isinstance(row.name, pd.Timestamp) else pd.Timestamp(row.name),
        boll_up=bu, boll_dn=bd, boll_mb=bm,
        bw=bw, atr=atr,
        reason=f"touch_{'lower' if direction == Direction.LONG else 'upper'}",
    )
