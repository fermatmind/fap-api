# PR21 Verify Guide

Date: 2026-01-30

## One-shot acceptance
```bash
export CI=true FAP_NONINTERACTIVE=1 COMPOSER_NO_INTERACTION=1 NO_COLOR=1
bash backend/scripts/pr21_accept.sh
```

## Manual commands (same as accept script)
```bash
export CI=true FAP_NONINTERACTIVE=1 COMPOSER_NO_INTERACTION=1 NO_COLOR=1
export DB_CONNECTION=sqlite
export DB_DATABASE=/tmp/pr21.sqlite
rm -f /tmp/pr21.sqlite
export SERVE_PORT=1821
lsof -ti tcp:1821 | xargs -r kill || true

cd backend && composer install --no-interaction --no-progress
cd backend && php artisan migrate --force
cd backend && php artisan fap:scales:seed-default
cd backend && php artisan fap:scales:sync-slugs
cd backend && php artisan db:seed --class=Pr21AnswerDemoSeeder
cd backend && php artisan test --filter=V0_3
cd backend && bash scripts/pr21_verify_answer_storage.sh
```

## Expected outputs (examples)
- `backend/artifacts/pr21/curl_progress_get.json`:
  - `answered_count` should be `2`
- `backend/artifacts/pr21/curl_submit.json`:
  - `ok: true`
- `backend/artifacts/pr21/db_assertions.json`:
  - `answer_sets = 1`
  - `answer_rows = 3`
  - `archive_audits >= 1`

## Artifacts
- `backend/artifacts/pr21/summary.txt`
- `backend/artifacts/pr21/verify.log`
- `backend/artifacts/pr21/curl_*.json`
- `backend/artifacts/pr21/db_assertions.json`
