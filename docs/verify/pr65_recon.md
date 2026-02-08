# PR65 Recon

- Keywords: PaymentWebhookController|BillingWebhook|payment_events

## 相关入口
- 路由入口：`POST /api/v0.3/webhooks/payment/{provider}`
- 控制器：`backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php`
- 服务层：`backend/app/Services/Commerce/PaymentWebhookProcessor.php`
- 迁移：`backend/database/migrations/2026_02_08_000001_add_provider_composite_unique_to_payment_events.php`

## 现状确认
- `PaymentWebhookProcessor` 已使用 provider 维度锁与查询：
  - `Cache::lock("webhook_pay:{$provider}:{$providerEventId}", ...)`
  - `where('provider', $provider)->where('provider_event_id', $providerEventId)`
- `billing` 签名已使用 `timestamp.rawBody` 与 tolerance 校验。
- 当前不满足 PR65 要求的点：secret 缺失路径曾返回 503，需要改为统一 404 并写固定日志锚点。

## 本次落地点
- billing secret 缺失：记录 `CRITICAL: BILLING_WEBHOOK_SECRET_MISSING`，并直接统一 404。
- replay 防护回归测试：缺 timestamp/超窗/签名不匹配/签名正确。
- payment_events 索引：确保 `unique(provider, provider_event_id)`。
- provider 隔离回归测试：同 event id 跨 provider 可并存；同 provider 重放走 duplicate。

## 风险点与规避
- 端口占用：验收脚本固定清理 `1865` 与 `18000`。
- sqlite fresh 兼容：新增迁移仅改索引，不 drop table。
- 404 口径：billing secret 缺失、签名失败、timestamp 缺失均统一 404。
- 脱敏：产物统一走 `backend/scripts/sanitize_artifacts.sh 65`。
- pack/seed/config 一致性：verify 脚本使用 `php -r` 校验 `content_packs`、`scales_registry`、包目录文件可读。
