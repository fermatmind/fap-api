# PR57 Recon

- Keywords: PaymentWebhookController|webhooks/payment|BILLING_WEBHOOK_SECRET
- 相关入口文件：
  - backend/routes/api.php（/api/v0.3/webhooks/payment/{provider}）
  - backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php
- 相关配置：
  - backend/config/services.php（services.billing.webhook_secret / webhook_tolerance_seconds）
  - backend/.env.example（BILLING_WEBHOOK_SECRET / BILLING_WEBHOOK_TOLERANCE_SECONDS）
- 相关测试：
  - backend/tests/Feature/V0_3/BillingWebhookSignatureTest.php
  - backend/tests/Feature/V0_3/PaymentWebhookRouteWiringTest.php（新增）
  - backend/tests/Feature/V0_3/BillingWebhookMisconfiguredSecretTest.php（新增）
- 需要新增/修改点：
  - 锁死 route→controller wiring，避免生产出现 “Target class not found”
  - 生产 billing secret 缺失：拒绝 + Log::error（便于告警/止血）
- 风险点与规避：
  - 入口必须为 public（不可依赖 org middleware / token）
  - 日志不得输出 body/signature/secret
  - artifacts 必须脱敏（Authorization/token/绝对路径）
