"""
风险管理 v2
修复：
  - 保证金耗尽立即平仓（防止负权益 → 数字溢出）
  - 强制止损：亏损达到保证金100%直接爆仓
  - 连续止损计数 → 触发冷却
"""
from __future__ import annotations
from dataclasses import dataclass, field
from typing import Optional
import pandas as pd

POSITION_RATIO = 0.50
LEVERAGE       = 20
TAKER_FEE      = 0.0004
MAX_DRAWDOWN   = 0.30    # 账户回撤30%停止交易


@dataclass
class Position:
    direction:    str    # "LONG" / "SHORT"
    entry_price:  float
    stop_loss:    float
    take_profit:  float
    entry_time:   pd.Timestamp
    interval:     str
    entry_kline_open: float = 0.0
    entry_kline_high: float = 0.0
    entry_kline_low: float = 0.0
    entry_kline_close: float = 0.0
    entry_kline_bw: float = 0.0
    entry_kline_mouth_state: int = 0
    entry_atr: float = 0.0
    margin:       float = 0.0
    notional:     float = 0.0
    qty:          float = 0.0
    fee_open:     float = 0.0
    status:       str   = "OPEN"
    close_price:  float = 0.0
    close_time:   Optional[pd.Timestamp] = None
    close_kline_interval: str = "1m"
    close_kline_open: float = 0.0
    close_kline_high: float = 0.0
    close_kline_low: float = 0.0
    close_kline_close: float = 0.0
    close_reason: str   = ""
    pnl:          float = 0.0
    pnl_pct:      float = 0.0
    fee_close:    float = 0.0
    max_loss_pnl: float = 0.0   # 最大允许亏损（=margin，超过强平）


@dataclass
class AccountState:
    name:           str
    equity:         float
    initial:        float
    peak_equity:    float = 0.0
    position:       Optional[Position] = None
    history:        list = field(default_factory=list)
    consec_sl:      int  = 0      # 连续止损次数
    cooldown_left:  int  = 0      # 冷却剩余K线数
    total_trades:   int  = 0

    def __post_init__(self):
        self.peak_equity = self.equity

    @property
    def drawdown(self) -> float:
        if self.peak_equity <= 0:
            return 0.0
        return max(0.0, (self.peak_equity - self.equity) / self.peak_equity)

    @property
    def is_stopped(self) -> bool:
        """账户是否停止交易（回撤过大或权益耗尽）"""
        return self.drawdown >= MAX_DRAWDOWN or self.equity <= 0

    def tick_cooldown(self):
        if self.cooldown_left > 0:
            self.cooldown_left -= 1

    def update_peak(self):
        if self.equity > self.peak_equity:
            self.peak_equity = self.equity


class RiskManager:
    def open_position(
        self,
        account: AccountState,
        direction: str,
        entry_price: float,
        stop_loss: float,
        take_profit: float,
        entry_time: pd.Timestamp,
        interval: str,
        cooldown_bars: int = 10,
    ) -> Optional[Position]:

        if account.position is not None:
            return None
        if account.is_stopped:
            return None
        if account.cooldown_left > 0:
            return None
        if account.equity <= 0:
            return None

        margin   = account.equity * POSITION_RATIO
        notional = margin * LEVERAGE
        qty      = notional / entry_price if entry_price > 0 else 0
        fee_open = notional * TAKER_FEE

        # 保证金必须足够支付手续费
        if margin <= fee_open:
            return None

        pos = Position(
            direction=direction,
            entry_price=entry_price,
            stop_loss=stop_loss,
            take_profit=take_profit,
            entry_time=entry_time,
            interval=interval,
            margin=margin,
            notional=notional,
            qty=qty,
            fee_open=fee_open,
            max_loss_pnl=margin,
        )

        account.equity  -= fee_open
        account.position = pos
        account.total_trades += 1
        return pos

    def check_close(
        self,
        account: AccountState,
        open: float,
        high: float,
        low: float,
        close: float,
        ts: pd.Timestamp,
        kline_interval: str = "1m",
        cooldown_bars: int = 10,
    ) -> Optional[Position]:
        pos = account.position
        if pos is None or pos.status != "OPEN":
            return None

        close_price  = None
        close_reason = ""

        if pos.direction == "LONG":
            # 爆仓检查（价格下跌 >= 保证金/notional = 1/leverage）
            liquidation = pos.entry_price * (1 - 1.0 / LEVERAGE)
            if low <= liquidation:
                close_price  = liquidation
                close_reason = "liquidation"
            elif low <= pos.stop_loss:
                close_price  = pos.stop_loss
                close_reason = "stop_loss"
            elif high >= pos.take_profit:
                close_price  = pos.take_profit
                close_reason = "take_profit"
        else:  # SHORT
            liquidation = pos.entry_price * (1 + 1.0 / LEVERAGE)
            if high >= liquidation:
                close_price  = liquidation
                close_reason = "liquidation"
            elif high >= pos.stop_loss:
                close_price  = pos.stop_loss
                close_reason = "stop_loss"
            elif low <= pos.take_profit:
                close_price  = pos.take_profit
                close_reason = "take_profit"

        if close_price is None:
            return None

        return self._close(
            account,
            close_price,
            ts,
            close_reason,
            cooldown_bars,
            close_kline_interval=kline_interval,
            close_kline_open=open,
            close_kline_high=high,
            close_kline_low=low,
            close_kline_close=close,
        )

    def force_close(
        self,
        account: AccountState,
        close_price: float,
        ts: pd.Timestamp,
        reason: str = "force_close",
        close_kline_interval: str = "1m",
        close_kline_open: float = 0.0,
        close_kline_high: float = 0.0,
        close_kline_low: float = 0.0,
        close_kline_close: float = 0.0,
    ) -> Optional[Position]:
        if account.position is None or account.position.status != "OPEN":
            return None
        return self._close(
            account,
            close_price,
            ts,
            reason,
            0,
            close_kline_interval=close_kline_interval,
            close_kline_open=close_kline_open,
            close_kline_high=close_kline_high,
            close_kline_low=close_kline_low,
            close_kline_close=close_kline_close,
        )

    def _close(
        self,
        account: AccountState,
        close_price: float,
        ts: pd.Timestamp,
        reason: str,
        cooldown_bars: int,
        close_kline_interval: str,
        close_kline_open: float,
        close_kline_high: float,
        close_kline_low: float,
        close_kline_close: float,
    ) -> Position:
        pos = account.position
        pos.close_price  = close_price
        pos.close_time   = ts
        pos.close_kline_interval = close_kline_interval
        pos.close_kline_open = close_kline_open
        pos.close_kline_high = close_kline_high
        pos.close_kline_low = close_kline_low
        pos.close_kline_close = close_kline_close
        pos.close_reason = reason
        pos.status       = "CLOSED"

        if pos.direction == "LONG":
            raw = (close_price - pos.entry_price) / pos.entry_price * pos.notional
        else:
            raw = (pos.entry_price - close_price) / pos.entry_price * pos.notional

        # 亏损不超过保证金（爆仓上限）
        raw = max(raw, -pos.margin)

        pos.fee_close = pos.notional * TAKER_FEE
        pos.pnl       = raw - pos.fee_open - pos.fee_close
        pos.pnl_pct   = pos.pnl / pos.margin if pos.margin > 0 else 0.0

        account.equity = max(0.0, account.equity + raw - pos.fee_close)
        account.update_peak()

        # 连续止损计数
        if reason in ("stop_loss", "liquidation"):
            account.consec_sl += 1
            if account.consec_sl >= 2 and cooldown_bars > 0:
                account.cooldown_left = cooldown_bars
                account.consec_sl = 0
        else:
            account.consec_sl = 0

        account.history.append(pos)
        account.position = None
        return pos
