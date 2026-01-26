# Queue Runbook (Report Jobs)

## Local (sqlite + database queue)
1) `cd backend && php artisan migrate`
2) `cd backend && QUEUE_CONNECTION=database php artisan queue:work --queue=reports --tries=3`
3) `cd backend && bash scripts/verify_mbti.sh`

## Troubleshooting
- `status=failed`:
  - Check `report_jobs.last_error`, `report_jobs.last_error_trace`, `report_jobs.failed_at`.
- Requeue a single attempt (tinker):
  - `cd backend && php artisan tinker`
  - `\App\Models\ReportJob::where('attempt_id', 'ATTEMPT_ID')->update(['status' => 'queued', 'available_at' => now(), 'failed_at' => null, 'last_error' => null, 'last_error_trace' => null, 'report_json' => null]);`

## Environment strategy
- CI: `QUEUE_CONNECTION=sync` (no worker; selfcheck/verify still pass)
- Production: `QUEUE_CONNECTION=database` or `QUEUE_CONNECTION=redis` (choose by infra)
