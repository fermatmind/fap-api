# PR40 Recon

- Keywords: PaymentWebhookController|verifyStripeSignature|Stripe-Signature

## 相关入口文件
- backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php
- backend/config/services.php
- backend/.env.example

## 相关路由
- POST /api/v0.3/webhooks/payment/{provider}/{orgId?}

## 目标
- Stripe-Signature 验签落地（t/v1 解析 + HMAC + hash_equals）
- 重放窗口容忍度参数化（services.stripe.webhook_tolerance_seconds）
- 非法签名统一 404（避免侧信道）

