# PR35 Recon

- Keywords: GenericLikertDriver|PaymentWebhook|ContentLoader
- 相关入口文件：
  - backend/app/Services/Assessment/Drivers/GenericLikertDriver.php
  - backend/app/Services/Commerce/PaymentWebhookProcessor.php
  - backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php
  - backend/app/Http/Controllers/MbtiController.php
  - backend/app/Services/Content/ContentLoaderService.php
- 相关路由：
  - POST /api/v0.3/webhooks/payment/{provider}
  - GET  /api/v0.2/attempts/{id}/report
- 需要新增/修改点：
  - GenericLikertDriver：支持 reverse + weight（兼容旧格式负权重表达）
  - Webhook：Cache lock + transaction 内幂等 insertOrIgnore
  - Stripe-Signature：解析 t/v1 + 时间戳防重放
  - Content cache：key 含 filemtime + 降级策略避免 CI 无 Redis 直接 500
  - IDOR：attempt report 权限校验（User/Anon/Share 任一满足）
