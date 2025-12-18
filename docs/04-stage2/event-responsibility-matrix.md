> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Events v0.2-B
> Related Docs:
> - docs/04-stage2/analytics-stage2-funnel-spec.md
> - docs/03-stage1/api-v0.2-spec.md
> - docs/03-stage1/fap-v0.2-glossary.md

# Event Responsibility Matrix（事件责任矩阵）— v0.2-B

目的：明确每个 `event_code` 由谁触发、何时触发、如何去重/去抖，保证埋点口径稳定、周报可复盘、后续迭代不扯皮。  
适用范围：Stage 2（V0.2-B）MBTI 主流程 + 用户权益最小通道。

---

## 1. 通用约定（v0.2-B）

### 1.1 字段约定
- **event_code**：事件代码（详见 `docs/03-stage1/fap-v0.2-glossary.md`）
- **trigger_side**：`frontend` / `backend`
- **trigger_time**：触发时机（接口成功返回 / 页面渲染完成 / 按钮点击成功等）
- **dedup_key**：去重口径（建议组合键）
- **throttle_window**：去抖窗口（例如 10s；无则填 `-`）
- **notes**：补充说明（是否计入周报、是否允许重复、失败是否上报等）

### 1.2 建议统一去重字段（写入 events.meta_json）
为确保可去重、可追踪，建议前端/后端在 `meta_json` 中尽量携带：
- `page_session_id`：前端生成；每次页面打开生成一个 UUID（进入页面时生成，离开页面作废）
- `attempt_id`：提交成功后才有（UUID）
- `scale_code` / `scale_version`
- `profile_version` / `content_package_version`（若已接入内容包）
- `share_style`：分享模板风格标识（如 `moments_poster_v1`）
- `request_id`：用户权益请求创建成功后返回（用于权益事件去重与审计）

> 注：v0.2-B 允许“先约定口径，后补实现”。先把字段写进矩阵，前后端逐步补齐上报即可。

### 1.3 周报核心口径（默认）
v0.2-B 核心漏斗事件：
- `scale_view` → `test_start` → `test_submit` → `result_view` → `share_generate`

v0.2-B 用户权益最小通道事件（不一定进入漏斗，但要可审计）：
- `delete_request_submit`
- `export_request_submit`

---

## 2. 事件责任矩阵（v0.2-B）

| event_code | trigger_side | trigger_time | dedup_key | throttle_window | notes |
|---|---|---|---|---|---|
| **scale_view** | frontend | 量表入口页/落地页 **渲染完成且首屏可交互**（建议 onShow 后首屏 ready） | `anon_id + scale_code + page_session_id` | - | 只记“进入一次页面”一次；下拉刷新不重复记；用于衡量入口曝光；建议 meta 带 `channel/region/locale/client_platform/client_version`。 |
| **test_start** | frontend | 用户点击「开始测试/立即测评」并 **成功进入答题流程**（例如 navigate 成功或答题页 ready） | `anon_id + scale_code + page_session_id` | - | 不要在仅进入答题页就打点，避免误计（误触/秒退）；用于衡量开始意愿；可在 meta 带 `question_count`。 |
| **test_submit** | backend | `POST /api/v0.2/attempts` **事务成功提交后**（attempts+results 写入成功） | `attempt_id` | - | 必须后端触发，确保与 attempts/results 强一致；事务失败回滚则不记；可在 meta 带 `scale_version/profile_version/content_package_version/type_code`（如可）。 |
| **result_view** | frontend | 结果页 `GET /attempts/{id}/result` **成功返回** 且页面 **完成渲染**（首屏关键模块 ready） | `anon_id + attempt_id + event_code` | **10s** | **强制去抖**：同一 `anon_id+attempt_id` 在 10 秒内只允许写 1 条；超出直接丢弃不报错（防止漏斗被放大）。可在 meta 带 `page_session_id/profile_version/content_package_version/type_code`。 |
| **share_generate** | frontend | 用户点击「生成分享卡/保存图片」并且 **图片生成成功**（canvas 导出成功/保存成功） | `anon_id + attempt_id + share_style + page_session_id` | - | 只记“生成成功”；生成失败不记；share_style 用于区分模板（朋友圈/群聊）；用于衡量分享资产产出；meta 带 `share_style/template_version`。 |
| **share_click** | frontend | 用户点击「分享」按钮或触发“转发入口”（不要求真正分享成功） | `anon_id + attempt_id + share_style + page_session_id` | - | 用于 Share CTR 的分子口径；不强求平台分享回调成功。 |
| **delete_request_submit** | backend | 用户权益接口创建成功（`type=delete`）后 | `request_id` | - | 合规审计链路，必须后端触发；meta 建议带 `anon_id`（或其哈希）与请求范围；避免存敏感明文；后续可扩展 `delete_request_fulfilled`。 |
| **export_request_submit** | backend | 用户权益接口创建成功（`type=export`）后 | `request_id` | - | 同上；导出完成后续可扩展 `export_request_fulfilled`（v0.2-B 暂不要求）。 |

---

## 3. 强一致性与边界规则（必须遵守）

### 3.1 “强一致”事件（只能后端触发）
- `test_submit`：必须由后端在事务成功后写入（避免“前端以为成功但 DB 回滚”的假数据）
- `delete_request_submit` / `export_request_submit`：必须由后端触发（用于审计与追踪）

### 3.2 “体验型”事件（前端触发更合理）
- `scale_view` / `test_start` / `result_view` / `share_generate` / `share_click`：以用户真实交互与页面完成渲染为准

### 3.3 失败是否上报
v0.2-B 默认：
- **只上报成功事件**（避免周报口径复杂化）
- 若未来需要失败分析，再引入 `*_failed` 事件（例如 `share_generate_failed`）并单独统计

---

## 4. 最小验收（写完矩阵后你要能验证）

### 4.1 手工走一遍漏斗（最小闭环）
1) 打开入口页 → 触发 `scale_view`  
2) 点击开始 → 触发 `test_start`  
3) 提交答案成功 → 后端触发 `test_submit`  
4) 打开结果页渲染完成 → 触发 `result_view`（10 秒内重复访问不应重复写入）  
5) 生成分享卡成功 → 触发 `share_generate`  
6) 点击分享入口 → 触发 `share_click`（可选，但建议做）

### 4.2 去重/去抖验证（关键）
- 同一 attempt：在 10 秒内多次请求 result：`result_view` 只新增 1 条
- 超过 10 秒再次进入：允许新增 1 条 `result_view`
- 同一次生成分享卡：`share_generate` 只出现 1 次（同 `share_style + page_session_id`）

---

## 5. 未来扩展（非 v0.2-B 必做，仅占位）
- `share_success`（平台分享成功回调，微信对这块有限制，可谨慎）
- `delete_request_fulfilled` / `export_request_fulfilled`（权益请求处理完成）
- `report_view`（若将“报告页/深读页”拆分多页面）

> 规则：新增事件必须先改本文件，再改 glossary，再落到前后端实现。
