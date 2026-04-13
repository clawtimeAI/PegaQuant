"""
回测引擎 v2
===========
核心修复：
  1. 每个账户只在对应周期的K线收盘时才检查信号（不是每根1m都查）
  2. 止损/止盈用1m高低价即时检测（覆盖所有周期的持仓）
  3. 止损金额上限 = 保证金（防止溢出）
  4. XY关键点严格时间过滤

信号过滤（在对应周期K线收盘时）：
  - mouth_state == 1
  - bw >= 阈值
  - has_a_point
  - 盈亏比 >= 1.5
  - 冷却期未到
"""
from __future__ import annotations
import pandas as pd
import numpy as np
from typing import Dict, List, Optional, Tuple
from dataclasses import dataclass, field

from backendV2.utils.db import load_klines, load_oscillation_structures, INTERVALS, INTERVAL_SECONDS
from backendV2.strategy.bb_strategy import (
    Direction, Signal, AccountType, ACCOUNT_INTERVALS,
    check_entry, calc_atr, BW_MIN,
)
from backendV2.risk.risk_manager import RiskManager, AccountState, Position

COOLDOWN_BARS = 10   # 连续2次止损后冷却K线数
NEAR_BAND_PCT = 0.002


@dataclass
class BacktestConfig:
    symbol:            str   = "BTCUSDT"
    start:             str   = "2024-01-01 00:00:00"
    end:               str   = "2024-12-31 23:59:59"
    initial_equity:    float = 10_000.0
    require_mouth:     bool  = True
    require_a_point:   bool  = True
    stop_loss_pct:     float = 0.01
    atr_period:        int   = 14
    min_rr:            float = 1.5


@dataclass
class TradeRecord:
    account:      str
    interval:     str
    direction:    str
    entry_kline_interval: str
    entry_kline_time: object
    entry_kline_open: float
    entry_kline_high: float
    entry_kline_low: float
    entry_kline_close: float
    entry_kline_bw: float
    entry_kline_mouth_state: int
    entry_time:   object
    close_time:   object
    close_kline_interval: str
    close_kline_time: object
    close_kline_open: float
    close_kline_high: float
    close_kline_low: float
    close_kline_close: float
    entry_price:  float
    close_price:  float
    stop_loss:    float
    take_profit:  float
    margin:       float
    notional:     float
    pnl:          float
    pnl_pct:      float
    fee:          float
    close_reason: str
    equity_after: float
    bw:           float
    atr:          float


@dataclass
class BacktestResult:
    config:        BacktestConfig
    trades:        List[TradeRecord]
    equity_curves: Dict[str, pd.Series]
    summary:       Dict


class BacktestEngine:
    def __init__(self, cfg: BacktestConfig):
        self.cfg = cfg
        self.rm  = RiskManager()
        self.accounts: Dict[str, AccountState] = {}
        self._klines:  Dict[str, pd.DataFrame] = {}
        self._atrs:    Dict[str, pd.Series]    = {}
        self._xy_map:  Dict[str, Dict]         = {}   # interval → {ts_str: (x_pts, y_pts, has_a)}
        self._trades:  List[TradeRecord]       = []
        self._eq_log:  Dict[str, List]         = {}
        # 记录每个账户在每个周期上一次生成信号的K线时间（防重复）
        self._last_signal_ts: Dict[str, Dict[str, pd.Timestamp]] = {}
        # 同一周期同方向止损后锁单：{account: {(interval, direction): locked_from_ts}}
        self._sl_locks: Dict[str, Dict[tuple[str, str], pd.Timestamp]] = {}
        self._band_limits: Dict[str, Dict[str, object]] = {
            "1h": {"active": False, "side": ""},
            "15m": {"active": False, "side": ""},
            "5m": {"active": False, "side": ""},
        }

    # ── 数据加载 ──────────────────────────────────
    def _load(self):
        print(f"[Engine] 加载数据 {self.cfg.symbol} {self.cfg.start}→{self.cfg.end}")
        for iv in INTERVALS:
            df = load_klines(self.cfg.symbol, iv, self.cfg.start, self.cfg.end)
            if not df.empty:
                self._atrs[iv] = calc_atr(df, self.cfg.atr_period)
            self._klines[iv] = df
            print(f"  {iv}: {len(df)} 根")

        print("[Engine] 加载震荡结构体...")
        for iv in INTERVALS:
            structs = load_oscillation_structures(
                self.cfg.symbol, iv, self.cfg.start, self.cfg.end
            )
            # 构建时间→(x_pts, y_pts, has_a_point) 的快照映射
            self._build_xy_snapshots(iv, structs)
            print(f"  {iv}: {len(structs)} 个结构体")

    def _build_xy_snapshots(self, interval: str, structs: list):
        """
        预计算每个结构体的"全量"x_points / y_points / has_a_point，
        回测时按时间查找。不做per-bar遍历，直接存每个结构体的范围。
        """
        self._xy_map[interval] = structs  # 直接存原始列表，回测时实时过滤

    def _get_xy(self, interval: str, ts: pd.Timestamp) -> Tuple[list, list, bool]:
        """返回 (x_pts, y_pts, has_a_point) — 严格 <= ts"""
        structs = self._xy_map.get(interval, [])
        ts_str  = str(ts)
        x_all, y_all = [], []
        has_a = False

        for s in structs:
            st = str(s.get("start_time") or "")
            et = str(s.get("end_time")   or "")
            # 跳过还未开始的
            if st > ts_str:
                continue
            # 只取当前时刻已存在的点
            for pt in (s.get("x_points") or []):
                if isinstance(pt, dict) and str(pt.get("time","")) <= ts_str:
                    x_all.append(pt)
            for pt in (s.get("y_points") or []):
                if isinstance(pt, dict) and str(pt.get("time","")) <= ts_str:
                    y_all.append(pt)
            if s.get("a_point") is not None:
                ap_time = str((s["a_point"] or {}).get("time",""))
                if ap_time and ap_time <= ts_str:
                    has_a = True

        return x_all[-10:], y_all[-10:], has_a

    # ── 初始化账户 ────────────────────────────────
    def _init_accounts(self):
        for at in AccountType:
            n = at.value
            self.accounts[n] = AccountState(
                name=n,
                equity=self.cfg.initial_equity,
                initial=self.cfg.initial_equity,
            )
            self._eq_log[n] = []
            self._last_signal_ts[n] = {}
            self._sl_locks[n] = {}

    def _maybe_unlock_after_mid_touch(self, acc_name: str, ts: pd.Timestamp) -> None:
        locks = self._sl_locks.get(acc_name)
        if not locks:
            return
        to_remove: list[tuple[str, str]] = []
        for (iv, direction), locked_from in locks.items():
            df_iv = self._klines.get(iv)
            if df_iv is None or df_iv.empty:
                continue
            valid = df_iv[(df_iv.index >= locked_from) & (df_iv.index <= ts)]
            if valid.empty:
                continue
            row = valid.iloc[-1]
            mb = row.get("boll_mb")
            if mb is None or pd.isna(mb):
                continue
            mb = float(mb)
            high = float(row.get("high", 0.0))
            low = float(row.get("low", 0.0))
            if low <= mb <= high:
                to_remove.append((iv, direction))
        for k in to_remove:
            locks.pop(k, None)

    def _get_last_closed_row(self, interval: str, ts: pd.Timestamp) -> Optional[pd.Series]:
        df = self._klines.get(interval)
        if df is None or df.empty:
            return None
        valid = df[df.index <= ts]
        if valid.empty:
            return None
        return valid.iloc[-1]

    def _update_band_limits(self, ts: pd.Timestamp, row_1m: pd.Series) -> None:
        high_1m = float(row_1m.get("high", 0.0))
        low_1m = float(row_1m.get("low", 0.0))

        for iv in ("1h", "15m", "5m"):
            st = self._band_limits.get(iv)
            if st is None:
                continue
            last = self._get_last_closed_row(iv, ts)
            if last is None:
                continue
            bu = last.get("boll_up")
            bm = last.get("boll_mb")
            bd = last.get("boll_dn")
            if bu is None or bm is None or bd is None or pd.isna(bu) or pd.isna(bm) or pd.isna(bd):
                continue
            bu, bm, bd = float(bu), float(bm), float(bd)

            if st["active"]:
                if low_1m <= bm <= high_1m:
                    st["active"] = False
                    st["side"] = ""
                continue

            if high_1m >= bu:
                st["active"] = True
                st["side"] = "upper"
            elif low_1m <= bd:
                st["active"] = True
                st["side"] = "lower"

    def _is_near_higher_bands(self, ts: pd.Timestamp, price: float, side: str) -> bool:
        if price <= 0:
            return False
        targets: list[float] = []
        for iv in ("15m", "1h"):
            last = self._get_last_closed_row(iv, ts)
            if last is None:
                continue
            v = last.get("boll_up" if side == "upper" else "boll_dn")
            if v is None or pd.isna(v):
                continue
            targets.append(float(v))
        if not targets:
            return False
        dist = min(abs(price - t) for t in targets)
        return dist <= price * NEAR_BAND_PCT

    def _entry_plans(self, at: AccountType, ts: pd.Timestamp, row_1m: pd.Series) -> Tuple[bool, List[Tuple[str, str, str]]]:
        st_1h = self._band_limits.get("1h", {})
        st_15m = self._band_limits.get("15m", {})
        st_5m = self._band_limits.get("5m", {})

        if st_1h.get("active") and st_1h.get("side") in ("upper", "lower"):
            side = str(st_1h["side"])
            pos_iv = "1h" if at == AccountType.LONG else ("15m" if at == AccountType.MID else "5m")
            return False, [("5m", pos_iv, side)]

        if st_15m.get("active") and st_15m.get("side") in ("upper", "lower") and at in (AccountType.MID, AccountType.SHORT):
            side = str(st_15m["side"])
            pos_iv = "15m" if at == AccountType.MID else "5m"
            return False, [("5m", pos_iv, side)]

        if st_5m.get("active") and st_5m.get("side") in ("upper", "lower"):
            side = str(st_5m["side"])
            if at != AccountType.SHORT:
                return True, []
            price = float(row_1m.get("close", 0.0))
            if self._is_near_higher_bands(ts, price, side):
                if (not st_15m.get("active")) or (not st_1h.get("active")):
                    return True, []
            return False, [("1m", "1m", side)]

        plans: List[Tuple[str, str, str]] = []
        for iv in ACCOUNT_INTERVALS[at]:
            if iv in ("4h", "30m"):
                continue
            plans.append((iv, iv, ""))
        return False, plans

    # ── 主循环 ────────────────────────────────────
    def run(self) -> BacktestResult:
        self._load()
        self._init_accounts()

        df_1m = self._klines.get("1m", pd.DataFrame())
        if df_1m.empty:
            raise ValueError("1m 无数据")

        total = len(df_1m)
        print(f"\n[Engine] 步进回测 {total} 根1m K线...")

        # 预建每个周期K线的时间集合（用于检测K线收盘）
        closed_ts: Dict[str, set] = {}
        for iv in INTERVALS:
            df = self._klines.get(iv, pd.DataFrame())
            closed_ts[iv] = set(df.index.astype(str)) if not df.empty else set()

        for i, (ts, row_1m) in enumerate(df_1m.iterrows()):
            if i % 100_000 == 0 and i > 0:
                print(f"  {i/total*100:.1f}%  {ts}")

            ts_str = str(ts)
            self._update_band_limits(ts, row_1m)

            for at in AccountType:
                acc_name = at.value
                account  = self.accounts[acc_name]

                # tick 冷却
                account.tick_cooldown()
                self._maybe_unlock_after_mid_touch(acc_name, ts)

                if account.is_stopped:
                    continue

                # ── 对持仓做止盈/止损检测（用1m高低价，覆盖所有周期持仓）──
                if account.position is not None:
                    pos = account.position
                    df_iv = self._klines.get(pos.interval)
                    if df_iv is not None and not df_iv.empty:
                        valid = df_iv[df_iv.index <= ts]
                        if not valid.empty:
                            row_iv = valid.iloc[-1]
                            if pos.direction == "LONG":
                                tp = row_iv.get("boll_up")
                                if tp is not None and not pd.isna(tp):
                                    pos.take_profit = float(tp)
                            else:
                                tp = row_iv.get("boll_dn")
                                if tp is not None and not pd.isna(tp):
                                    pos.take_profit = float(tp)
                    closed = self.rm.check_close(
                        account,
                        open=float(row_1m["open"]),
                        high=float(row_1m["high"]),
                        low=float(row_1m["low"]),
                        close=float(row_1m["close"]),
                        ts=ts,
                        kline_interval="1m",
                        cooldown_bars=COOLDOWN_BARS,
                    )
                    if closed is not None:
                        if closed.close_reason == "stop_loss":
                            self._sl_locks[acc_name][(closed.interval, closed.direction)] = ts
                        self._trades.append(self._make_record(acc_name, closed, account.equity))

                # ── 在对应周期K线收盘时才寻找新信号 ──
                if account.position is not None:
                    continue
                blocked, plans = self._entry_plans(at, ts, row_1m)
                if blocked:
                    continue

                for entry_iv, pos_iv, side in plans:
                    df_entry = self._klines.get(entry_iv)
                    if df_entry is None or df_entry.empty:
                        continue
                    if ts_str not in closed_ts.get(entry_iv, set()):
                        continue
                    if ts not in df_entry.index:
                        continue
                    row_entry = df_entry.loc[ts]

                    last_entry_ts = self._last_signal_ts[acc_name].get(entry_iv)
                    if last_entry_ts is not None and last_entry_ts == ts:
                        continue

                    atr_s = self._atrs.get(entry_iv)
                    atr = float(atr_s.loc[ts]) if (atr_s is not None and ts in atr_s.index and not pd.isna(atr_s.loc[ts])) else float(row_entry["close"]) * 0.005

                    struct_iv = "5m" if entry_iv in ("15m", "30m", "1h", "4h") else entry_iv
                    x_pts, y_pts, has_a = self._get_xy(struct_iv, ts)

                    loc_idx = df_entry.index.get_loc(ts)
                    prev_row = df_entry.iloc[loc_idx - 1] if loc_idx > 0 else row_entry

                    sig: Optional[Signal] = None
                    if side in ("upper", "lower"):
                        bu = row_entry.get("boll_up")
                        bm = row_entry.get("boll_mb")
                        bd = row_entry.get("boll_dn")
                        bw = row_entry.get("bw")
                        mouth = int(row_entry.get("mouth_state", 0))
                        if bu is None or bm is None or bd is None or bw is None:
                            continue
                        if pd.isna(bu) or pd.isna(bm) or pd.isna(bd) or pd.isna(bw):
                            continue
                        bu, bm, bd, bw = float(bu), float(bm), float(bd), float(bw)
                        if bm == 0:
                            continue

                        if self.cfg.require_mouth and mouth != 1:
                            continue
                        if bw < float(BW_MIN.get(entry_iv, 0.010)):
                            continue
                        if self.cfg.require_a_point and not has_a:
                            continue

                        direction = Direction.SHORT if side == "upper" else Direction.LONG
                        high = float(row_entry["high"])
                        low = float(row_entry["low"])
                        if direction == Direction.SHORT and high < bu:
                            continue
                        if direction == Direction.LONG and low > bd:
                            continue

                        if direction == Direction.LONG:
                            x_prices = [p.get("price") for p in x_pts if isinstance(p, dict) and p.get("price") is not None]
                            if len(x_prices) < 2:
                                continue
                        else:
                            y_prices = [p.get("price") for p in y_pts if isinstance(p, dict) and p.get("price") is not None]
                            if len(y_prices) < 2:
                                continue

                        last_pos = self._get_last_closed_row(pos_iv, ts)
                        if last_pos is None:
                            continue
                        tp_v = last_pos.get("boll_up" if direction == Direction.LONG else "boll_dn")
                        if tp_v is None or pd.isna(tp_v):
                            continue
                        tp = float(tp_v)
                        entry = float(row_entry["close"])
                        sl = entry * (1.0 - self.cfg.stop_loss_pct) if direction == Direction.LONG else entry * (1.0 + self.cfg.stop_loss_pct)

                        if direction == Direction.LONG:
                            if tp <= entry:
                                continue
                            rr = (tp - entry) / (entry - sl) if (entry - sl) > 0 else 0.0
                        else:
                            if tp >= entry:
                                continue
                            rr = (entry - tp) / (sl - entry) if (sl - entry) > 0 else 0.0
                        if rr < float(self.cfg.min_rr):
                            continue

                        sig = Signal(
                            direction=direction,
                            entry_price=entry,
                            stop_loss=sl,
                            take_profit=tp,
                            interval=pos_iv,
                            account=at,
                            open_time=ts,
                            boll_up=bu,
                            boll_dn=bd,
                            boll_mb=bm,
                            bw=bw,
                            atr=float(atr),
                            reason=f"limit_{side}_{entry_iv}_to_{pos_iv}",
                        )
                    else:
                        sig = check_entry(
                            row=row_entry,
                            prev_row=prev_row,
                            interval=entry_iv,
                            account=at,
                            x_pts=x_pts,
                            y_pts=y_pts,
                            has_a_point=has_a,
                            atr=atr,
                            require_mouth_open=self.cfg.require_mouth,
                            require_a_point=self.cfg.require_a_point,
                            stop_loss_pct=self.cfg.stop_loss_pct,
                            min_rr=self.cfg.min_rr,
                        )

                    if sig is None:
                        continue
                    if (sig.interval, sig.direction.value) in self._sl_locks.get(acc_name, {}):
                        continue

                    pos = self.rm.open_position(
                        account=account,
                        direction=sig.direction.value,
                        entry_price=sig.entry_price,
                        stop_loss=sig.stop_loss,
                        take_profit=sig.take_profit,
                        entry_time=ts,
                        interval=sig.interval,
                        cooldown_bars=COOLDOWN_BARS,
                    )
                    if pos is not None:
                        pos.entry_kline_interval = entry_iv
                        pos.entry_kline_open = float(row_entry.get("open", 0.0))
                        pos.entry_kline_high = float(row_entry.get("high", 0.0))
                        pos.entry_kline_low = float(row_entry.get("low", 0.0))
                        pos.entry_kline_close = float(row_entry.get("close", 0.0))
                        pos.entry_kline_bw = float(row_entry.get("bw", 0.0)) if not pd.isna(row_entry.get("bw")) else 0.0
                        pos.entry_kline_mouth_state = int(row_entry.get("mouth_state", 0) or 0)
                        pos.entry_atr = float(atr)
                        self._last_signal_ts[acc_name][entry_iv] = ts
                        break

            # 记录权益
            for acc_name, acc in self.accounts.items():
                self._eq_log[acc_name].append((ts, max(0.0, acc.equity)))

        # 强制平仓
        last_ts    = df_1m.index[-1]
        last_close = float(df_1m.iloc[-1]["close"])
        last_row_1m = df_1m.iloc[-1]
        for acc_name, account in self.accounts.items():
            pos = self.rm.force_close(
                account,
                last_close,
                last_ts,
                "backtest_end",
                close_kline_interval="1m",
                close_kline_open=float(last_row_1m.get("open", 0.0)),
                close_kline_high=float(last_row_1m.get("high", 0.0)),
                close_kline_low=float(last_row_1m.get("low", 0.0)),
                close_kline_close=float(last_row_1m.get("close", 0.0)),
            )
            if pos:
                self._trades.append(self._make_record(acc_name, pos, account.equity))

        return self._build_result()

    def _make_record(self, acc_name: str, pos: Position, eq: float) -> TradeRecord:
        return TradeRecord(
            account=acc_name, interval=pos.interval,
            direction=pos.direction,
            entry_kline_interval=str(getattr(pos, "entry_kline_interval", pos.interval)),
            entry_kline_time=pos.entry_time,
            entry_kline_open=float(getattr(pos, "entry_kline_open", 0.0)),
            entry_kline_high=float(getattr(pos, "entry_kline_high", 0.0)),
            entry_kline_low=float(getattr(pos, "entry_kline_low", 0.0)),
            entry_kline_close=float(getattr(pos, "entry_kline_close", 0.0)),
            entry_kline_bw=float(getattr(pos, "entry_kline_bw", 0.0)),
            entry_kline_mouth_state=int(getattr(pos, "entry_kline_mouth_state", 0) or 0),
            entry_time=pos.entry_time, close_time=pos.close_time,
            close_kline_interval=str(getattr(pos, "close_kline_interval", "1m")),
            close_kline_time=pos.close_time,
            close_kline_open=float(getattr(pos, "close_kline_open", 0.0)),
            close_kline_high=float(getattr(pos, "close_kline_high", 0.0)),
            close_kline_low=float(getattr(pos, "close_kline_low", 0.0)),
            close_kline_close=float(getattr(pos, "close_kline_close", 0.0)),
            entry_price=pos.entry_price, close_price=pos.close_price,
            stop_loss=pos.stop_loss, take_profit=pos.take_profit,
            margin=pos.margin, notional=pos.notional,
            pnl=pos.pnl, pnl_pct=pos.pnl_pct,
            fee=pos.fee_open + pos.fee_close,
            close_reason=pos.close_reason,
            equity_after=round(eq, 4),
            bw=float(getattr(pos, "entry_kline_bw", 0.0)),
            atr=float(getattr(pos, "entry_atr", 0.0)),
        )

    def _build_result(self) -> BacktestResult:
        eq_curves = {}
        for n, log in self._eq_log.items():
            if log:
                times, vals = zip(*log)
                eq_curves[n] = pd.Series(vals, index=pd.DatetimeIndex(times))

        summary = {}
        for at in AccountType:
            n = at.value
            acc = self.accounts[n]
            trades = [t for t in self._trades if t.account == n]
            summary[n] = _summarize(acc, trades, self.cfg.initial_equity)

        return BacktestResult(
            config=self.cfg,
            trades=self._trades,
            equity_curves=eq_curves,
            summary=summary,
        )


# ── 统计 ──────────────────────────────────────
def _summarize(acc: AccountState, trades: list, initial: float) -> dict:
    if not trades:
        return dict(total_trades=0, win_rate=0, total_pnl=0,
                    total_pnl_pct=0, max_drawdown=round(acc.drawdown, 4),
                    sharpe=0, profit_factor=0, avg_win=0, avg_loss=0,
                    final_equity=round(acc.equity, 2),
                    tp_count=0, sl_count=0, liq_count=0)

    pnls   = [t.pnl for t in trades]
    wins   = [p for p in pnls if p > 0]
    losses = [p for p in pnls if p <= 0]
    gp = sum(wins)   if wins   else 0.0
    gl = abs(sum(losses)) if losses else 0.0

    pnl_pcts = [t.pnl_pct for t in trades]
    std = np.std(pnl_pcts)
    sharpe = (np.mean(pnl_pcts) / std * np.sqrt(252)) if std > 0 and len(pnl_pcts) > 1 else 0.0

    return dict(
        total_trades  = len(trades),
        win_count     = len(wins),
        loss_count    = len(losses),
        win_rate      = round(len(wins) / len(trades), 4),
        total_pnl     = round(sum(pnls), 2),
        total_pnl_pct = round(sum(pnls) / initial, 4),
        max_drawdown  = round(acc.drawdown, 4),
        sharpe        = round(sharpe, 4),
        profit_factor = round(gp / gl, 4) if gl > 0 else float("inf"),
        avg_win       = round(np.mean(wins), 2)   if wins   else 0.0,
        avg_loss      = round(np.mean(losses), 2) if losses else 0.0,
        final_equity  = round(acc.equity, 2),
        tp_count      = sum(1 for t in trades if t.close_reason == "take_profit"),
        sl_count      = sum(1 for t in trades if t.close_reason == "stop_loss"),
        liq_count     = sum(1 for t in trades if t.close_reason == "liquidation"),
    )
