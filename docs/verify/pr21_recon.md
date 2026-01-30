# PR21 Recon: Answer storage v2 (progress/resume + answer_sets + archival)

Date: 2026-01-30

## 环境
- PHP: 8.5.2
- Laravel: 12.48.1

## 现有 attempts start/submit 位置（PR17）
- 路由：`backend/routes/api.php`
  - POST `/api/v0.3/attempts/start` -> `API\V0_3\AttemptsController@start`
  - POST `/api/v0.3/attempts/submit` -> `API\V0_3\AttemptsController@submit`
- 控制器：`backend/app/Http/Controllers/API/V0_3/AttemptsController.php`
  - `start()`：创建 Attempt，写 `test_start` 事件
  - `submit()`：`answers_digest` 幂等锁，评分，写 result，记录 `test_submit`

## org_id 解析与中间件（PR18）
- v0.3 路由统一走 `ResolveOrgContext` 中间件：`backend/routes/api.php`
- `ResolveOrgContext`：`backend/app/Http/Middleware/ResolveOrgContext.php`
  - 读取 `X-Org-Id` / `fm_org_id` / fm_token 解析 org
  - 校验 org+user 关系并写入 `request->attributes['org_id']`
- `FmTokenAuth`：`backend/app/Http/Middleware/FmTokenAuth.php`
  - 解析 `Authorization: Bearer fm_xxx`
  - 从 `fm_tokens` 读取 `org_id` -> 注入 `fm_org_id`
  - 用于后续 org 归属校验

## PR19 consume hook 与触发点
- `AttemptsController@submit()` 内部：
  - `BenefitWalletService::consume()` 在 result 创建后执行
  - `EventRecorder::record('wallet_consumed', ...)` 记录消费事件
  - `EntitlementManager::grantAttemptUnlock()` + `ReportSnapshotStore::createSnapshotForAttempt()`
  - 该路径不应被 progress API 触发

## Events 表/写入点（本 PR 不新增事件）
- `EventRecorder`：`backend/app/Services/Analytics/EventRecorder.php`
  - `record()`/`recordFromRequest()` 写 `events` 表（若表存在）
- `AttemptsController@start()`：`test_start`
- `AttemptsController@submit()`：`test_submit` + `wallet_consumed`

## 现有 attempts/results 字段与迁移
- attempts 基表：`backend/database/migrations/2025_12_14_084436_create_attempts_table.php`
- answers 字段：`backend/database/migrations/2025_12_21_101327_add_answers_fields_to_attempts_table.php`
  - `answers_json` 等
- v0.3 字段补齐：`backend/database/migrations/2026_01_29_120000_v03_attempts_results_fields.php`
  - `org_id` / `pack_id` / `dir_version` / `content_package_version` / `scoring_spec_version`
  - `duration_ms` / `answers_digest` / `started_at` / `submitted_at`
- results 表 `org_id` + UNIQUE(org_id, attempt_id)：同上迁移

## 风险点（需在 PR21 规避）
- sqlite 不支持分区 / 部分 DDL：归档命令需提供 sqlite 降级路径（不 drop partition）。
- org_id 必须持续走 `ResolveOrgContext`：progress/submit 应保持 org 过滤并返回 404。
- submit 幂等依赖 `answers_digest`：progress 不触发 consume/事件。
