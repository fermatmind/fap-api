# High-3 Attempt Ownership Verify

## What fixed
- Org member/viewer can no longer submit another user's attempt by guessing `attempt_id`.
- Org member/viewer can no longer read/write another user's attempt progress.
- Draft access without resume token now requires `attempt.user_id` ownership match at service layer.
- Report services now use actor-aware attempt lookup to reduce unsafe direct reuse risk.

## Files changed
- `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Controllers/API/V0_3/AttemptsController.php`
- `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Controllers/API/V0_3/AttemptProgressController.php`
- `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Attempts/AttemptProgressService.php`
- `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Report/ReportGatekeeper.php`
- `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Report/ReportSnapshotStore.php`
- `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php`
- `/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/V0_3/AttemptMemberViewerOwnershipTest.php`
- `/Users/rainie/Desktop/GitHub/fap-api/docs/security/high3_attempt_horizontal_tampering.md`

## How to verify
- `cd /Users/rainie/Desktop/GitHub/fap-api/backend`
- `composer install --no-interaction --no-progress`
- `php artisan migrate:fresh --force`
- `php artisan test --filter AttemptMemberViewerOwnershipTest`
- `php artisan test --filter "AttemptOwnershipAnd404Test|AttemptProgressFlowTest|ReportSnapshotB2BTest|ReportSnapshotB2CTest"`
- `cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh`

## Artifacts
- `/Users/rainie/Desktop/GitHub/fap-api/backend/artifacts/high3_scan/scan.txt`
- `/Users/rainie/Desktop/GitHub/fap-api/backend/artifacts/high3_scan/test.log`
