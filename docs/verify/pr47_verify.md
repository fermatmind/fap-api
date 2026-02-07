# PR47 Verify

- Date: 2026-02-07
- Commands:
  - php artisan route:list
  - php artisan migrate
  - php -l backend/app/Http/Middleware/FmTokenAuth.php
  - php artisan test --filter ProviderWebhookSignatureTest
  - bash backend/scripts/pr47_accept.sh
  - bash backend/scripts/ci_verify_mbti.sh
- Result:
  - route:list: PASS
  - migrate: PASS
  - middleware lint/check: PASS
  - ProviderWebhookSignatureTest: PASS
  - pr47_accept: PASS
  - ci_verify_mbti: PASS
- Artifacts:
  - backend/artifacts/pr47/summary.txt
  - backend/artifacts/pr47/verify.log
  - backend/artifacts/pr47/phpunit.txt
  - backend/artifacts/verify_mbti/summary.txt

Key Notes

- v0.2 provider webhook 签名升级为 `timestamp + raw_body`，并引入 `INTEGRATIONS_WEBHOOK_*` 配置族。
- 当 webhook secret 已配置时，`X-Webhook-Timestamp` 缺失/过期、签名错误统一返回 404。
- `integrations` 表新增 webhook 收敛字段：`webhook_last_event_id`、`webhook_last_timestamp`、`webhook_last_received_at`。
- API 验收继续使用 `/api/v0.3/scales/{code}/questions` 动态生成 answers（未写死题量）。

Blocker Handling

- 【错误原因】`pr47_verify.sh` 首次执行时，valid webhook 返回 404（签名串与请求体不一致）。
- 【最小修复动作】webhook 请求改为 `--data-binary` 且 payload 统一由 `php -r` 输出无尾换行 JSON。
- 【对应命令】
  - `bash backend/scripts/pr47_accept.sh`

Step Verification Commands

1. Step 1 (routes): `php artisan route:list`
2. Step 2 (migrations): `php artisan migrate`
3. Step 3 (middleware): `php -l backend/app/Http/Middleware/FmTokenAuth.php`
4. Step 4 (controllers/services/tests): `php artisan test --filter ProviderWebhookSignatureTest`
5. Step 5 (scripts/CI): `bash -n backend/scripts/pr47_verify.sh && bash -n backend/scripts/pr47_accept.sh && bash backend/scripts/pr47_accept.sh && bash backend/scripts/ci_verify_mbti.sh`
