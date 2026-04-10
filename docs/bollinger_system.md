# 布林带系统识别（Slope / Trend / Regime）

目标：
- 把“布林带本身的形态”（上下轨斜率、带宽变化、轨外持续）与“价格相对布林带的位置”（轨内震荡、趋势延伸、回归中轨）统一成一套可复用的识别输出。
- 输出既要能用于局部规则（例如 ABC 的 B 破坏），也要能用于全局状态（例如 1m 最近 1500 根的走势分段：震荡→下跌→震荡→上涨→下跌）。

适用数据（每个 interval 都可用）：
- K 线：open/high/low/close/time
- 布林：boll_up / boll_mb / boll_dn

---

## 1. 触及与轨内判定（基础算子）

建议统一使用（已确定）：
- touch_mb：`low <= boll_mb <= high`
- touch_up：`high >= boll_up`
- touch_dn：`low <= boll_dn`
- in_band：`boll_dn <= close <= boll_up`
- outside_up：`close > boll_up`（或 `high > boll_up`，看场景）
- outside_dn：`close < boll_dn`（或 `low < boll_dn`，看场景）

说明：
- 触及用 high/low；“是否回到轨内”用 close，可以减少假触及造成的状态跳变。

---

## 2. 上下轨斜率（Slope）识别

用途：
- B 破坏规则：轨外持续 + 下轨斜率陡增（下跌加速）
- 趋势/震荡识别：中轨斜率、上下轨同向斜率、带宽变化
- 多周期联动：小周期失效时，判断是否在形成大周期趋势段

### 2.1 输入与输出

输入（窗口 N）：
- 最近 N 根的 `boll_up[t]`、`boll_mb[t]`、`boll_dn[t]`（至少需要其中一条）

输出（建议同时给出 3 个维度）：
- slope_raw：每根 K 的“价格斜率”（单位：price/bar）
- slope_pct：归一化斜率（单位：pct/bar），建议 `slope_raw / boll_mb_last`
- slope_angle：可选（单位：degrees），便于可视化（`atan(slope_pct)`)

### 2.2 计算方法（推荐：线性回归斜率）

对最近 N 个点做一元线性回归：
- 自变量：`x = 0..N-1`
- 因变量：`y = boll_dn`（或 boll_up / boll_mb）
- 输出：`slope_raw = cov(x,y) / var(x)`

对比阈值：
- dn_slope_pct = slope_raw / boll_mb_last
- 若 `dn_slope_pct < -slope_pct_threshold`，则认为“下轨斜率陡增”（向下加速）
- 若 `up_slope_pct > +slope_pct_threshold`，则认为“上轨斜率陡增”（向上加速）

### 2.3 参数建议（先给默认，后续可调）

- N（窗口长度）：20 / 50 / 100（短中长）
- slope_pct_threshold：0.0002 ~ 0.001（取决于周期与品种波动）
- 可加稳定性条件：要求连续 K 根满足（例如连续 3 根都超过阈值）

---

## 3. 带宽（Bandwidth）与状态辅助指标

### 3.1 带宽与压缩/扩张

带宽：
- bw = boll_up - boll_dn
- bw_pct = bw / boll_mb

带宽变化（扩张/压缩）：
- bw_chg = bw_pct_now - bw_pct_Nago

用途：
- bw 很小且 mb 斜率很小：更像震荡/盘整
- bw 扩张且 mb 有明显斜率：更像趋势段

### 3.2 轨外持续（Outside Streak）

定义：
- outside_dn_streak：连续多少根 close < boll_dn
- outside_up_streak：连续多少根 close > boll_up

用途：
- 趋势延伸判定（例如下跌趋势：outside_dn_streak 持续增长）
- B 破坏判定（outside_dn_streak >= N_outside 且 dn_slope_pct < -threshold）

---

## 4. 走势分段（Trend / Regime）识别：输出“震荡→下跌→…”

目标输出（例）：
- `RANGE -> DOWN -> RANGE -> UP -> DOWN`

### 4.1 单根 K 的 Regime 分类（建议规则先行，后续可上机器学习）

给每根 bar 打一个标签（regime），再做“相邻同类合并”得到分段：

建议标签集合：
- RANGE（震荡）
- UP（上涨趋势）
- DOWN（下跌趋势）
- BREAKOUT_UP（上破轨外延伸）
- BREAKOUT_DN（下破轨外延伸）
- MEAN_REVERT（回归中轨段，可选）

推荐判定特征（可组合）：
- mb_slope_pct（中轨斜率）
- bw_pct 与 bw_chg（带宽与扩张）
- outside_up_streak / outside_dn_streak（轨外持续）
- close 相对 mb 的位置（close > mb / close < mb）

一个可执行的“优先级规则”示例：
1) 若 outside_dn_streak >= N_outside 且 dn_slope_pct < -slope_thr：BREAKOUT_DN
2) 若 outside_up_streak >= N_outside 且 up_slope_pct > +slope_thr：BREAKOUT_UP
3) 若 mb_slope_pct > +mb_slope_thr 且 bw_pct > bw_thr：UP
4) 若 mb_slope_pct < -mb_slope_thr 且 bw_pct > bw_thr：DOWN
5) 否则：RANGE

### 4.2 分段（Segmentation）

从最新往回看 M 根（例如 1500 根）：
- 得到每根 bar 的 regime
- 合并连续相同 regime 为一个段（start_time/end_time/len）
- 可设置最小段长度 min_len（短于 min_len 的段合并到相邻段，减少噪声）

最终输出：
- 段序列（从旧到新）：`[(RANGE, 120 bars), (DOWN, 300 bars), ...]`
- 以及压缩文本：`RANGE->DOWN->RANGE->UP->DOWN`

---

## 5. 与 ABC 形态的结合点（落地接口）

ABC 引擎里会直接使用的布林系统输出（建议统一成函数返回值）：
- dn_slope_pct / up_slope_pct / mb_slope_pct
- outside_dn_streak / outside_up_streak
- bw_pct / bw_chg
- regime（当前 bar 的标签）
- segments（最近 M 根的分段序列，供“当前大趋势/小趋势”判断）

示例：B 破坏规则（下轨侧 B）可写成：
- 若 `outside_dn_streak >= N_outside` 且 `dn_slope_pct < -slope_thr` => B 破坏（强势下跌趋势）

---

## 6. 需要你后续继续补充/定稿的参数表（建议逐步沉淀）

- N_outside：轨外持续阈值（按周期不同）
- slope_thr / mb_slope_thr：斜率阈值（按周期不同）
- bw_thr：带宽阈值（震荡 vs 趋势）
- min_len：分段最短长度（减少噪声段）
- “刷新新低”最小差值 eps：用于 B 刷新计数去噪

