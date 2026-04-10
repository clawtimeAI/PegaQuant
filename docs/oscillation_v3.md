# 震荡结构 V3 规则（oscillation_structures_v3）

本文用于确认 V3 引擎的规则细节与落库字段含义，对应实现：`webman/plugin/webman/gateway/NewOscillationEngineV3.php`。

---

## 1. 输入数据与基础算子

每根 K 线字段（来自 `kline_{interval}`）：
- `open_time`
- `high / low / close`
- `boll_up / boll_mb / boll_dn`

基础判定（V3 当前实现）：
- 轨外收盘（上轨外）：`close > boll_up`
- 轨外收盘（下轨外）：`close < boll_dn`
- 回到轨内：`boll_dn <= close <= boll_up`
- 触及中轨：`low <= boll_mb <= high`
- 带宽：`band_width = boll_up - boll_dn`

说明：
- “是否轨外持续”统一用 close 判定，避免 high/low 假刺穿导致状态跳变。
- “触及中轨”统一用 high/low 判定。
- 引擎支持传入 `startTime/endTime` 进行历史重算：当 `startTime` 非空时，会清空该 `symbol+interval` 的旧结构并从 `startTime` 起重新计算。

---

## 2. 表结构（oscillation_structures_v3）

核心字段：
- `symbol / interval`
- `status`: `ACTIVE` / `CLOSED`
- `start_time / end_time`
- `x_points / y_points`：关键点数组（见第 4 节）
- `a_point`：A 点（见第 3 节）
- `episode`：一次“轨外持续→进轨→2X”的过程信息（见第 3 节）
- `close_reason / close_condition`
- `engine_state`：引擎运行时状态机快照

说明：
- 引擎会自动 `CREATE TABLE IF NOT EXISTS`，并 `ALTER TABLE ... ADD COLUMN IF NOT EXISTS x_points/y_points`，老表可平滑升级。

---

## 3. 结构结束与新结构生成（V3 的核心）

### 3.1 Episode 定义（轨外持续段）

当出现轨外收盘时，进入 Episode 计数：
- `episode_start_time`：同侧轨外收盘段的第 1 根 K（close 在轨外）的 `open_time`
- `episode_side`：
  - `UP`：`close > boll_up`
  - `DN`：`close < boll_dn`
- `outside_streak`：同侧轨外收盘连续根数（close 连续在轨外）

当 `outside_streak >= 30`：
- `episode_confirm_time`：达到第 30 根（或以上）时那根 K 的 `open_time`
- 记录基准带宽：
  - `UP`：用“从下轨侧运行过来后，首次触及上轨”的那根 K；若取不到则回退到 Episode 第 1 根
  - `DN`：优先用“最近一个 X 关键点”的那根 K；若 X 不存在则用“从上轨侧运行过来后，首次触及下轨”的那根 K；若仍取不到则回退到 Episode 第 1 根
  - `start_band_time`：用于取基准带宽的那根 K 的 `open_time`
  - `start_boll_up / start_boll_dn`
  - `start_band_width = start_boll_up - start_boll_dn`
- 状态进入 “等待回到轨内”（见 3.2）

### 3.2 回到轨内后，计算 2X 带宽条件

当价格首次回到轨内（`boll_dn <= close <= boll_up`）：
- `reentry_time`：首次回到轨内那根 K 的 `open_time`
- 初始化回到轨内后的最大带宽：
  - `max_band_width_after_reentry = max(boll_up - boll_dn)`（从 reentry 开始一路更新）

阈值定义：
- `band_width_factor = 2.0`
- `band_width_threshold = start_band_width * band_width_factor`

2X 达成条件（使用 `>=`）：
- `max_band_width_after_reentry >= band_width_threshold`

### 3.3 A 点捕捉与确认

A 点的定义与 “进轨后触及中轨确认”一致：
- 在 Episode 已达到 30 且已经回到轨内后，首次出现：
  - 回到轨内：`boll_dn <= close <= boll_up`
  - 触及中轨：`low <= boll_mb <= high`
- 则记录：
  - `a_point = { time: open_time, price: boll_mb, side: episode_side }`

说明：
- 2X 与触中轨（A 点）先后顺序不固定：两者在回到轨内后先发生谁都可以，最终只要两者都发生即可完成结构切分。

### 3.3.1 未达成 2X 时的重新起算规则（你补充的关键要求）

如果 Episode 已进入 “回到轨内后等待 2X” 的阶段，但还没有满足 “2X（>=）”：
- 只要后续又出现新的“轨外连续收盘 >= 30”段（不论方向），则立刻以该段为新的 Episode 重新开始计算：
  - 新的 `episode_start_time` 取该段轨外连续收盘的第 1 根 K
  - 新的 `start_band_width` 基准改为“最近同侧最后一个关键点”的那根 K 的 `boll_up - boll_dn`（若不存在则回退到该段第 1 根 K）
  - 之前已捕捉的 `a_candidate_time/a_candidate_price` 全部清除
  - `reentry_time/max_band_width_after_reentry/band_2x_reached` 全部清除并重新等待回到轨内

### 3.4 结束当前结构 + 创建新结构（你要求的关键点）

当满足 “轨外收盘>=30 → 回到轨内后带宽达到 2X（>=） + 回到轨内后触及中轨（A 点）” 时：

1) 结束当前结构（若当前结构已有内容：已有 `a_point` 或已累计关键点 `x_points/y_points`）：
- `close_reason = BREAK_EPISODE_2X`
- `close_condition` 会记录：
  - `episode_start_time / episode_confirm_time / reentry_time`
  - `threshold_time`：达到 2X 判定时那根 K 的 `open_time`
  - `a_time`：A 点候选时间
  - `band_width_factor / start_band_width / band_width_threshold / max_band_width_after_reentry`
- **end_time = episode_start_time**

2) 创建新结构（立即创建，不等待后续）：
- **新结构 start_time = episode_start_time**（连续轨外收盘段的第一根K）
- 新结构会直接写入本次确认得到的：
  - `a_point`
  - `episode`
- 新结构状态机重置到 `WAIT_EPISODE`，用于寻找下一段 Episode

备注：
- V3 的“新结构起始时间”与 “确认 A 点时间”是两回事：
  - `start_time` 固定取 `episode_start_time`
  - `a_point.time` 仍是 `a_candidate_time`

---

## 4. X/Y 关键点规则（同 V1 的标记方式）

### 4.1 关键点数据结构

每个点结构：
- `time`
- `price`
- `kind`: `X` 或 `Y`
- `label`: `X1/X2/...` 或 `Y1/Y2/...`

### 4.2 触发与确认（结构首个点有额外约束）

触发 pending（抓轨外极值）：
- 若 `low < boll_dn`：更新 pending 为 `X`，并取更低 `low` 作为极值
- 若 `high > boll_up`：更新 pending 为 `Y`，并取更高 `high` 作为极值

确认关键点：
- 当 pending 存在：
  - 若为结构内第一个关键点（先出现 X1 则约束 X1；先出现 Y1 则约束 Y1），则必须满足：在 pending 产生之后的任意一根 K，且收盘已回到轨内 `boll_dn <= close <= boll_up`，并触及中轨 `low <= boll_mb <= high`
  - 若不是结构内第一个关键点，则满足以下任一条件即可确认：
    - 条件 A（触及中轨确认）：在 pending 产生之后的任意一根 K，只要满足 `low <= boll_mb <= high` 即可（不允许用 pending 产生的同一根 K）
    - 条件 B（轨内收盘次数确认）：连续收盘在轨内 `boll_dn <= close <= boll_up` 的根数 `>= 30`
- 满足任一条件，则确认 pending 为关键点：
  - `X` → 追加到 `x_points`，label 按追加顺序生成 `X1/X2/...`
  - `Y` → 追加到 `y_points`，label 按追加顺序生成 `Y1/Y2/...`
- 确认后清空 pending

约束（当前实现）：
- 关键点在结构生命周期内持续累计；结构发生切分（`BREAK_EPISODE_2X`）时会按 `episode_start_time` 进行归属切分并重新编号（新结构从 `X1/Y1` 开始）。

---

## 5. 状态机（engine_state.phase）

phase 取值：
- `WAIT_EPISODE`：等待出现同侧轨外收盘并累计 streak
- `WAIT_REENTRY`：streak>=30 后，等待 close 回到轨内
- `WAIT_CONFIRM`：回到轨内后，持续更新 `max_band_width_after_reentry` 与捕捉 `a_candidate`，直到满足确认条件

engine_state 关键字段（与规则对应）：
- `outside_side / outside_streak`
- `episode_side / episode_start_time / episode_confirm_time`
- `start_band_time / start_boll_up / start_boll_dn / start_band_width`
- `reentry_time / max_band_width_after_reentry / band_2x_reached`
- `a_candidate_time / a_candidate_price`
- `pending`（用于关键点）
- `restart_side / restart_streak / restart_start_time / restart_start_band_time / restart_start_band_width`（用于“未达成 2X 时，出现新轨外>=30 的重新起算”）

---

## 6. 你需要重点确认的结论（摘要）

- “结构结束条件”以 **start_band_time 处的带宽**作为基准（默认取“最近同侧最后一个关键点”的那根 K；没有则回退到 episode_start_time），回到轨内后只要同时满足：
  - 带宽最大值 `max_band_width_after_reentry >= 2X`
  - 回到轨内后触及中轨（A 点）
  即触发切分（两者先后顺序不限制）。
- 触发后会立即“结束旧结构 + 创建新结构”：
  - 旧结构 `end_time = episode_start_time`
  - 新结构 `start_time = episode_start_time`
- X/Y 关键点确认统一为：**进轨后触及中轨确认**（X1/Y1 与后续 Xn/Yn 规则一致）。
