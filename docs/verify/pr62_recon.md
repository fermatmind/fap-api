# PR62 Recon

- Keywords: PaymentWebhookProcessor|insertOrIgnore|Cache::lock

## Goal
- 修复 PaymentWebhookProcessor 幂等落库与业务处理原子性，确保 `insertOrIgnore` 与业务推进同事务。
- 引入分布式锁并统一 key 规则：`webhook_pay:{provider}:{providerEventId}`。
- 增加状态机终态 double-check，避免重复推进导致重复发放。
- 增加回归测试覆盖“假幂等丢单”与锁 key 规则。

## Before Facts
- 锁 key 使用 `payment_webhook:{provider}:{providerEventId}`，且 TTL/阻塞秒数为硬编码常量：
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:21`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:22`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:78`
- payment_events 读写主要按 `provider_event_id` 单字段查询：
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:150`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:208`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:589`
- 重复事件分支没有输出最小白名单日志。
- 订单终态（paid/fulfilled 等）缺少显式 skip 日志关键字 `payment_webhook_skip_already_processed`。

## Risk
- 该问题属于资损/账实不一致风险，要求失败快速返回并保留重试能力。
- 日志只允许白名单字段：`provider`、`provider_event_id`、可选 `order_id`，禁止泄漏敏感 payload。
