> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Analytics v0.2-B
> Related Docs:
> - docs/04-stage2/README.md
> - docs/04-stage2/event-responsibility-matrix.md
> - docs/04-stage2/stage2-acceptance-playbook.md
> - docs/03-stage1/fap-v0.2-glossary.md
> - docs/03-stage1/api-v0.2-spec.md

# Stage 2 数据与漏斗规范（Funnel + Weekly Ritual）

本文件定义 Stage 2（V0.2-B）的最小事件集、字段口径、漏斗计算口径与周复盘流程。  
要求：与 Stage 1 的 events 词典口径一致（术语、字段、命名、envelope）。

---

## 1. Scope（范围）

- Stage 2 目标：跑通“测评 → 报告 → 分享 → 增长”的稳定回路
- 本文只定义：
  - 事件最小集（event_code）
  - 事件字段口径（必带/可选）
  - 漏斗指标公式（分子分母）
  - 去重/去抖规则（防止指标被放大）
  - Weekly Ritual（周复盘输出物）

---

## 2. Stage 2 最小事件集（Minimum Event Set）

建议最小事件集如下（按漏斗顺序）：

- scale_view
- test_start
- test_submit（后端写入）
- result_view
- report_view（可选：深读页/长文页）
- share_generate
- share_click
- feedback_submit（可选）
- payment_intent（占位）

> 注意：Stage 2 的漏斗闭环，至少要保证 `test_submit` 与 `result_view` 的口径稳定可复盘。

---

## 3. 事件字段口径（摘要）

详见 Stage 1 events 规范与术语词典：  
- docs/03-stage1/api-v0.2-spec.md  
- docs/03-stage1/fap-v0.2-glossary.md

### 3.1 必带字段（Required）
每条 events 必须尽量带齐：
- anon_id（匿名用户标识）
- user_id（预留，可空）
- openid（预留，可空）
- scale_code（例如 MBTI）
- scale_version（例如 v0.2）
- attempt_id（可空：但推荐尽量带，漏斗口径需要）
- region / locale
- source / channel
- occurred_at（事件发生时间，统计以此为准）

### 3.2 可选字段（Optional）
- type_code（若已知）
- client_platform / client_version（若能拿到）
- meta_json（json）：建议收敛字段命名，不要随意塞杂物
  - duration_ms（测评耗时、页面停留等）
  - error_code / error_message（错误统计）
  - share_target（朋友圈/微信群/复制链接等）
  - experiment_id / variant_id（AB 占位）

---

## 4. Event Dedup Rules（事件去重/去抖口径）

### 4.1 result_view（10 秒去抖，强制）
**Why（原因）**  
结果页可能因为刷新/回退/并发请求导致重复触发，造成 `result_view` 在同一时间窗口内写入多条，从而放大漏斗与停留指标。

**Rule（强制口径）**  
对 `event_code = result_view` 应用 10 秒去抖（throttle）：

- Dedup Key：`anon_id + attempt_id + event_code`
- Window：以 `occurred_at` 为准的 10 秒窗口
- 行为：
  - 若同一 Dedup Key 在 10 秒窗口内已写入过 `result_view`，则本次写入 **直接丢弃**
  - **不报错、不影响接口返回**（业务照常返回 result）

**Scope（适用范围）**  
仅对 `result_view` 生效。`test_submit` / `share_generate` / `share_click` 等保持“每次动作一次事件”的语义，不做去抖。

---

## 5. 漏斗指标（Stage 2 核心口径）

### 5.1 核心指标（必须每周复盘）
- UV/PV：scale_view
- Starts：test_start
- Completes：test_submit
- Result Rate：result_view / test_submit
- Share Rate：share_generate / result_view
- Share CTR：share_click / share_generate

### 5.2 质量指标（建议每周一起看）
- error_rate：含 error_code 的事件占比（或按接口错误统计）
- avg_duration：
  - test duration：从 test_start 到 test_submit（如可算）
  - result dwell：result_view 的 duration_ms（如有）

> 备注：所有漏斗/指标以“时间窗口内的事件”为准（occurred_at），并在周报里明确统计区间。

---

## 6. Weekly Ritual（周仪式）

### 6.1 频率与负责人
- 频率：每周一固定
- Owner：产品负责人/运营（可由 liufuwei 暂代）

### 6.2 Checklist（10 分钟版）
1) 运行 weekly-report（最近 7 天）
2) 填 D1 指标（本周值 + WoW）
3) 记录本周动作（投放/渠道/内容）
4) 写 3 行结论：
   - best metric
   - worst metric
   - next experiment

### 6.3 输出归档（必须）
- 归档路径：`docs/weekly/2025-Wxx.md`
- 每周至少写：
  - 本周统计区间
  - 核心漏斗指标
  - 本周动作
  - 3 行结论 + 下周实验

---

## 7. Acceptance（验收清单）

满足以下条件即可认为本规范在 Stage 2 生效：

- [ ] Stage 2 最小事件集已在产品链路中产生（至少 test_submit + result_view）
- [ ] `result_view` 去抖口径已写入本规范（4.1）并在实现侧遵循（或明确待实现）
- [ ] 周报输出物能按 `docs/weekly/2025-Wxx.md` 归档至少 2 周
- [ ] 漏斗口径（分子/分母）写死且与周报一致，不随口头变化
