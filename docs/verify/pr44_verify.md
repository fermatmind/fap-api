# PR44 Verify

- Date: 2026-02-07
- Commands:
  - bash backend/scripts/pr44_accept.sh
  - bash backend/scripts/ci_verify_mbti.sh
  - php artisan test --filter EventPayloadLimiterTest
  - php artisan test --filter EventPayloadLimitsTest
- Result:
  - pr44_accept: PASS
  - ci_verify_mbti: PASS
  - EventPayloadLimiterTest: PASS
  - EventPayloadLimitsTest: PASS
- Artifacts:
  - backend/artifacts/pr44/summary.txt
  - backend/artifacts/pr44/verify.log
  - backend/artifacts/pr44/phpunit.txt
  - backend/artifacts/verify_mbti/summary.txt

Key Notes

- analytics event payload limit is now configurable via `fap.events.*` / `FAP_EVENTS_*`.
- `/api/v0.3/events` now limits `props`/`props_json`/`meta_json` before normalization and persistence.
- event ingest response status is `201` for created event rows.
- verification blocker handling:
  - port bind under sandbox (`1844`, `1827`) required elevated rerun.
  - duplicated `--filter` option in `pr44_verify.sh` caused a non-zero exit and was fixed to regex filter.

Step Verification Commands

1. Step 1 (routes): php artisan route:list
2. Step 2 (migrations): php artisan migrate
3. Step 3 (middleware): php -l backend/app/Http/Middleware/FmTokenAuth.php
4. Step 4 (controllers/services/tests): php artisan test --filter EventPayloadLimiterTest && php artisan test --filter EventPayloadLimitsTest && php artisan test --filter EventExperimentsJsonTest
5. Step 5 (scripts/CI): bash -n backend/scripts/pr44_verify.sh && bash -n backend/scripts/pr44_accept.sh && bash backend/scripts/pr44_accept.sh && bash backend/scripts/ci_verify_mbti.sh
