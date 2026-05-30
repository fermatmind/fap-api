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
php artisan commerce:compensate-pending-orders --provider=alipay --include-created --limit=50 --older-than-minutes=15
```

Runtime policy:

- Runs every five minutes through Laravel scheduler.
- Uses `withoutOverlapping`.
- Includes stale `created` and `pending` Alipay orders.
- Does not pass `--close-expired`, so it does not automatically close or expire
  unpaid historical orders.
- Keeps the query window conservative with `--limit=50`.

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
