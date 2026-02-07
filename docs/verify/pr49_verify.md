# PR49 Verify

- Date: 2026-02-07
- Commands:
  - php artisan route:list
  - php artisan migrate
  - php artisan test --filter SchemaIndexTest
  - php artisan test --filter MigrationsNoSilentCatchTest
  - bash backend/scripts/pr49_accept.sh
  - bash backend/scripts/ci_verify_mbti.sh
- Result:
  - route:list: PASS
  - migrate: PASS
  - SchemaIndexTest: PASS
  - MigrationsNoSilentCatchTest: PASS
  - pr49_accept: PASS
  - ci_verify_mbti: PASS
- Artifacts:
  - backend/artifacts/pr49/summary.txt
  - backend/artifacts/pr49/verify.log
  - backend/artifacts/pr49/pack_seed_config.txt
  - backend/artifacts/pr49/phpunit_schema_index.txt
  - backend/artifacts/pr49/phpunit_migrations_no_silent_catch.txt
  - backend/artifacts/verify_mbti/summary.txt

Step Verification Commands

1. Step 1 (routes): `cd backend && php artisan route:list | grep -E "api/v0.3/scales/\{scale_code\}/questions|api/v0.3/attempts/start|api/v0.3/attempts/submit|api/v0.2/healthz"`
2. Step 2 (migrations): `cd backend && touch /tmp/pr49.sqlite && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr49.sqlite php artisan migrate:fresh --force && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr49.sqlite php artisan migrate:rollback --step=1 --force && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr49.sqlite php artisan migrate --force`
3. Step 3 (middleware): `grep -n -E "DB::table\\('fm_tokens'\\)|DB::table\\(\"fm_tokens\"\\)" backend/app/Http/Middleware/FmTokenAuth.php && grep -n -E "attributes->set\\('fm_user_id'|attributes->set\\(\"fm_user_id\"" backend/app/Http/Middleware/FmTokenAuth.php`
4. Step 4 (service/tests): `cd backend && php -l app/Support/Database/SchemaIndex.php && php artisan test --filter SchemaIndexTest && php artisan test --filter MigrationsNoSilentCatchTest`
5. Step 5 (scripts/CI): `bash -n backend/scripts/pr49_verify.sh && bash -n backend/scripts/pr49_accept.sh && bash backend/scripts/pr49_accept.sh && bash backend/scripts/ci_verify_mbti.sh`
