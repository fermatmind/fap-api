# Alipay Pending Payment Compensation

## Purpose

Alipay can confirm a payment while the local webhook path does not persist a
payment event. In that case the order remains `created` or `pending`, and the
existing paid-order repair scheduler cannot grant report access because the
order has not transitioned to `paid`.

The scheduled compensation job queries stale Alipay orders and only mutates an
order when Alipay confirms a terminal paid state.

## Scheduled Command

```bash
php artisan commerce:compensate-pending-orders --provider=alipay --include-created --only-stale --limit=10 --older-than-minutes=60
```

Runtime policy:

- Runs every ten minutes through Laravel scheduler.
- Must be registered in `bootstrap/app.php` via `withSchedule`; this is the
  runtime scheduler source used by the production Laravel 11 bootstrap.
- Uses `withoutOverlapping`.
- Includes stale `created` and `pending` Alipay orders.
- Does not pass `--close-expired`, so it does not automatically close or expire
  unpaid historical orders.
- Keeps the provider query window conservative with `--limit=10`,
  `--older-than-minutes=60`, and `--only-stale`.

## Scheduler Runner Verification

The command being present in `app/Console/Kernel.php` is not sufficient for the
current bootstrap path. Verify the runtime scheduler and the server runner
separately:

```bash
php artisan schedule:list --json | jq -r '.[] | select(.command | contains("commerce:compensate-pending-orders"))'
```

Production must also have a process manager, cron, or timer that invokes
Laravel's scheduler, for example `php artisan schedule:run` every minute or
`php artisan schedule:work` under a process supervisor. If the command appears
in `schedule:list` but the runner is absent, automatic compensation will not
execute.

## Manual Diagnosis

Use dry-run before any targeted repair:

```bash
php artisan commerce:compensate-pending-orders --provider=alipay --order=<order_no> --dry-run --include-created --only-stale
```

Expected paid confirmation shape:

```text
queried_count=1
paid_count=1
failed_count=0
unresolved_count=0
unsupported_count=0
```

## Manual Targeted Repair

Only after provider confirmation:

```bash
php artisan commerce:compensate-pending-orders --provider=alipay --order=<order_no> --include-created --only-stale
```

Then verify:

- `orders.payment_state=paid`
- `orders.grant_state=granted`
- active `benefit_grants` row exists
- `unified_access_projections.access_state=ready`
- `exact_result_entry.ready_to_enter=true`

## Non-Goals

- Do not use this scheduler to submit URLs or trigger Search Channel.
- Do not use this scheduler to mutate CMS/content.
- Do not enable `--close-expired` without a separate risk review.
