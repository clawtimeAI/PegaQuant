# 震荡结构识别（Oscillation Engine）移植开发文档（面向 Webman）

本文档用于把本项目后端的「震荡结构识别」功能，移植到 Webman(PHP) 后端（你的上游负责从币安获取 K 线）。目标是在「获取/落库 K 线」后，同步完成震荡结构体识别，并把结构状态持久化，以便后续查询与增量继续计算。

对应 Python 实现入口：
- 识别引擎：`backend/app/services/oscillation_engine.py`
- 结构仓库：`backend/app/repositories/oscillation_repo.py`
- 数据模型：`backend/app/models.py`（`OscillationStructure`）
- 触发点：`backend/app/services/kline_stream_service.py`（K 线收盘后落库，再调用引擎）

---

## 1. 功能概述

### 1.1 输入

对某个 `symbol + interval` 的连续 K 线序列（必须包含）：
- `open_time`（K 线开盘时间，升序、唯一）
- `high`
- `low`
- `close`
- `boll_up`、`boll_dn`（布林带上下轨；建议与本项目一致：BOLL(400,2)）

说明：引擎的「带外/带内」判断依赖布林带上下轨。如果轨为 null，会显著减少识别结果（很多情况下直接无法确认关键点）。

### 1.2 输出

持久化到表 `oscillation_structures` 的结构数据：
- 当前 `ACTIVE` 结构的关键点序列：`x_points`、`y_points`
- 增量状态：`engine_state`
- 当触发破位：关闭当前结构（`CLOSED`）并开启一条新的 `ACTIVE`

---

## 2. 数据结构与字段语义

### 2.1 K 线行（引擎需要的最小字段）

引擎逐根处理 DB 读取出来的 `row`，需要字段：
- `open_time: datetime`
- `high: float`
- `low: float`
- `close: float`
- `boll_up: float | null`
- `boll_dn: float | null`

带内/带外判定（与 Python 逻辑一致）：
- 在带内：`boll_dn <= close <= boll_up`（任一为 null 则认为不在带内）
- 下穿下轨：`low < boll_dn`
- 上穿上轨：`high > boll_up`

### 2.2 OscillationStructure（结构记录）

Python 模型：`backend/app/models.py -> class OscillationStructure`

字段语义：
- `status`: `"ACTIVE"` 或 `"CLOSED"`
- `x_points`: `[{time, price, kind:"X"}...]`（关键低点序列）
- `y_points`: `[{time, price, kind:"Y"}...]`（关键高点序列）
- `engine_state`: 引擎增量状态（必须持久化，否则每次只能全量重算）
- `close_reason`: 当前实现固定 `"BREAK_DUAL"`
- `close_condition`: 记录破位触发条件（阈值、命中价、命中时间等）
- `start_time/end_time`: 结构起止（start_time 在首次确认关键点时补上）

### 2.3 关键点（Point）

引擎内部统一使用：
- `kind`: `"X"`（低点）或 `"Y"`（高点）
- `time`: 关键点归属 K 线的 open_time
- `price`: 极值价格（X 用 low，Y 用 high）

存储时结构数组元素形如：
```json
{ "time": "2026-01-01T00:00:00", "price": 123.45, "kind": "X" }
```

---

## 3. 参数（必须对齐才能复现同样结果）

来自 `backend/app/settings.py`：
- `osc_confirm_bars`：默认 `30`
  - 含义：候选关键点出现后，需要连续多少根回到布林带内，才确认该关键点
- `osc_break_pct`：默认 `0.05`
  - 含义：相对「最后一个关键点」的破位阈值比例
- `osc_break_extreme_pct`：默认 `0.02`
  - 含义：相对「历史极值关键点」的破位阈值比例
- `kline_boll_period`：默认 `400`
  - 含义：BOLL 周期，乘数固定 2（BOLL(400,2)）

---

## 4. 增量状态机（engine_state）

引擎为了支持增量计算，会把状态写入 `engine_state`：

- `last_processed_open_time`
  - 上次处理到的 K 线 open_time（下次从它之后继续）
- `pending`
  - 尚未确认的候选关键点（来自带外极值）
  - 形如：`{ kind: "X"|"Y", time: isoString, price: float }`
- `inband_count`
  - 候选点出现后，连续回到带内的根数计数（达到 confirm_bars 才确认）
- 以及落地时会把参数也记录（便于回放/排查）：
  - `confirm_bars`、`break_pct`、`break_extreme_pct`

---

## 5. 核心识别逻辑（逐根 K 线）

引擎逐根处理「已收盘」K 线（建议只喂已收盘，避免抖动）。

对每一根 K 线 `row`，执行顺序：
1) 破位检查（可能关闭结构并新开结构）
2) pending 更新（带外极值 → 回带内确认 → 形成关键点）

### 5.1 破位检查（BREAK_DUAL）

破位规则是“双阈值”同时满足：

#### X 侧破位（向下破）
前提：已有 X 点（`x_points` 非空）
- `last_x = 最后一个 X 点价格`
- `min_x = 历史所有 X 点中的最低价`
- `last_threshold = last_x * (1 - break_pct)`
- `extreme_threshold = min_x * (1 - break_extreme_pct)`

若同时满足：
- `row.low < last_threshold`
- `row.low < extreme_threshold`

则判定 X 侧破位，关闭当前结构。

#### Y 侧破位（向上破）
前提：已有 Y 点（`y_points` 非空）
- `last_y = 最后一个 Y 点价格`
- `max_y = 历史所有 Y 点中的最高价`
- `last_threshold = last_y * (1 + break_pct)`
- `extreme_threshold = max_y * (1 + break_extreme_pct)`

若同时满足：
- `row.high > last_threshold`
- `row.high > extreme_threshold`

则判定 Y 侧破位，关闭当前结构。

#### 破位后的动作
一旦破位（且当前结构 status 为 ACTIVE）：
1. 关闭当前结构（写 `end_time=row.open_time`、`close_reason="BREAK_DUAL"`、`close_condition=breakInfo`）
2. 立即创建新的 ACTIVE 结构（`start_time=row.open_time`）
3. 清空内存中的 `x_points/y_points/pending/inband_count`
4. 继续处理后续 K 线（新结构从此根开始）

### 5.2 pending 更新与关键点确认

核心思想：先捕捉“带外极值”为 pending，然后等待价格回到带内连续 N 根，确认 pending 为关键点。

#### 1) 下穿下轨（候选 X）
条件：`row.low < row.boll_dn`
- 若 pending 为空或不是 X：pending = `{kind:"X", time: row.open_time, price: row.low}`
- 若 pending 已是 X：只有当 `row.low` 更低才更新 pending
- `inband_count = 0`

#### 2) 上穿上轨（候选 Y）
条件：`row.high > row.boll_up`
- pending 同理（Y 用更高的 high）
- `inband_count = 0`

#### 3) 未穿轨（可能回带内）
否则（既不下穿也不上穿）：
- 若 `pending != null` 且 `close` 回到带内：
  - `inband_count++`
  - 当 `inband_count >= confirm_bars`：
    - 将 pending 转为关键点写入 `x_points` 或 `y_points`
    - 若 `active.start_time` 为空，则设置为该关键点时间
    - 清空 pending，并将 `inband_count = 0`
- 否则：`inband_count = 0`

---

## 6. 触发时机（Webman 推荐集成点）

Python 当前触发点（供对齐）：在收到一根 **收盘确认** K 线并落库后执行：
`upsert_klines(...) -> prune_old_klines(...) -> run_oscillation_engine(...)`

Webman 推荐触发点：
- “从币安同步到新的一根已收盘 K 线”并成功落库（含 boll_up/boll_dn）后
- 对该 `symbol + interval` 立即调用一次增量引擎：
  - `runEngine(symbol, interval, start=null, end=null)`

说明：增量引擎只会处理 `last_processed_open_time` 之后的 K 线，因此频繁调用是安全的。

---

## 7. Webman(PHP) 移植实现建议

### 7.1 需要实现/复用的模块
- K 线获取与落库（你已有）
- BOLL(400,2) 计算（建议落库到 K 线表，供引擎直接使用）
- `oscillation_structures` 表（字段含义按本项目保持）
- 引擎函数：
  - `runOscillationEngine($db, $symbol, $interval, $start=null, $end=null, $confirmBars=null, $breakPct=null, $breakExtremePct=null)`

### 7.2 增量执行必做点
- 读取当前 ACTIVE 结构（无则创建）
- 从 `engine_state.last_processed_open_time` 之后开始取 K 线（按 open_time 升序）
- 逐根处理，维护内存：
  - `pending`、`inband_count`、`x_points`、`y_points`
- 循环结束后写回 ACTIVE 结构：
  - `x_points/y_points`
  - `engine_state`（尤其 `last_processed_open_time/pending/inband_count`）

---

## 8. 伪代码（可直接翻译为 PHP）

```text
function runEngine(symbol, interval):
  confirmBars = cfg.confirmBars
  breakPct = cfg.breakPct
  breakExtremePct = cfg.breakExtremePct

  active = getActiveStructure(symbol, interval)
  if active == null:
    active = createActiveStructure(symbol, interval)

  state = active.engine_state or {}
  lastProcessed = parseTime(state.last_processed_open_time) or null
  pending = state.pending or null
  inbandCount = int(state.inband_count or 0)
  xPoints = active.x_points or []
  yPoints = active.y_points or []

  rows = queryKlines(symbol, interval, after_time=lastProcessed, orderBy=open_time asc)

  for row in rows:
    # 1) break check
    breakInfo = checkBreak(row, xPoints, yPoints, breakPct, breakExtremePct)
    if breakInfo and active.status == "ACTIVE":
      closeStructure(active, end_time=row.open_time, reason="BREAK_DUAL", condition=breakInfo)
      active.x_points = xPoints; active.y_points = yPoints
      active.engine_state = {last_processed_open_time: row.open_time, pending:null, inband_count:0}
      active = createActiveStructure(symbol, interval, start_time=row.open_time)
      xPoints=[]; yPoints=[]; pending=null; inbandCount=0

    # 2) pending update
    if row.boll_dn != null and row.low < row.boll_dn:
      pending = updatePendingX(pending, row.open_time, row.low)
      inbandCount = 0
    else if row.boll_up != null and row.high > row.boll_up:
      pending = updatePendingY(pending, row.open_time, row.high)
      inbandCount = 0
    else:
      if pending != null and row.boll_dn != null and row.boll_up != null and (row.close between [boll_dn, boll_up]):
        inbandCount++
        if inbandCount >= confirmBars:
          appendPoint(xPoints/yPoints, pending)
          if active.start_time is null: active.start_time = pending.time
          pending = null
          inbandCount = 0
      else:
        inbandCount = 0

    lastProcessed = row.open_time

  # persist active
  active.x_points = xPoints
  active.y_points = yPoints
  active.engine_state = {
    last_processed_open_time: lastProcessed,
    pending: pending,
    inband_count: inbandCount,
    confirm_bars: confirmBars,
    break_pct: breakPct,
    break_extreme_pct: breakExtremePct
  }
  save(active)
```

---

## 9. 常见坑位与对齐清单

1) 必须保证 K 线输入是“已收盘”序列（否则 pending/确认会抖动）
2) 必须保证 open_time 升序、连续、不重不漏（否则增量状态会乱）
3) 必须保证 `boll_up/boll_dn` 与 BOLL(400,2) 一致，否则点位密度与破位触发会显著不同
4) 破位是“双阈值”同时满足，不是单阈值
5) 必须持久化 `engine_state`，否则会变成每次全量重算

