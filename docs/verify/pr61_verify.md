# PR61 Verify

## Before
- Driver file: /Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Assessment/Drivers/GenericLikertDriver.php
- 现有入口方法：`compute(array $answers, array $spec): array`、`score(array $answers, array $spec, array $ctx): ScoreResult`
- 现有 reverse/weight 已生效，但 invalid answer 会回落 default_value，且没有 `scoring_invalid_answer` warning

## After
- Reverse 公式：`(min + max) - raw`（仅 raw 有效时）
- Weight 公式：`score * weight`
- invalid answer 行为：该题记 `0` 分 + `Log::warning('scoring_invalid_answer', context)`
- warning 最小字段：`event`、`scale_code(可选)`、`item_id(可选)`、`dimension(可选)`
- 明确不写入：原始 answer、原始选项数组、整份 answers payload

## Step Verification Commands
1. routes: `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list`
2. migrations: `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan migrate --force`
3. FmTokenAuth: `php -l /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php && grep -n -E "DB::table\\('fm_tokens'\\)|attributes->set\\('fm_user_id'" /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php`
4. driver + tests: `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan test --filter GenericLikertDriver`
5. scripts + CI: `bash -n /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr61_verify.sh && bash -n /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr61_accept.sh && bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr61_accept.sh && bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/ci_verify_mbti.sh`

## Contract Smoke (curl)
- `curl -i http://127.0.0.1:1861/api/v0.3/healthz`
- `curl -i http://127.0.0.1:1861/api/v0.3/scales/MBTI/questions`

## PASS Keywords
- `backend/artifacts/pr61/unit.log` 含 `PASS` 或 `OK (`
- `bash backend/scripts/pr61_accept.sh` 退出码为 0
- `bash backend/scripts/ci_verify_mbti.sh` 退出码为 0
