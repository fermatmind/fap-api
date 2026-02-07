# PR54 Verify

- Date: 2026-02-07
- Commands:
  - `php artisan route:list`
  - `php artisan migrate`
  - `php artisan test --filter AdminQueueDlqReplayTest`
  - `bash backend/scripts/pr54_accept.sh`
  - `bash backend/scripts/ci_verify_mbti.sh`
- Result:
  - `php artisan route:list` 通过（DLQ metrics/replay 路由可见）
  - `php artisan migrate` 通过（`queue_dlq_replays` 成功执行）
  - `php artisan test --filter AdminQueueDlqReplayTest` 通过
  - `bash backend/scripts/pr54_accept.sh` 通过
  - `bash backend/scripts/ci_verify_mbti.sh` 通过
- Artifacts:
  - `backend/artifacts/pr54/summary.txt`
  - `backend/artifacts/pr54/verify.log`
  - `backend/artifacts/pr54/dlq_metrics_before.json`
  - `backend/artifacts/pr54/dlq_replay_response.json`
  - `backend/artifacts/pr54/dlq_metrics_after.json`
  - `backend/artifacts/pr54/phpunit_admin_queue_dlq_replay.txt`
  - `backend/artifacts/verify_mbti/summary.txt`

Step Verification Commands

1. Step 1 (routes): `cd backend && php artisan route:list | grep -E "queue/dlq/metrics|queue/dlq/replay"`
2. Step 2 (migrations): `cd backend && php artisan migrate --force && rm -f /tmp/pr54.sqlite && touch /tmp/pr54.sqlite && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr54.sqlite php artisan migrate:fresh --force`
3. Step 3 (middleware): `php -l backend/app/Http/Middleware/FmTokenAuth.php && grep -n "DB::table('fm_tokens')" backend/app/Http/Middleware/FmTokenAuth.php && grep -n "attributes->set('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php`
4. Step 4 (controller/service/tests): `php -l backend/app/Http/Controllers/API/V0_2/Admin/AdminQueueController.php && php -l backend/app/Services/Queue/QueueDlqService.php && cd backend && php artisan test --filter AdminQueueDlqReplayTest`
5. Step 5 (scripts/CI): `bash -n backend/scripts/pr54_verify.sh && bash -n backend/scripts/pr54_accept.sh && bash backend/scripts/pr54_accept.sh && bash backend/scripts/ci_verify_mbti.sh`
