# PR64 Verify

## Scope
- AttemptsController ownership tightened for member/viewer.
- Unified 404 for not-found vs unauthorized ownership access.
- New feature test: `AttemptOwnershipAnd404Test`.
- New local acceptance scripts: `pr64_verify.sh`, `pr64_accept.sh`.

## Step Verification Commands (Rule 2 order)

1. Routes
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list | grep -E "api/v0\.3/attempts/(start|submit|\{id\}/result|\{id\}/report)"
```

2. Migrations (no schema change in PR64)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan migrate --force
rm -f /tmp/pr64_step.sqlite && touch /tmp/pr64_step.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr64_step.sqlite php artisan migrate:fresh --force
rm -f /tmp/pr64_step.sqlite
```

3. Middleware contract (no code change)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
php -l /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php
grep -n "DB::table('fm_tokens')" /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php
grep -n "attributes->set('fm_user_id'" /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php
```

4. Controller + tests
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
php -l /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Controllers/API/V0_3/AttemptsController.php
php -l /Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/V0_3/AttemptOwnershipAnd404Test.php
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter AttemptOwnershipAnd404Test
```

5. Scripts + CI
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash -n /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr64_verify.sh
bash -n /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr64_accept.sh
bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr64_accept.sh
bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/ci_verify_mbti.sh
```

## Curl Examples
```bash
curl -i -H "Accept: application/json" \
  -H "X-Org-Id: <ORG_ID>" \
  -H "Authorization: Bearer <MEMBER_B_TOKEN>" \
  "http://127.0.0.1:1864/api/v0.3/attempts/<ATTEMPT_ID>/result"

curl -i -H "Accept: application/json" \
  -H "X-Org-Id: <ORG_ID>" \
  -H "Authorization: Bearer <OWNER_OR_ADMIN_TOKEN>" \
  "http://127.0.0.1:1864/api/v0.3/attempts/<ATTEMPT_ID>/report"
```

## Acceptance
- `bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr64_accept.sh`
- `bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/ci_verify_mbti.sh`
- Artifact summary: `/Users/rainie/Desktop/GitHub/fap-api/backend/artifacts/pr64/summary.txt`
