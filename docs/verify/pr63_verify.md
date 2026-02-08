# PR63 Verify

## Before
- `/Users/rainie/Desktop/GitHub/fap-api/backend/config/queue.php` 中 `failed.driver` 默认是 `database-uuids`。
- `/Users/rainie/Desktop/GitHub/fap-api/backend/config/queue.php` 中 `redis.block_for` 为 `null`。
- `/Users/rainie/Desktop/GitHub/fap-api/backend/.env.example` 中 `APP_DEBUG=true` 且 `FAP_ADMIN_TOKEN=change_me`。

## After
- `failed.driver` 默认改为 `database`，`failed.table` env 化为 `DB_FAILED_JOBS_TABLE`。
- `redis.block_for` env 化为 `REDIS_QUEUE_BLOCK_FOR` 默认 5。
- 新增全局日志 Processor：`/Users/rainie/Desktop/GitHub/fap-api/backend/app/Support/Logging/RedactProcessor.php`。
- `AppServiceProvider` 在 `boot()` 注册全局递归脱敏。
- `.env.example` 设为 `APP_DEBUG=false`，敏感键改为留空或 `(production_value_required)`。
- 新增单测：`/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Unit/Logging/RedactProcessorTest.php`。
- 新增迁移：`/Users/rainie/Desktop/GitHub/fap-api/backend/database/migrations/2026_02_08_060000_make_failed_jobs_uuid_nullable.php`，确保 `database` failed driver 与现有 `failed_jobs` 结构兼容。

## Step Verification Commands
- `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list`
- `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan migrate --force`
- `cd /Users/rainie/Desktop/GitHub/fap-api/backend && rm -f /tmp/pr63_step.sqlite && touch /tmp/pr63_step.sqlite && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr63_step.sqlite php artisan migrate:fresh --force`
- `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan test tests/Unit/Logging/RedactProcessorTest.php`
- `bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr63_accept.sh`
- `bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/ci_verify_mbti.sh`

## PASS Keywords
- `/Users/rainie/Desktop/GitHub/fap-api/backend/artifacts/pr63/verify.log` 包含 `PASS` 或 `OK (`
- `bash backend/scripts/pr63_accept.sh` 退出码 0
- `bash backend/scripts/ci_verify_mbti.sh` 退出码 0
