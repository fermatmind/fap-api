# Audit Log

## 字段口径
- action: 动作标识（如 `cache_invalidate` / `content_release_probe`）
- target_type: 目标类型（如 `ContentRelease` / `AdminUser`）
- target_id: 目标 ID（字符串）
- meta_json: 补充信息（method/path/params_sanitized/status_intended 等）
- ip: 请求来源 IP
- user_agent: 请求 UA
- request_id: 请求链路标识
- created_at: 记录时间

## 必须写入审计的动作（最小集合）
- cache_invalidate
- content_release_probe
- role_update / permission_update
- admin_user_disable / admin_user_enable
- rollback / deploy 相关操作
- admin_bootstrap_owner

## 导出
- Filament Audit Logs 页面支持导出 CSV（默认导出最近 1000 条）
- API 侧可通过 `/api/v0.2/admin/audit-logs` 分页查询后导出

