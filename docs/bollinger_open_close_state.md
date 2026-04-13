# 布林带“开口 / 缩口”状态机（基于带宽极值）

目标：
- 为每根 K 线打上一个布林带状态：开口（EXPAND）或缩口（CONTRACT）
- 在状态推进过程中，记录并维护“极值点”（开口峰值 / 缩口谷值），用于后续策略与结构识别复用

适用输入（每根 bar）：
- `boll_up` / `boll_dn` / `boll_mb`（可选）
- `time`

核心定义：
- 带宽：`bw = boll_up - boll_dn`
- 归一化带宽（推荐）：`bw_pct = bw / boll_mb`（减少不同价格阶段的尺度影响）

---

## 1. 状态直觉与交易含义（为什么有效）

观察规律（经验性）：
- 价格波动放大（趋势启动/加速）时，布林带带宽倾向于持续扩大：这段可视为“开口”
- 价格进入横盘/震荡整理时，波动收敛，带宽倾向于持续收窄：这段可视为“缩口”

因此，带宽序列 `bw(t)` 往往在两个阶段之间切换：
- 开口阶段：不断刷新“更大的带宽极值”（峰值在上涨/下跌扩张后出现）
- 缩口阶段：不断刷新“更小的带宽极值”（谷值在整理收敛后出现）

---

## 2. 极值点与状态输出（你要记录什么）

在任意时刻，仅维护一组“当前状态的极值”即可：

### 2.1 开口（EXPAND）
- 维护开口峰值：`expand_peak_bw` 与 `expand_peak_time`
- 规则：只要 `bw` 继续创新高（超过峰值 + eps），就刷新峰值
- 直觉：趋势/波动在扩张，系统处于“开口”

### 2.2 缩口（CONTRACT）
- 维护缩口谷值：`contract_trough_bw` 与 `contract_trough_time`
- 规则：只要 `bw` 继续创新低（低于谷值 - eps），就刷新谷值
- 直觉：波动在收敛，系统处于“缩口”

### 2.3 每根 bar 的建议输出字段
- `mouth_state`: `EXPAND | CONTRACT`
- `extreme_bw`: 当前状态下的极值（峰值或谷值）
- `extreme_time`: 极值发生时间
- `state_start_time`: 当前状态起点时间（发生状态切换的时间点）
- `bw_now` / `bw_pct_now`
- `since_state_start_bars`: 状态持续长度（bars）

---

## 3. 状态切换规则（关键：何时从开口变缩口、从缩口变开口）

仅靠“是否创新高/创新低”会对噪声非常敏感。建议使用“极值 + 反向突破 + 确认”三件套：
- 极值：当前状态持续维护的峰/谷
- 反向突破：带宽从峰值回落到一定幅度，或从谷值回升到一定幅度
- 确认：连续 K 根都满足（或使用最小持续 bars）避免抖动

### 3.1 参数（建议）
- `use_bw_pct`: 是否用 `bw_pct` 替代 `bw`（推荐 true）
- `eps`: 极值刷新最小差值（去掉微小抖动）
  - 用 bw_pct 时：例如 `eps = 0.0001 ~ 0.0005`
  - 用 bw 时：按品种/周期给一个最小价格尺度
- `switch_delta`: 反向突破阈值（决定切换敏感度）
  - 用 bw_pct 时：例如 `switch_delta = 0.001 ~ 0.005`
- `confirm_bars`: 切换确认根数（例如 2~5）
- `smooth_n`（可选）：对 bw/bw_pct 做轻微平滑（例如 EMA 3~5），降低尖刺

### 3.2 从开口（EXPAND）切到缩口（CONTRACT）

状态为 EXPAND 时维护峰值：
- 若 `bw_now >= expand_peak_bw + eps`：刷新峰值，保持 EXPAND

否则（未刷新峰值）：
- 若 `bw_now <= expand_peak_bw - switch_delta` 连续满足 `confirm_bars` 根：
  - 切换到 CONTRACT
  - `contract_trough_bw = bw_now`，`contract_trough_time = time_now`
  - `state_start_time = time_now`

含义：
- 带宽从峰值明显回落并稳定，认为进入收敛段（横盘/整理概率上升）

### 3.3 从缩口（CONTRACT）切到开口（EXPAND）

状态为 CONTRACT 时维护谷值：
- 若 `bw_now <= contract_trough_bw - eps`：刷新谷值，保持 CONTRACT

否则（未刷新谷值）：
- 若 `bw_now >= contract_trough_bw + switch_delta` 连续满足 `confirm_bars` 根：
  - 切换到 EXPAND
  - `expand_peak_bw = bw_now`，`expand_peak_time = time_now`
  - `state_start_time = time_now`

含义：
- 带宽从谷值明显回升并稳定，认为波动重新扩张（趋势启动/突破概率上升）

---

## 4. 推荐的“极值事件点”定义（用于策略/结构）

为了更像“结构化的关键点”，建议把极值点当成事件输出：

- `EXPAND_START`: 从 CONTRACT 切到 EXPAND 的那根 bar（或确认完成的最后一根）
- `EXPAND_PEAK`: EXPAND 阶段内最后一次刷新峰值的时间点（峰值极值点）
- `CONTRACT_START`: 从 EXPAND 切到 CONTRACT 的那根 bar（或确认完成的最后一根）
- `CONTRACT_TROUGH`: CONTRACT 阶段内最后一次刷新谷值的时间点（谷值极值点）

这些点可以直接作为后续引擎的输入，例如：
- 用 `EXPAND_START` 作为“行情启动”的候选时刻
- 用 `EXPAND_PEAK` 与 `CONTRACT_TROUGH` 构建波动周期的“摆动幅度”

---

## 5. 伪代码（逐根 K 线更新）

假设使用 `v = bw_pct`（也可用 bw）：

```text
state ∈ {EXPAND, CONTRACT}

if state == EXPAND:
  if v >= peak + eps:
    peak = v; peak_time = t
    drop_streak = 0
  else:
    if v <= peak - switch_delta:
      drop_streak += 1
      if drop_streak >= confirm_bars:
        state = CONTRACT
        trough = v; trough_time = t
        state_start_time = t
        rise_streak = 0
        drop_streak = 0
    else:
      drop_streak = 0

if state == CONTRACT:
  if v <= trough - eps:
    trough = v; trough_time = t
    rise_streak = 0
  else:
    if v >= trough + switch_delta:
      rise_streak += 1
      if rise_streak >= confirm_bars:
        state = EXPAND
        peak = v; peak_time = t
        state_start_time = t
        rise_streak = 0
        drop_streak = 0
    else:
      rise_streak = 0
```

实现细节建议：
- 初始化：可以用前 N 根计算初始 `v` 的趋势，或简单以“先 CONTRACT”开始，并把 trough/peak 初始化为第一根
- `eps` 与 `switch_delta` 分工：`eps` 用于“是否刷新极值”，`switch_delta` 用于“是否足够反向突破触发切换”

---

## 6. 去噪与边界情况（务必补齐）

### 6.1 大波动尖刺（假开口/假缩口）
- 现象：单根异常波动把带宽拉大/拉小，随后迅速回归
- 处理：
  - 采用 `smooth_n`（轻微平滑）
  - 提高 `confirm_bars`
  - 使用 `switch_delta`（必须“明显回落/回升”才切换）

### 6.2 不同周期 / 不同价格尺度
- 用 `bw_pct` 可以显著减少阈值随价格抬升而失效的问题
- 阈值仍建议按 interval 给默认（例如 1m/5m/15m 各一套）

### 6.3 长时间极窄横盘
- CONTRACT 会不断刷新谷值直到很低
- 一旦出现波动恢复（突破谷值 + switch_delta 并确认），就切回 EXPAND

### 6.4 单边趋势中的“缓慢收敛”
- 可能出现价格仍缓慢上行/下行，但带宽开始收敛
- 这符合你的观察：行情“到一定程度趋于横盘”，带宽进入缩口
- 若你希望趋势中不轻易判缩口：提高 `confirm_bars` 或加一个额外条件（例如 mb_slope 仍很大则延迟切换）

---

## 7. 与“方向”解耦（开口/缩口不等于涨/跌）

该状态机只描述“波动是在扩张还是收敛”，不描述方向。

若你还需要同时输出方向（UP/DN/RANGE），建议组合：
- `mouth_state`（EXPAND/CONTRACT）
- `mb_slope` 或 `price_slope`（方向）
- `outside_up/down`（是否轨外延伸）

组合例子：
- `EXPAND + mb_slope>0`：上行趋势启动/加速概率更高
- `CONTRACT + |mb_slope|小`：震荡横盘概率更高

