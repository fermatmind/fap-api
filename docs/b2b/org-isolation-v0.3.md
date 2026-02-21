# B2B Org Isolation + OrgContext (v0.3)

## 目标与非目标
- 目标
  - 在 v0.3 路由中引入 Organization/Membership/Invite 与 OrgContext，确保 attempts/results/events/report_jobs 全链路按 org_id 隔离。
  - org_id=0 作为 public org，访问 scales 必须受 is_public=1 约束。
  - 跨 org 访问统一 404（避免信息泄露）。
- 非目标
  - 不实现复杂权限矩阵（仅 owner/admin/member）。
  - 不改动 v0.3 逻辑与数据结构（仅新增 org_id 字段与最小写入）。

## public org_id=0 策略与 is_public 规则
- org_id=0 = public 组织。
- org_id=0 访问 scales 仅允许 `is_public=1`。
- org_id>0 时：可访问本 org 的 scale + public scale（org_id=0 且 is_public=1）。

## OrgContext 解析优先级与错误口径
解析优先级（从高到低）：
1. `X-Org-Id` header
2. token claim org_id（来自 fm_tokens 的 org_id 或 meta_json.org_id）
3. fallback：org_id=0

校验口径：
- org_id=0：直接通过（public）
- org_id>0：必须有 user_id 且存在 organization_members 记录
- 非成员/无 user_id：统一返回 404（`ORG_NOT_FOUND`）
- 跨 org 查询统一 404（按 `org_id + id` 收口）

## DB 表结构与索引
### organizations
- 字段：id(bigint pk)、name(varchar)、owner_user_id(bigint)、created_at/updated_at
- 索引：owner_user_id

### organization_members
- 字段：id(bigint pk)、org_id、user_id、role(owner/admin/member)、joined_at、created_at/updated_at
- 约束：unique(org_id, user_id)
- 索引：org_id、user_id

### organization_invites
- 字段：id(bigint pk)、org_id、email、token(unique)、expires_at、accepted_at、created_at/updated_at
- 索引：org_id、email

## attempts/results/events/report_jobs 的 org_id 契约
- 所有写入与查询按 `org_id` 收口，跨 org 统一 404。
- 新增列默认值固定为 0，并补齐历史数据为 0。
- results 维持 UNIQUE(org_id, attempt_id)。
- 后续商业化/快照类表同样必须携带 org_id。

## 常见故障排查
- token 无法携带 org_id：优先使用 `X-Org-Id` header。
- membership 缺失导致 404：检查 `organization_members` 是否存在对应 (org_id, user_id)。
- public 访问 private scale 返回 404：确认 `scales_registry.is_public=1`。
