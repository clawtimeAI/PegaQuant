# backendV2 规则与实现说明（整理版）

本文基于 docs/backendV2.md 的规则描述，并结合当前代码实现（backendV2/backtest、backendV2/strategy、backendV2/risk、backendV2/report）整理。为避免“规则期望”和“代码现实”混淆，文中明确标注为“已实现 / 待实现”。

## 1. 总览

backendV2 当前定位为“回测引擎 + 信号/风控规则集合”，核心特点：

- 三账户并行：short / mid / long
- 多周期驱动：按 1m 步进，但只在对应周期 K 线收盘时评估开仓信号
- 出场（止盈/止损/爆仓）：用 1m 的 high/low 做盘中触发，覆盖所有周期持仓
- 报告：输出 HTML + trades.csv（全量交易，可搜索）

## 2. 账户与周期

账户与周期映射（已实现）：

- short：1m、5m
- mid：15m、30m
- long：1h

说明：

- 4h 周期：已禁用（不开单、不参与信号检查）
- 30m 周期：当前仍会开单（docs/backendV2.md 里“30m 不开单”的规则暂未落地）

## 3. 数据依赖

回测所需数据（已实现）：

- K 线表：kline_1m / kline_5m / kline_15m / kline_30m / kline_1h / kline_4h
  - 字段：open_time, open, high, low, close, volume, boll_up, boll_mb, boll_dn, bw, mouth_state
- 震荡结构表：oscillation_structures_v3
  - 主要用：x_points / y_points / a_point（用于开仓过滤）

## 4. 回测主循环（驱动与信号评估时机）

驱动方式（已实现）：

- 以 1m 为时间轴逐根步进
- 每根 1m：
  - 对所有账户的持仓用 1m high/low 检测止盈/止损/爆仓
  - 对没有持仓的账户，仅在“对应周期 K 线收盘时刻”才评估该周期的开仓信号

“周期收盘”的判定方式（已实现）：

- 对每个周期预先建立其 open_time 索引集合
- 当 ts（当前 1m 时刻）出现在该集合中，视为该周期 K 线收盘时刻

## 5. 开仓（入场）逻辑

### 5.1 入场触发（已实现）

仅在周期 K 线收盘时评估：

- 1m/5m（short）
- 15m/30m（mid）
- 1h（long）

同一账户同一周期同一根 K 线，只允许触发一次（防重复）。

### 5.2 入场过滤条件（已实现）

对每个候选信号，按以下条件过滤，不满足则不入场：

- mouth_state 过滤：mouth_state == 1（可开关）
- 带宽过滤：bw >= BW_MIN[interval]（每个周期独立阈值）
- A 点过滤：必须 has_a_point == True（可开关）
- 不追突破：close 必须在布林带上下轨之间（boll_dn <= close <= boll_up）
- 触碰条件：必须触碰上下轨之一
  - low <= boll_dn → 认为触下轨
  - high >= boll_up → 认为触上轨
  - 若同一根同时触上下轨：用 close 相对中轨 boll_mb 决定只保留其中一侧触碰

### 5.3 方向判定（已实现）

- 触下轨 → 做多（LONG）
- 触上轨 → 做空（SHORT）

### 5.4 止损/止盈初值（已实现，且已按近期要求调整）

- 入场价：周期 K 线 close
- 止损：固定百分比（参数化）
  - LONG：sl = entry * (1 - sl_pct)
  - SHORT：sl = entry * (1 + sl_pct)
  - 默认 sl_pct = 0.01（1%），CLI 参数为 --sl-pct
- 止盈：按“开仓周期的布林带上下轨”
  - LONG：tp = 开仓周期 boll_up
  - SHORT：tp = 开仓周期 boll_dn
- 盈亏比过滤：rr >= min_rr（默认 1.5，可配置）

### 5.5 开仓限制（已实现）

开仓前必须同时满足：

- 账户无持仓
- 账户未停止交易（drawdown < MAX_DRAWDOWN 且 equity > 0）
- 冷却期 cooldown_left == 0
- 止损锁单未命中（见 6.2）

## 6. 开单限制（规则与实现差异）

### 6.1 “轨外开单限制”（待实现）

docs/backendV2.md 描述的规则要点：

- “价格触及上/下轨后，对应周期开单限制开启；触及中轨后关闭”
- “1h/15m 轨外时，需要去 5m 结构寻找 X2X3.. 或 Y2Y3.. 的预判开仓点”
- “5m 轨外时，需要去 1m 结构找开仓点”
- “5m 开单限制开启后，还需要判断与 15m/1h 上下轨的距离，接近则等待更高周期轨触发后再开”

当前代码状态：

- 已实现“同周期同方向止损锁单，触及中轨解锁”（见 6.2）
- 尚未实现“轨外→开启限制→只允许结构点位开仓”的那套上层联动逻辑

建议落地路径（供后续实现时对齐）：

- 引入 per-interval 的“轨外状态机”（inside / above / below）
- 当状态进入 above/below 时，开启对应周期的限制开关
- 当价格触及 boll_mb 时关闭限制开关
- 限制开关开启时，把入场点位约束到“结构点位附近”的规则（如 X2..、Y2..）并明确“点位附近”的距离阈值

### 6.2 “止损后锁单，触中轨解锁”（已实现）

规则（已实现）：

- 同一账户、同一周期、同一方向发生 stop_loss 后：
  - 禁止在该周期、该方向继续开仓
  - 直到价格触及该周期中轨 boll_mb 才解除

实现细节：

- 锁的粒度：按 (interval, direction) 锁
- 解锁判断：取该周期最近一根已收盘 K 线，满足 low <= boll_mb <= high 即认为“触及中轨”

## 7. 出场（止盈/止损/爆仓）逻辑

### 7.1 止盈按周期动态更新（已实现）

你要求的规则已落地：

- 例如 15m 多单：持仓期间 take_profit 始终跟随“当前时刻之前最新 15m K 线的 boll_up”
- 触发仍用 1m high/low 判断：
  - LONG：1m high >= take_profit
  - SHORT：1m low  <= take_profit

### 7.2 止损固定百分比（已实现）

- 止损只在开仓时设置为 entry ± sl_pct，不做移动止损
- 触发用 1m high/low 判断：
  - LONG：1m low <= stop_loss
  - SHORT：1m high >= stop_loss

### 7.3 爆仓（已实现）

使用固定杠杆阈值触发（LEVERAGE=20）：

- LONG：low <= entry * (1 - 1/leverage)
- SHORT：high >= entry * (1 + 1/leverage)

触发优先级（同一根 1m 同时满足多个条件时，按顺序命中）：

- LONG：爆仓 → 止损 → 止盈
- SHORT：爆仓 → 止损 → 止盈

## 8. 仓位与费用模型

已实现模型（当前口径）：

- 使用账户总权益 equity 计算仓位
- 每次开仓保证金比例：POSITION_RATIO（默认 0.5）
  - margin = equity * POSITION_RATIO
  - notional = margin * LEVERAGE
  - qty = notional / entry_price
- 手续费：
  - 开仓：fee_open = notional * TAKER_FEE
  - 平仓：fee_close = notional * TAKER_FEE
- 权益更新（保持“总权益口径”，不扣保证金占用）：
  - 开仓：equity -= fee_open
  - 平仓：equity += raw_pnl - fee_close
  - 并限制亏损不超过保证金：raw_pnl >= -margin

## 9. 报告与交易字段

已实现输出：

- HTML 报告：权益曲线 + 绩效表 + 全量交易表（支持搜索）
- trades.csv：全量交易记录

交易记录补充字段（已实现）：

- 成交（开仓）K 线：entry_kline_interval、entry_kline_time（以及相关指标字段）
- 出场触发 K 线：close_kline_interval、close_kline_time（以及相关指标字段）
- 页面当前隐藏 OHLC，但数据会在 CSV 中保留（便于进一步排查）

## 10. CLI 参数（回测入口）

run_backtest.py 支持：

- --symbol
- --start / --end
- --equity
- --sl-pct：固定止损百分比（默认 0.01）
- --no-mouth：关闭 mouth_state 过滤
- --no-a-point：关闭 A 点过滤
- --output：报告输出目录

## 11. 待实现清单（来自 docs/backendV2.md 的规则）

以下规则目前仍属于“方向/设计”，尚未完全反映到代码：

- “价格突破 1h/15m/5m 上下轨后，去更低周期结构中寻找 X2.. / Y2.. 点位入场”的层级联动
- “5m 开单限制开启后，还要判断距离 15m/1h 上下轨是否很接近，接近则等待更高周期轨触发后再开”的联动细化
- “30m 不开单”的禁用策略（当前 mid 仍允许 30m）

