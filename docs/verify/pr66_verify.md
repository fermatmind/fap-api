# PR66 Verify

## Step 1: routes/api.php (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list
```

## Step 2: migrations
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
find backend/database/migrations -name '*.php' -print0 | xargs -0 -n1 php -l

cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan migrate --force
rm -f /tmp/pr66_step2.sqlite && touch /tmp/pr66_step2.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr66_step2.sqlite php artisan migrate:fresh --force
rm -f /tmp/pr66_step2.sqlite
```

## Step 3: FmTokenAuth.php (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
php -l backend/app/Http/Middleware/FmTokenAuth.php
grep -n -E "DB::table\('fm_tokens'\)|DB::table\(\"fm_tokens\"\)" backend/app/Http/Middleware/FmTokenAuth.php
grep -n -E "attributes->set\('fm_user_id'|attributes->set\(\"fm_user_id\"" backend/app/Http/Middleware/FmTokenAuth.php
```

## Step 4: unit gates
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter MigrationSafetyTest
php artisan test --filter MigrationRollbackSafetyTest
php artisan test --filter MigrationNoSilentCatchTest
```

## Step 5: scripts/CI
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash -n backend/scripts/pr66_verify.sh
bash -n backend/scripts/pr66_accept.sh
bash backend/scripts/pr66_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Curl example
```bash
curl -sS -i http://127.0.0.1:1866/api/v0.2/healthz || true
```

## Artifacts
- backend/artifacts/pr66/summary.txt
- backend/artifacts/pr66/unit_tests.log
- backend/artifacts/pr66/migration_lint.log
- backend/artifacts/pr66/dropifexists_hits.txt
- backend/artifacts/pr66/catch_hits.txt
