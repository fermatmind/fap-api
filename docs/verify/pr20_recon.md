# PR20 Recon: Teaser Paywall + ReportSnapshot (immutable)

Date: 2026-01-29

## 环境
- PHP: 8.5.2
- Laravel: 12.48.1

## 相关入口文件
- v0.3 attempts report 端点
  - 路由：`backend/routes/api.php` -> `Route::get("/attempts/{id}/report", [AttemptsController::class, "report"])`
  - 控制器：`backend/app/Http/Controllers/API/V0_3/AttemptsController.php`（`report()`）
- v0.3 attempts submit 端点
  - 路由：`backend/routes/api.php` -> `Route::post("/attempts/submit", [AttemptsController::class, "submit"])`
  - 控制器：`backend/app/Http/Controllers/API/V0_3/AttemptsController.php`（`submit()`）
- webhook processor
  - 控制器：`backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php`
  - 处理器：`backend/app/Services/Commerce/PaymentWebhookProcessor.php`
- report builder / report engine
  - MBTI：`backend/app/Services/Report/ReportComposer.php`（`AttemptsController::report()` 内调用）
  - 非 MBTI：`backend/app/Services/Assessment/GenericReportBuilder.php`
  - 评分引擎：`backend/app/Services/Assessment/AssessmentEngine.php`
- entitlement / benefit
  - 权益判定：`backend/app/Services/Commerce/EntitlementManager.php`
  - 钱包扣减/充值：`backend/app/Services/Commerce/BenefitWalletService.php`
  - 订单流转：`backend/app/Services/Commerce/OrderManager.php`
- scales_registry / view_policy_json 读取
  - registry service：`backend/app/Services/Scale/ScaleRegistry.php`
  - model cast：`backend/app/Models/ScaleRegistry.php`
  - lookup API：`backend/app/Http/Controllers/API/V0_3/ScalesLookupController.php`
  - seed：`backend/database/seeders/ScaleRegistrySeeder.php`

## 相关 DB 表 / 迁移
- attempts / results / org_id
  - 主表：`backend/database/migrations/2025_12_14_084436_create_attempts_table.php`
  - 主表：`backend/database/migrations/2025_12_13_231207_create_results_table.php`
  - org_id + 索引：`backend/database/migrations/2026_01_29_000004_add_org_id_to_attempts_results_events_report_jobs.php`
  - v0.3 字段补齐：`backend/database/migrations/2026_01_29_120000_v03_attempts_results_fields.php`
- orders / payment_events
  - orders：`backend/database/migrations/2026_01_29_200010_create_orders_table.php`（含 `target_attempt_id`）
  - payment_events：`backend/database/migrations/2026_01_29_200020_create_payment_events_table.php`（`provider_event_id` UNIQUE）
- benefit / sku
  - skus：`backend/database/migrations/2026_01_29_200000_create_skus_table.php`
  - benefit_wallets：`backend/database/migrations/2026_01_29_200030_create_benefit_wallets_table.php`
  - benefit_wallet_ledgers：`backend/database/migrations/2026_01_29_200040_create_benefit_wallet_ledgers_table.php`
  - benefit_consumptions：`backend/database/migrations/2026_01_29_200050_create_benefit_consumptions_table.php`
  - benefit_grants：`backend/database/migrations/2026_01_29_200060_create_benefit_grants_table.php`

## 相关路由（route:list）
- GET `/api/v0.3/attempts/{id}/report`
- POST `/api/v0.3/attempts/submit`
- POST `/api/v0.3/attempts/start`
- POST `/api/v0.3/webhooks/payment/{provider}`
- POST `/api/v0.3/orders` / GET `/api/v0.3/orders/{order_no}`

## 需要新增/修改点（PR20）
- 新增 `report_snapshots` 表 + 幂等迁移
- 新增 `ReportSnapshotStore` / `ReportGatekeeper` / `CreateReportSnapshotJob`
- webhook 与 submit 成功路径触发 snapshot
- v0.3 report 固定响应结构 + gatekeeper（禁止 GET 写入）
- seed view_policy_json + SKU
- 新增 tests + verify script + CI workflow

## 潜在风险与规避
- **GET report 端点写入 snapshot 必须禁止**：只允许 payment webhook / credit consume 写入
- **跨 org 读取 attempt**：坚持 `org_id` 过滤并保持 404（复用 OrgContext）
- **artifacts 含绝对路径**：verify 脚本写入前统一脱敏（`/Users/*`/`/home/*` -> `<REPO>`）
- **view_policy_json 缺省**：需要默认策略避免空裁剪导致不稳定
