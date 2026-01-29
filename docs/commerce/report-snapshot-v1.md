# Report Snapshot v1 (Teaser Paywall + Immutable Purchase)

## 1) 目标
- **Teaser 付费墙**：未购买仅返回裁剪后的 report（locked=true）。
- **已购永恒**：已购买仅从 `report_snapshots` 读取 full report，保证内容包升级不影响历史报告。

## 2) 数据结构（report_snapshots）
- `org_id` (bigint, index)
- `attempt_id` (string, UNIQUE)
- `order_no` (string, nullable, index)
- `scale_code` (string, index)
- `pack_id` (string)
- `dir_version` (string)
- `scoring_spec_version` (string, nullable)
- `report_engine_version` (string, fixed: `v1.2`)
- `snapshot_version` (string, fixed: `v1`)
- `report_json` (json)
- `created_at` (timestamp)

约束/索引：
- `UNIQUE(attempt_id)`
- `INDEX(org_id)`
- `INDEX(order_no)`
- `INDEX(scale_code)`

## 3) 写入时机（仅两条路径）
- **支付回调**（report_unlock paid + entitlement 成功后）：创建 snapshot
- **credit consume**（submit 成功且 consume 成功后）：创建 snapshot

> 禁止在 GET /report 内写入 snapshot。

## 4) 只读原则
- **GET /api/v0.3/attempts/{id}/report** 只读：
  - 有权益：只读 `report_snapshots`
  - 无权益：现场生成 full report，再按 `view_policy_json` 裁剪为 teaser

## 5) 幂等策略
- `report_snapshots.attempt_id UNIQUE`（重复写入直接回读）
- `payment_events.provider_event_id UNIQUE`（webhook 幂等）
- `benefit_consumptions` 对 attempt consume 幂等

## 6) 回滚策略
- 若临时关闭付费墙：
  - 直接返回 full report（locked=false）
  - `report_snapshots` 保留，继续只读

## 7) 常见故障排查
- `report_snapshot_missing`：
  - entitlement 已存在但 snapshot 缺失；检查支付/consume 写入链路
- `view_policy_json` 缺失：
  - 使用默认策略（free_sections=intro/score, blur_others=true, teaser_percent=0.3）
- 跨 org 404：
  - OrgContext 收口严格，跨 org 的 attempt 统一 404
