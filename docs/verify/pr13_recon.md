# PR13 勘察结论（Zero-Input 数据管线）

## 相关入口文件
- backend/routes/api.php
  - 现有 v0.2 路由入口；当前仅有 events ingestion 与 payments webhook mock。
- backend/app/Http/Middleware/FmTokenAuth.php
  - 负责解析 fm_token 并注入 fm_user_id / anon_id；后续 /me/data 需要沿用该注入逻辑。
- backend/app/Http/Controllers/API/V0_2/PaymentsController.php
  - 已有 webhook mock + 幂等概念，供本 PR webhook 设计参考。
- backend/app/Http/Controllers/EventController.php
  - 现有事件 ingestion 入口，需避免与新 /integrations 路由冲突。
- docs/payments/webhook-idempotency.md
  - 支付幂等/重放约束文档，可复用在数据回放的规则描述。

## 相关 DB 表/迁移
- 现有关键表/迁移：
  - events / payment_events / fm_tokens / attempts / results 等（见 backend/database/migrations/*）。
- 当前不存在：
  - integrations / ingest_batches / sleep_samples / health_samples / screen_time_samples / idempotency_keys。
- 风险点：
  - 需保证迁移幂等（判断表/列/索引是否存在）。
  - 需避免与 payment_events 的幂等语义混淆（命名/表结构清晰隔离）。

## 相关路由
- 已存在：
  - POST /api/v0.3/events
  - POST /api/v0.3/payments/webhook/mock
  - POST /api/v0.3/auth/provider
  - /api/v0.3/me/*（需要 FmTokenAuth）
- 当前不存在：
  - /api/v0.3/integrations/*
  - /api/v0.3/webhooks/*
  - /api/v0.3/me/data/*

## 需要新增/修改点
- 新增路由：/api/v0.3/integrations/{provider}/* 与 /api/v0.3/webhooks/{provider}。
- 新增 /me/data/sleep|mood|screen-time 查询路由（FmTokenAuth 下）。
- 新增 migrations：integrations / ingest_batches / domain samples / idempotency_keys。
- 新增 Service/Support：IngestionService / ReplayService / ConsentService / IdempotencyStore。
- 新增 Controller：Integrations/ProvidersController 与 Webhooks/HandleProviderWebhook。
- Seeder：QuantifiedSelfSeeder（30 天样本）。
- 验收脚本与 Metabase 视图。

## 潜在风险与规避
- 路由冲突：避开 /events 与 /payments/webhook/mock，统一放在 /integrations 与 /webhooks 前缀下。
- 幂等策略不一致：统一使用 idempotency_keys 表记录并复用 ReplayService。
- FmTokenAuth 行为变更风险：仅新增 fm_user_id 注入时保持既有逻辑不变。
- 迁移重复执行风险：所有创建动作加 Schema::hasTable / hasColumn / hasIndex 判定。
- 验收端口冲突：脚本遵循 18030-18059 自动探测。
