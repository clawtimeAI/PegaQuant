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

---

# 震荡结构 V4 规则（草案）

本文是基于你反馈的两个问题（结构首点冗余、趋势延续但不触轨导致无法识别新结构）提出的 V4 重新设计，用 `bw`（布林带相对带宽）作为“开口/收口”的主驱动信号，不再依赖“轨外连续收盘>=30”这类强触轨条件。

## 1. 输入数据与关键指标

每根 K 线字段（来自 `kline_{interval}`）：
- `open_time`
- `high / low / close`
- `boll_up / boll_mb / boll_dn`
- `bw`：布林带相对带宽，建议统一定义为 `bw = (boll_up - boll_dn) / boll_mb`（`boll_mb` 为空或 0 时为 NULL）

辅助判定：
- 触及中轨：`low <= boll_mb <= high`
- 轨外极值（用于关键点候选）：
  - `low < boll_dn`：下轨外极值候选（X）
  - `high > boll_up`：上轨外极值候选（Y）

## 2. 结构生命周期（V4 的核心）

V4 将一个震荡结构定义为：
- 一段新的行情（上涨或下跌）导致 `bw` 扩张（开口），随后 `bw` 收缩（收口）直到完成确认；
- 从“开口第一根 K”到“收口确认完成”算作一个完整生命周期；
- 下一个结构从上一个结构的结束点开始衔接（即：新结构的 start_time = 上一个结构的 end_time）。

### 2.1 状态划分

结构内使用 `bw` 驱动的三段状态：
- `WAIT_OPEN`：等待开口开始（寻找“开口第一根 K”）
- `OPENING`：已进入开口过程，等待开口确认
- `CLOSING`：开口已确认并进入收口过程，等待收口确认（结构完成）

### 2.2 开口起点（开口第一根 K）

定义：
- `open_start_time`：识别到“bw 由收缩转为扩张”的第一根 K
- `open_start_bw`：该根 K 的 `bw`

判定建议（实现可二选一，V4 文档先固定语义）：
- 使用 `mouth_state`：当 `mouth_state` 从 2（收口）切到 1（开口）的那根 K 作为 `open_start_time`

### 2.3 开口确认（2X 规则）

参数：
- `open_factor = 2.0`

确认条件：
- 从 `open_start_time` 开始向后扫描，若出现任意一根 K 满足：
  - `bw >= open_start_bw * open_factor`
则认为“开口确认达成”，记录：
- `open_confirm_time`：满足条件的第一根 K 的时间
- `open_confirm_bw`：该根的 `bw`

说明：
- 这一步的直觉含义是“结构将要到来/进入结构波动阶段”，通常也接近“开口最宽的区域”，但实现上不要求必须取全局最大，仅需满足 2X 即可确认。

### 2.4 收口起点（收口第一根 K）

定义：
- `close_start_time`：在开口确认之后，识别到 `bw` 由扩张转为收缩的第一根 K
- `close_start_bw`：该根 K 的 `bw`

判定建议（同 2.2）：
- 方案 A：`mouth_state` 从 1（开口）切到 2（收口）的那根 K
- 方案 B：纯 bw 单调性：出现“确认性下降”（例如 `bw_t < bw_{t-1} - eps`）的第一根 K

### 2.5 收口确认（2X 范围规则）

参数：
- `close_factor = 2.0`

确认条件（收口意味着带宽显著缩窄）：
- 从 `close_start_time` 开始向后扫描，若出现任意一根 K 满足：
  - `bw < close_start_bw / close_factor`
则认为“收口确认达成”，结构生命周期结束，记录：
- `close_confirm_time`
- `close_confirm_bw`
- `end_time = close_confirm_time`（或实现上使用 `close_confirm_time` 作为切分边界）

说明：
- “2 倍范围”在收口阶段的合理语义是“缩到收口第一根的 1/2（或更小）”。如果你希望用“相对区间”表达，也可以写成：`bw / close_start_bw <= 1 / close_factor`。

### 2.6 处理中途“假收口→再开口”的扰动（你举的 111...222...111 场景）

你描述的典型形态是：在一段下跌/上涨趋势的开口过程中，会出现一段横盘使 `mouth_state` 临时进入 2（收口），但并未触及中轨，随后趋势继续（`mouth_state` 又回到 1），且这一段的 `bw` 可能一直较高。

为了避免这类“假收口”扰乱结构切分，V4 增加一条约束：**收口起点需要“锁定”**，未锁定的收口视作噪声，不进入结构收口阶段。

定义：
- `peak_bw_since_open_confirm`：从 `open_confirm_time` 起到当前，观察到的最大 `bw`
- `close_lock_factor = 2.0`（与 2X 规则对齐，先固定为 2.0）

规则：
- 当出现 `mouth_state 1 -> 2` 时，先记录一个“收口候选”：
  - `close_probe_time = t`
  - `close_probe_bw = bw_t`
- 只有当后续出现任意一根 K 满足 `bw <= peak_bw_since_open_confirm / close_lock_factor` 时，才将该根视为真正进入收口阶段，并“锁定收口起点”：
  - `close_start_time = close_probe_time`
  - `close_start_bw = close_probe_bw`
  - phase 进入 `CLOSING`
- 如果在锁定前，`mouth_state` 又从 2 回到 1（即出现 2->1 再开口），则清空 `close_probe_*`，继续处于开口阶段（`OPENING`），不切结构。

说明：
- 直觉含义：只有当带宽相对“开口确认后的峰值”至少收缩到一半，才认为这次收口是有效的；横盘导致的短暂收口但带宽仍处于高位时，会被过滤掉。
- 一旦收口起点被锁定，结构的收口确认仍按 2.5：`bw < close_start_bw / 2` 完成生命周期闭合。

## 3. 关键点（X/Y）规则（V4 必须修复的问题）

### 3.1 关键点候选（pending）

结构内持续维护一个 `pending` 候选点：
- 若 `low < boll_dn`：pending.kind = X，price 取更低的 low（记录极值时间）
- 若 `high > boll_up`：pending.kind = Y，price 取更高的 high（记录极值时间）

### 3.2 关键点确认（统一用“触及中轨”）

V4 将关键点确认统一收敛为：
- **只要出现“触及中轨（low <= boll_mb <= high）”，就可以确认 pending 为一个关键点**

并且增加“结构首个点”的硬约束（用于解决你指出的 X1 冗余问题）：
- 一个结构的第一个关键点只能是 `X1` 或 `Y1` 其一
- **第一个关键点必须在首次触及中轨之后才能确认**
  - 也就是说：在结构开始后、首次触中轨之前，即便出现轨外极值，也只能更新 pending，但不能确认成 `X1/Y1`
  - 当出现首次触中轨时：将“触中轨之后的第一段轨外极值 pending”作为结构的 `X1/Y1`，并在下一次触中轨时完成确认

对应你给的例子（结构 ID 582）：
- `2026/3/19 04:10:00 70456` 出现在“触中轨之前”，因此不应成为 X1
- “触及中轨后”的轨外极值关键点应是：`2026/3/19 15:20:00 69421.1`（作为新的 X1）

### 3.3 可能没有首点的情况

你提到“长期下跌横盘后继续跌，期间不会触及中轨”：
- V4 允许结构在整个生命周期内不产生任何关键点（因为首点确认被触中轨约束住了）
- 结构切分仍由 `bw` 的开口/收口确认完成，不依赖关键点是否存在

## 4. 结构切分与循环

一旦收口确认达成：
- 当前结构标记为 `CLOSED`，`end_time = close_confirm_time`
- 立即进入下一轮 `WAIT_OPEN`，寻找下一段开口第一根 K 作为新结构的 `start_time`

新结构的 `start_time` 与旧结构的 `end_time` 的衔接原则：
- 语义上要求连续：`new.start_time = old.end_time`
- 实现上可以取“下一轮开口第一根 K”作为 `new.start_time`，并将上一结构 `end_time` 设为同一时间点，确保结构不重叠不间断

## 5. V4 建议落库字段（供确认后实现）

若新建 `oscillation_structures_v4`，建议核心字段：
- `symbol / interval / status`
- `start_time / end_time`
- `open_start_time / open_start_bw`
- `open_confirm_time / open_confirm_bw / open_factor`
- `close_start_time / close_start_bw`
- `close_confirm_time / close_confirm_bw / close_factor`
- `x_points / y_points`（可选，保留兼容）
- `engine_state`（用于断点续跑：包含 phase、pending、首次触中轨时间等）

## 6. 已确认的关键点（用于后续落地实现）

- 收口确认条件：`bw < close_start_bw / 2`（`close_factor = 2.0`）
- 开口第一根 K：使用 `mouth_state 2->1`
- 结构首个关键点：固定为“首次触中轨之后才允许确认 X1/Y1”

---

# 趋势/震荡两态 + 震荡区间突破约束（草案）

你提出的简化假设是：只看两种行情状态即可降低误识别。
- 开口（`mouth_state = 1`）代表趋势行情（上涨/下跌不区分方向）
- 缩口（`mouth_state = 2`）代表震荡行情

当前问题是：震荡阶段会出现很多“假突破/假结构”，导致震荡结构不清晰。因此需要一个“区间约束”：上一段震荡期形成的带宽上界未被突破前，不允许进入新的趋势阶段。

本节给出一个更完整的约束逻辑，用 `bw` 来定义“震荡区间”并过滤假开口。

## 1. 核心思想

- 趋势阶段一旦“开口确认”，视为趋势成立（趋势内部允许出现短暂收口噪声，不应频繁切换状态）。
- 趋势结束后进入震荡阶段，在震荡阶段维护一个 `range_bw_max`（震荡期观测到的 `bw` 最大值）。
- 下一次趋势想成立，必须满足两件事：
  1) 新的开口确认达成（2X 规则）
  2) 新开口确认的带宽强度，必须“打破震荡区间上界”（`open_confirm_bw > range_bw_max`，可选加倍数阈值）

直觉：震荡期的带宽上界可以看作“区间波动的最大强度”。如果新的开口确认并没有超过这个强度，就更可能是区间内的噪声开合，不应被识别成“趋势突破”。

## 2. 状态机（两态 + 候选开口）

建议用以下状态表达（外部仍是“两态”，但内部需要细分来实现过滤与成熟度）：
- `TREND`：趋势期（开口确认通过，并突破震荡上界）
- `RANGE_BUILDING`：震荡期（缩口）但尚未成熟（用于过滤“短暂缩口”）
- `RANGE_READY`：震荡期已成熟（允许进入“突破候选”）
- `BREAKOUT_CANDIDATE`：出现 2->1 后，开口在进行中，但还没满足“突破约束”

其中 `BREAKOUT_CANDIDATE` 的存在是为了把“2->1 的短暂开口”先暂存，不立即切换为趋势，直到确认它真的突破了震荡区间。

## 3. 关键变量与维护规则

### 3.1 趋势确认变量（沿用 V4 的 2X 规则）

- `open_start_time / open_start_bw`：开口第一根 K（建议使用 `mouth_state 2->1` 的那根）
- `open_confirm_time / open_confirm_bw`：满足 `bw >= open_start_bw * open_factor` 的第一根 K（`open_factor=2.0`）

### 3.2 震荡区间变量（新增）

当进入 `RANGE` 时初始化：
- `range_start_time`
- `range_bw_max`：震荡期的 `bw` 最大值（初始化为 `bw` 当前值）
- `range_bw_min`：震荡期的 `bw` 最小值（初始化为 `bw` 当前值）
- `range_bar_count`：震荡期累计 K 数（初始化为 0）

在 `RANGE` 内逐根更新：
- `range_bw_max = max(range_bw_max, bw)`
- `range_bw_min = min(range_bw_min, bw)`
- `range_bar_count += 1`

### 3.3 突破约束（你要求的“必须打破极值”）

当出现开口确认（`open_confirm_time` 已产生）时，额外检查：
- 强约束（默认建议）：
  - `open_confirm_bw > range_bw_max`
- 可选增强（抗噪更强，但会降低灵敏度）：
  - `open_confirm_bw >= range_bw_max * breakout_factor`（例如 `breakout_factor=1.1~1.3`）

只有通过该检查，才允许从 `RANGE/BREAKOUT_CANDIDATE -> TREND`。

如果未通过：
- 视为“区间内假突破”：保持在 `RANGE`（或退回 `RANGE`），并继续更新 `range_bw_max`。

## 4. 状态切换条件（建议固定口径）

### 4.1 RANGE -> BREAKOUT_CANDIDATE

- 触发：仅当处于 `RANGE_READY`，且出现 `mouth_state 2->1`（出现开口第一根 K），并且 `bw` 非空
- 动作：记录 `open_start_*`，进入候选态

补充（短暂缩口过滤）：
- 若处于 `RANGE_BUILDING` 时发生 `mouth_state 2->1`，说明缩口未成熟即再次开口：
  - 该段缩口视为趋势内部噪声，不形成“有效震荡区间”
  - 丢弃本次 `range_*`（不使用 `range_bw_max` 做突破门槛）
  - 直接回到 `TREND`（等待趋势的下一次有效结束）

### 4.2 BREAKOUT_CANDIDATE -> TREND

- 触发：开口确认达成（`bw >= open_start_bw * 2.0`）且满足突破约束（`open_confirm_bw > range_bw_max`）
- 动作：
  - 关闭本次震荡区间：`range_end_time = open_confirm_time`（突破成立点固定使用 `open_confirm_time`）
  - 进入 `TREND`

### 4.3 BREAKOUT_CANDIDATE -> RANGE（失败回退）

两类失败都回退：
- 失败 A：开口过程中又缩回去（出现 `mouth_state 1->2` 且开口未确认）
- 失败 B：开口确认了但未突破 `range_bw_max`

回退动作：
- 清空 `open_start_* / open_confirm_*`
- 保持 `range_bw_max` 的持续更新（震荡区间不结束）

### 4.4 TREND -> RANGE

趋势结束可以继续沿用 V4 的“收口确认”（2X 范围规则），减少趋势期内部反复切换：
- 当趋势期进入有效收口并确认完成后（例如：先锁定收口起点，再 `bw < close_start_bw / 2`），切回 `RANGE`
- 切回时初始化新一段震荡区间的 `range_bw_max`

## 5. 防抖与边界情况（建议一并落地，否则会被噪声打爆）

### 5.1 bw 为空/异常

- `bw` 为空：不更新区间变量、不做确认判断，只更新 `last_mouth_state`
- `open_start_bw <= 0`：不开口确认（避免除法/倍数无意义）

### 5.2 震荡区间的“成熟度”（必选）

为降低震荡期误识别，本策略要求震荡区间必须“成熟”后才允许产生突破候选与突破门槛。

建议同时使用两个成熟度约束（两者都满足才认为成熟）：
- **持续性**：`range_bar_count >= N`（建议默认 `N=20`，按周期可配置）
- **收缩-再放大幅度**：`range_bw_max / range_bw_min >= mature_ratio`（建议默认 `mature_ratio=1.5`）

实现注意：
- `range_bw_min` 过小（接近 0）会导致比值失真，建议对 `range_bw_min` 做下限裁剪（例如 `>= 1e-9`）或仅在 `range_bw_min > 0` 时启用该条件。
- 未成熟（`RANGE_BUILDING`）期间出现 `2->1`，按 4.1 的补充规则丢弃该段震荡，不进入 `BREAKOUT_CANDIDATE`，以避免“短暂缩口”造成的假突破泛滥。

### 5.3 震荡上界被持续抬高的情况

如果震荡区间里出现了较强波动，`range_bw_max` 会被抬高，后续突破更难。
这恰好符合“区间越激烈，突破门槛越高”的直觉；但如果门槛过高，可用 `breakout_factor` 调整为略低于 1（不建议）或引入“衰减/窗口”：
- 只看最近 `M` 根 K 的 `range_bw_max`（滑动窗口）
- 或对 `range_bw_max` 做时间衰减

## 6. 与“震荡结构”展示的对应关系

如果采用该约束逻辑，结构定义会更清晰：
- 一段 `RANGE` 对应一个“震荡区间结构”（区间开始到突破成立点）
- 一段 `TREND` 对应一个“趋势段结构”（趋势开始到趋势收口确认结束）

你原来的“开口→收口”作为单个震荡结构生命周期依然可用，但建议在震荡期不要切出多个小结构，而是用 `range_bw_max` 的突破约束把结构切分点固定在“真正突破”上，从而避免震荡期的误识别泛滥。
