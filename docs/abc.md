ABC 三角形态识别（基于布林上下轨 / 中轨 + 关键点）

目标：
- 在任意周期（1m,5m,15m,30m,1h,4h）把“震荡行情”拆分成最基本的 A-B-C 三角结构。
- A 与 C 位于同侧布林带（同为上轨侧或同为下轨侧），B 位于对侧布林带。
- 结合 oscillation_structures（震荡结构关键点 X/Y）与该 ABC 结构，用于量化引擎最终交易决策。

一、术语与输入数据
1) 布林带数据（每根 K 都有）：
- boll_up / boll_mb / boll_dn

2) 关键点（锚点）：
- 这里的“关键点”可以直接复用 oscillation_structures 的 X/Y 点位：
  - 上轨外：对应 Y 点区域（高于 boll_up 的极值/确认）
  - 下轨外：对应 X 点区域（低于 boll_dn 的极值/确认）

3) 触及判定（建议参数化，便于统一实现与回测对齐）：
- 触及中轨：当一根 K 的 [low, high] 覆盖 boll_mb，记为 touch_mb
- 触及上轨：当 high >= boll_up（或 high > boll_up），记为 touch_up
- 触及下轨：当 low <= boll_dn（或 low < boll_dn），记为 touch_dn
- “进入轨内”：建议定义为 close 在 [boll_dn, boll_up] 内（避免假触及）

二、形态方向（对称规则）
以 A 出现在上轨侧为例（做空侧的三角），下轨侧完全对称：
- A：上轨外区域的关键点（Y 类），确认后表示上方阶段性极值已形成
- B：下轨侧区域的关键点（X 类），确认后表示下方转折区域出现
- C：再次回到上轨侧（Y 类），只要触及上轨即认为 C 出现（不需要确认）

三、状态机（建议实现为单周期、单 symbol 的有限状态机）
状态：
S0 寻找临时 A
S1 A 已确认，寻找临时/已确认 B 序列
S2 已出现 C（ABC 完成），等待结束条件 -> 进入下一轮

四、A 点规则
1) 临时 A 的产生：
- 从任意“上轨外关键点”开始，选极值作为临时 A（上轨侧取最高价，下一根刷新则更新临时 A）

2) A 的确认：
- 当价格从轨外回到轨内，并触及中轨（touch_mb 且 close 在轨内），临时 A 确认为 A
- A 确认时刻建议取：
  - A 的 time/price：使用临时 A 极值所在那根 K（或对应的 Y 关键点）
  - A_confirm_time：使用触及中轨那根 K（用于后续逻辑窗口）

五、B 点规则（可以有多个：B1,B2,B3…）
1) B 区域的开始条件：
- A 确认后，行情向对侧运行，出现“触及下轨（touch_dn）”视为进入 B 区域

2) 临时 B：
- 在触及下轨之后出现的第一个下轨侧关键点作为临时 B（对应 X 点）
- 若后续出现更低的极值/关键点，可更新临时 B（并记录刷新次数，用于破坏规则）

3) B 的确认：
- 价格从下轨侧反弹并触及中轨（touch_mb 且 close 在轨内）确认该 B
- 每一次“触及下轨 -> 反弹触及中轨”，都可以新增一个已确认 B（形成 B1,B2,B3…）

六、C 点规则
1) C 的出现：
- 在至少一个 B 已确认之后，价格运行到 A 同侧（上轨侧）并触及上轨（touch_up）
- C 不需要确认：第一根触及上轨的 K 即定义为 C

2) C 与下一轮 A 的关系：
- 若 C 出现后价格回踩中轨（touch_mb 且 close 在轨内），则：
  - 当前 ABC 认为已经走完
  - 该 C 可作为下一轮 ABC 的 A，并在回踩中轨时同时完成“下一轮 A 的确认”

七、不完整（不完美）形态
1) 只有 A，没有 B：
- A 确认后，行情未触及下轨（touch_dn）就回到上轨侧形成 C
- 该情形仍可记为“不完美 ABC”（A->C），需要在策略里明确它是否可交易、如何控风险

2) 只有 AB，没有 C：
- B 形成后被破坏（见下文 B 破坏规则），导致 C 无法形成
- 此时应关闭当前以 A 为起点的形态，并回到寻找新 A

八、破坏 / 失效规则（需要参数化）
1) A 点破坏（A_confirm 后发生）：
- A_confirm 后，价格没有触及 A 对侧布林线（上轨侧 A 的对侧是下轨），却再次触及 A 同侧布林线（再次 touch_up）
- 满足该条件则认为 A 被破坏，回到寻找 A
- 存储建议：不新增新形态记录，而是更新当前形态的 A（节省表空间）

2) B 点破坏（B 区域内或 B1_confirm 后发生）：
（1）刷新临时 B 新低次数达到阈值（默认 3 次）
- 需明确“刷新”的定义：当出现新的极值低于当前临时 B.price（可加最小差值阈值），计数 +1
- 达到阈值则判定 B 破坏：关闭当前形态，回到寻找 A

（2）强势下跌趋势破坏（轨外运行 + 下轨斜率陡增）
- 条件建议拆成两个可参数化指标：
  - outside_dn_bars：连续 N 根 close 在 boll_dn 以下（或 low < boll_dn）视为“长时间轨外”
  - dn_slope：boll_dn 在 M 根内的相对变化率超过阈值（例如 (dn_now - dn_Mago)/dn_Mago < -slope_pct）
- 两者同时满足则判定 B 破坏

3) 趋势冲击破坏（单根大阳/大阴，或连续几根涨跌幅超过“中轨到轨道”的距离）
适用场景：
- 人眼看到“突破上轨/下轨 + 一根（或几根）非常强的趋势 K”，通常意味着进入趋势延伸，当前 ABC 应视为失效/结束。

基础距离定义（需 boll_mb/boll_up/boll_dn 非空且带宽有效）：
- dist_up = boll_up - boll_mb
- dist_dn = boll_mb - boll_dn

（1）单根冲击（Impulse-1）判定（上轨侧为例，下轨侧对称）
- 触发门槛（上轨侧）：
  - close > boll_up（收盘在上轨外）
  - body = |close - open|，range = high - low
  - body >= dist_up * impulse_body_ratio 或 range >= dist_up * impulse_range_ratio
  - 可选“趋势形态过滤”：阳线（close > open）且 body/range >= body_to_range_ratio
- 下轨侧对称：
  - close < boll_dn
  - body/range 与 dist_dn 对比

（2）多根冲击（Impulse-N）判定（最近 N 根）
- 定义：move_N = |close_now - open_Nago|
- 上轨侧触发（示例）：
  - 至少有 1 根 close > boll_up（或 high >= boll_up）
  - move_N >= dist_up * impulseN_ratio
  - 可选：N 根内阳线占比 >= bull_ratio_min（过滤震荡噪声）
- 下轨侧对称：
  - 至少有 1 根 close < boll_dn（或 low <= boll_dn）
  - move_N >= dist_dn * impulseN_ratio

触发后的处理建议：
- 直接判定“ABC 结束/失效”，关闭当前结构并回到寻找新 A（原因可记为 IMPULSE_BREAK）。
  - 若已在 S2（已出现 C）：可视为“C 后强势延伸结束”（等价于九(2) 的一种更稳健量化）。
  - 若在 S1（A 已确认但尚未形成 C）：可视为“趋势接管”，避免继续等待 B/C。

参数建议（先给默认，后续按周期调）：
- impulse_body_ratio：0.8
- impulse_range_ratio：1.2
- body_to_range_ratio：0.6
- impulse_N：3（或 5）
- impulseN_ratio：1.2

九、C 点结束形态条件
满足任一则认为当前 ABC 结束：
1) C 出现后回踩中轨（touch_mb 且 close 在轨内） -> 关闭当前 ABC，并开启下一轮
2) C 出现后继续强势突破 A（同侧加速延伸）且不回头：
- 需要量化“突破 A”的标准，例如：
  - high 超过 A.price * (1 + break_pct)（上轨侧 A 的情况）
  - 或连续 K 根收盘在 boll_up 之外（趋势延伸）
- 触发后认为该段 ABC 已结束（避免一直挂在 S2）

十、多周期关系（建议写入引擎的顶层策略约束）
- 小周期（1m）B 破坏，可能对应更大周期（5m/15m/1h/4h）正在形成新的三角结构。
- 引擎层建议：同一时刻在多个周期维护各自 ABC 状态，并允许“上层周期优先级”覆盖下层信号：
  - 例如：当 5m 的 A 已确认且 5m 的 B 破坏条件触发时，暂停 1m 的 B 做多逻辑。

十一、落库建议（为后续实现预留）
1) 建议单独表 abc_structures（每个 symbol + interval 一条 ACTIVE 结构）：
- id, symbol, interval, status(ACTIVE/CLOSED)
- a_point, b_points[], c_points[]（jsonb，元素包含 time/price/label/source）
- a_confirm_time, last_state, break_reason, break_condition
- created_at, updated_at

2) 与 oscillation_structures 的关联：
- 记录引用的 structure_id 以及引用的 X/Y label（便于回溯）

十二、必须补齐的“可执行定义”（实现前需确定）
- touch 判定：用 high/low 判“触及”，用 close 判“进入轨内”（已确定）
- 关键点来源：完全复用 oscillation_structures 的 X/Y（已确定）
- B 新低刷新计数：需要最小差值阈值，避免噪声触发（已确定）
- 布林带斜率体系：需要一套通用的斜率/趋势识别（用于 B 破坏、大趋势/小趋势判断等）
- 不完美 ABC（A->C）处理：引入“临时 B 点”
  - A_confirm 后，价格首次触及中轨后，在中轨附近出现的关键点作为临时 B
  - 后续若出现更低的关键点/极值，则用“不断新低”更新临时 B
  - 当价格触及 C 点时，即使未触及对侧布林线，也有可用的 B 参考点
  - 交易层：C 点仍是做空区间；B 点交易跳过（不做）

