# PR63 Recon

- Keywords: config/queue.php|RedactProcessor|APP_DEBUG

## Scope
- Queue hardening: failed/DLQ + redis retry_after
- Global log redaction: recursive context filter (password/token/authorization/secret/credit_card)
- .env.example hardening: APP_DEBUG default false; sensitive keys blank/required marker
- Tests: redaction processor unit test
- Migrations: ensure failed_jobs table exists for database failed driver

## Non-Goals
- 不改动业务逻辑（支付/计分/权限等），仅做运维与安全加固
