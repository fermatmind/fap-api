# PR52 Verify

- Date: 2026-02-07
- Commands:
  - php artisan route:list
  - php artisan migrate
  - php artisan test --filter ApiExceptionRendererTest
  - php artisan test --filter UuidRouteParamsMiddlewareTest
  - php artisan test --filter OrgContextMiddlewareTest
  - php artisan test --filter MeAttemptsAuthContractTest
  - php artisan test --filter ApiErrorContractMiddlewareTest
  - bash backend/scripts/pr52_accept.sh
  - bash backend/scripts/ci_verify_mbti.sh
- Result:
  - route:list: PASS
  - migrate: PASS
  - ApiExceptionRendererTest: PASS
  - UuidRouteParamsMiddlewareTest: PASS
  - OrgContextMiddlewareTest: PASS
  - MeAttemptsAuthContractTest: PASS
  - ApiErrorContractMiddlewareTest: PASS
  - pr52_accept: PASS
  - ci_verify_mbti: PASS
- Artifacts:
  - backend/artifacts/pr52/summary.txt
  - backend/artifacts/pr52/verify.log
  - backend/artifacts/pr52/pack_seed_config.txt
  - backend/artifacts/pr52/me_attempts_unauthorized.json
  - backend/artifacts/pr52/share_invalid_uuid.json
  - backend/artifacts/pr52/org_not_found.json
  - backend/artifacts/pr52/v03_missing_route.json
  - backend/artifacts/verify_mbti/summary.txt

Step Verification Commands

1. Step 1 (routes): `cd backend && php artisan route:list | grep -E "api/v0.2/me/attempts|api/v0.3/attempts/\{attempt_id\}/progress|api/v0.3/attempts/\{id\}/(result|report)"`
2. Step 2 (migrations): `cd backend && php artisan migrate --force && rm -f /tmp/pr52.sqlite && touch /tmp/pr52.sqlite && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr52.sqlite php artisan migrate:fresh --force`
3. Step 3 (middleware): `grep -n -E "DB::table\('fm_tokens'\)|attributes->set\('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php && php -l backend/app/Http/Middleware/FmTokenAuth.php && php -l backend/app/Http/Middleware/EnsureUuidRouteParams.php`
4. Step 4 (controller/service/tests): `php -l backend/app/Support/ApiExceptionRenderer.php && php -l backend/app/Http/Middleware/NormalizeApiErrorContract.php && cd backend && php artisan test --filter ApiExceptionRendererTest && php artisan test --filter UuidRouteParamsMiddlewareTest && php artisan test --filter OrgContextMiddlewareTest && php artisan test --filter MeAttemptsAuthContractTest && php artisan test --filter ApiErrorContractMiddlewareTest`
5. Step 5 (scripts/CI): `bash -n backend/scripts/pr52_verify.sh && bash -n backend/scripts/pr52_accept.sh && bash backend/scripts/pr52_accept.sh && bash backend/scripts/ci_verify_mbti.sh`

Contract Smoke (curl)

- `curl -i http://127.0.0.1:1852/api/v0.3/me/attempts` -> `401` + `error_code=UNAUTHORIZED`
- `curl -i -X POST http://127.0.0.1:1852/api/v0.3/shares/not-a-uuid/click` -> `404` + `error_code=NOT_FOUND`
- `curl -i -H "X-Org-Id: 999999" http://127.0.0.1:1852/api/v0.3/scales` -> `404` + `error_code=ORG_NOT_FOUND`
- `curl -i http://127.0.0.1:1852/api/v0.3/__missing` -> `404` + `error_code=NOT_FOUND`
