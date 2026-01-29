# PR19 勘察结论（商业化底盘 v2）

## 相关入口文件
- backend/routes/api.php
  - v0.2 已有 payments/webhook mock、orders 相关路由；v0.3 当前仅 scales/attempts/orgs。
- backend/app/Http/Controllers/API/V0_2/PaymentsController.php
  - v0.2 订单创建/支付/履约与 webhook mock 逻辑入口。
- backend/app/Services/Payments/PaymentService.php
  - 现有 orders/payment_events/benefit_grants 的幂等处理与发放逻辑（v0.2 语义）。
- backend/app/Http/Controllers/Webhooks/HandleProviderWebhook.php
  - v0.2 integrations/webhooks 入口，签名校验与请求头处理。
- backend/app/Http/Controllers/API/V0_3/AttemptsController.php
  - v0.3 attempts submit/result/report 入口（后续需在 submit 成功路径联动 credit consume）。
- backend/app/Support/OrgContext.php
  - 组织上下文解析（org_id/user_id），v0.3 统一入口。
- backend/app/Http/Middleware/ResolveOrgContext.php
  - v0.3 请求注入 org_id。
- backend/app/Http/Middleware/FmTokenAuth.php
  - token 解析与 user_id/anon_id 注入（v0.3 org 私有接口使用）。
- docs/payments/webhook-idempotency.md
  - webhook 幂等/重放约束文档参考。

## 相关 DB 表/迁移
- 已存在（v0.2 语义）：
  - orders / payment_events / benefit_grants
    - 迁移：
      - backend/database/migrations/2026_01_22_090010_create_orders_table.php
      - backend/database/migrations/2026_01_22_090020_create_benefit_grants_table.php
      - backend/database/migrations/2026_01_22_090030_create_payment_events_table.php
- 组织与 org_id 支撑：
  - organizations / organization_members / organization_invites
  - attempts/results/events/report_jobs 已在 2026_01_29_000004_add_org_id_* 加 org_id
- 当前不存在（需新增）：
  - skus / benefit_wallets / benefit_wallet_ledgers / benefit_consumptions

## 相关路由（v0.3 现状）
- 已存在：
  - GET  /api/v0.3/scales
  - GET  /api/v0.3/scales/lookup
  - GET  /api/v0.3/scales/{scale_code}
  - GET  /api/v0.3/scales/{scale_code}/questions
  - POST /api/v0.3/attempts/start
  - POST /api/v0.3/attempts/submit
  - GET  /api/v0.3/attempts/{id}/result
  - GET  /api/v0.3/attempts/{id}/report
  - POST /api/v0.3/orgs
  - GET  /api/v0.3/orgs/me
  - POST /api/v0.3/orgs/{org_id}/invites
  - POST /api/v0.3/orgs/invites/accept
- 当前不存在（需新增）：
  - /api/v0.3/skus
  - /api/v0.3/orders
  - /api/v0.3/webhooks/payment/{provider}
  - /api/v0.3/orgs/{org_id}/wallets
  - /api/v0.3/orgs/{org_id}/wallets/{benefit_code}/ledger

## 需要新增/修改点
- 路由：v0.3 commerce/webhook/wallet 入口。
- 表结构：skus / benefit_wallets / benefit_wallet_ledgers / benefit_consumptions；评估与 orders/payment_events/benefit_grants 的兼容改造策略。
- 服务层：OrderManager / PaymentWebhookProcessor / BenefitWalletService / EntitlementManager / PaymentGateway 接口与 StubGateway。
- 控制器：CommerceController / OrgWalletController / PaymentWebhookController。
- v0.3 attempts submit：支付成功后的 credit consume 接入。
- 事件打点：payment_webhook_received / purchase_success / wallet_topped_up / wallet_consumed / entitlement_granted。
- 测试：v0.3 webhook 幂等与 submit consume。
- 验收脚本与 CI workflow。

## 潜在风险与规避
- 表名冲突风险：orders/payment_events/benefit_grants 已存在且字段语义为 v0.2；新增 v2 需保持兼容（迁移幂等 + 不破坏既有字段）。
- 幂等一致性：payment_events.provider_event_id UNIQUE；ledger/consumptions 唯一约束避免重复扣减。
- 并发扣减：wallet balance 更新需行锁与事务。
- 跨 org 访问：所有查询统一 org_id 过滤，跨 org 返回 404。
- 事件一致性：webhook 收到即记录 payment_webhook_received；发放后记录 wallet_topped_up 或 entitlement_granted。
