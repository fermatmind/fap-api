# Test KPI Scheduler

Status: active after deployment of `TEST-KPI-SCHEDULER-05`.

## Runtime schedule

The Laravel 11 runtime scheduler source is `backend/bootstrap/app.php`.

Registered command:

```bash
php artisan analytics:refresh-test-metrics-daily --scheduled-current-day
```

Schedule:

- every 15 minutes
- `withoutOverlapping(20)`
- current day only
- all orgs and all scales
- writes only to `analytics_test_metrics_daily`

The scheduled mode intentionally rejects manual `--from`, `--to`, `--org`,
`--scale`, `--dry-run`, and `--confirm-write` overrides. Historical backfills
must continue to use the controlled manual command flow from
`analytics:refresh-test-metrics-daily`.

## Server runner

Repository deploy/runbook evidence says the current production backend target
is Aliyun and that Nginx, Supervisor, and cron point to the managed current
release backend. Queue workers are owned by Supervisor; the Laravel scheduler
runner is cron or an equivalent supervised `schedule:work` process that invokes
the current release backend.

Required production check after deploy:

```bash
cd <current-release>/backend
php artisan schedule:list --json | grep analytics:refresh-test-metrics-daily
crontab -l | grep 'schedule:run'
supervisorctl status 'fap-queue-*'
```

The scheduler registration is not sufficient on its own. Production must have
an active scheduler runner pointing at the managed current release backend.

## Scope

This scheduler refreshes the read model used by Ops KPI cards and daily detail
views. It does not change public routes, frontend payload contracts, result
rendering, sitemap, llms, CMS content, payment/order state machines, or queue
worker definitions.
