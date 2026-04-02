# Admin Ops Runbook

## Bootstrap

- 首个 Ops 管理员与首个 org 的正式 bootstrap/runbook 请先看：`docs/04-ops/ops-bootstrap.md`
- 当前正确登录入口是 `/ops/login`
- 不要把 `App\Models\User` 当成 Ops 登录账号
- 不要把 `/ops/organizations-import` 当成已自动化完成的 org import 流程

## 专项 Runbook

- MBTI desktop clone 顶部身份 `profile_identity` 线上缺失 / production republish：`backend/docs/ops/mbti-desktop-clone-profile-identity-republish.md`

## 健康检查（Healthz Snapshot）
1) API：`GET /api/v0.3/admin/healthz/snapshot`
2) 预期：返回最新 `ops_healthz_snapshots` 记录，`ok=true` 为绿色
3) UI：Admin Console 首页 HealthzStatusWidget

## 探针（Probe）
1) UI：Content Releases 列表 → `Probe`
2) API：`POST /api/v0.3/admin/content-releases/{id}/probe`
3) 预期：返回 `health/questions/content_packs` 的探针结果

## 清缓存（Invalidate）
1) API：`POST /api/v0.3/admin/cache/invalidate`
2) Body 示例：`{"pack_id":"MBTI-CN","dir_version":"v0.3","scope":"pack"}`
3) 预期：返回清理的 cache keys 列表

## 回滚
- 沿用现有内容发布回滚 SOP（参考 `docs/content_ops_playbook.md`）

## 常见故障
- Token 不匹配：检查 `FAP_ADMIN_TOKEN` 与请求 Header（`X-FAP-Admin-Token`）
- Admin 用户被禁用：在 Admin Users 中启用或重新 bootstrap
- 会话失效：重新登录 `/ops/login`，或改用 token 调用 API
