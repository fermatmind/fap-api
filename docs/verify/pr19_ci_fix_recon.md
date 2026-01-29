# PR19 CI 修复勘察结论（ci-verify-commerce-v2）

## 1) Workflow 关键信息
- workflow: `.github/workflows/ci_verify_commerce_v2.yml`
- php-version: 8.4
- extensions: mbstring, sqlite, pdo_sqlite, mysql, pdo_mysql

## 2) 相关 UNIQUE/INDEX 迁移清单（含路径与行号）
- `backend/database/migrations/2026_01_29_200020_create_payment_events_table.php`
  - L27 unique(provider_event_id)
  - L28 index(order_no, received_at)
- `backend/database/migrations/2026_01_29_200030_create_benefit_wallets_table.php`
  - L25 unique(org_id, benefit_code)
  - L26 index(org_id)
- `backend/database/migrations/2026_01_29_200040_create_benefit_wallet_ledgers_table.php`
  - L30 unique(idempotency_key)
  - L31 index(org_id, benefit_code, created_at)
- `backend/database/migrations/2026_01_29_200050_create_benefit_consumptions_table.php`
  - L26 unique(org_id, benefit_code, attempt_id)
  - L30 index(org_id)
- `backend/database/migrations/2026_01_29_200060_create_benefit_grants_table.php`
  - L34 index(org_id, user_id, benefit_code)
  - L35 index(attempt_id, benefit_code)
  - L36 unique(source_order_id, benefit_type, benefit_ref)
- `backend/database/migrations/2026_01_29_200010_create_orders_table.php`
  - L35 unique(order_no)
  - L36 index(org_id, created_at)
  - L37 index(user_id, created_at)
  - L38 index(status, created_at)
  - L39 index(external_trade_no)
- `backend/database/migrations/2026_01_29_200000_create_skus_table.php`
  - L31 index(scale_code, is_active)
  - L32 index(benefit_code)
  - L92 unique(sku)
- `backend/database/migrations/2026_01_29_000004_add_org_id_to_attempts_results_events_report_jobs.php`
  - L65 index(attempts.org_id)
  - L72 index(results.org_id)
  - L82 unique(results.org_id, results.attempt_id)
  - L89 index(events.org_id)
  - L96 index(report_jobs.org_id)
- `backend/database/migrations/2026_01_29_120000_v03_attempts_results_fields.php`
  - L60 index(attempts.org_id, scale_code, pack_id, dir_version)
  - L67 index(attempts.org_id, anon_id)
  - L74 index(attempts.org_id, user_id)
  - L115 unique(results.org_id, results.attempt_id)

## 3) 预期修复策略
- 为所有 commerce v2 相关 UNIQUE/INDEX 增加 `indexExists` 判断。
- 统一显式索引名，避免 Laravel 默认命名在 MySQL 上产生重复。
- 通过 CI workflow 中二次 migrate 验证幂等性。
