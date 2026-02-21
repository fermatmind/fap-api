# PR67 Verify

## Step 1: routes/api.php (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list
```

## Step 2: migrations (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan migrate --force
```

## Step 3: FmTokenAuth.php (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
php -l backend/app/Http/Middleware/FmTokenAuth.php
grep -n -E "DB::table\('fm_tokens'\)|attributes->set\('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php
```

## Step 4: Driver + tests
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter GenericLikertDriver
php artisan test tests/Unit/Psychometrics/GenericLikertDriverTest.php
```

## Step 5: scripts/CI
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash -n backend/scripts/pr67_verify.sh
bash -n backend/scripts/pr67_accept.sh
bash backend/scripts/pr67_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Curl examples
```bash
curl -sS -i http://127.0.0.1:1867/api/v0.3/healthz || true
curl -sS -i http://127.0.0.1:1867/api/v0.3/scales/MBTI/questions || true
```

## Key Diff Areas
- `GenericLikertDriver::resolveItemRule`: support nested `rule.weight/reverse`.
- `GenericLikertDriver` invalid-answer branch: use warning message `Invalid answer option` and context `question/answer`.
- New tests in `backend/tests/Unit/Psychometrics/GenericLikertDriverTest.php`.

## Artifacts
- `backend/artifacts/pr67/verify.log`
- `backend/artifacts/pr67/unit_tests.log`
- `backend/artifacts/pr67/summary.txt`
- `backend/artifacts/pr67/verify_done.txt`
