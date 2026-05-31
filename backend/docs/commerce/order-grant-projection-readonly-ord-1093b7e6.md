# Order Grant / Projection Read-only Check

Task: `ORDER-GRANT-PROJECTION-READONLY-REDACTED-1093B7E6`

Order checked:

- `redacted_production_order_ref_1093b7e6`

## Summary

Production read-only verification shows this order is not currently paid.

- `orders.status`: `pending`
- `orders.payment_state`: `pending`
- `orders.grant_state`: `not_started`
- `benefit_grants`: `0`
- `unified_access_projections`: missing for the order attempt
- `exact_result_entry.reason_code` read-only inference: `projection_missing_result_ready`
- `exact_result_entry.ready_to_enter` read-only inference: `false`

The order wait page behavior is consistent with the backend state: payment has not been confirmed into `paid`, no grant has been issued, and no unified access projection is ready.

## Read-only Evidence

The production checks used SELECT-only Laravel query builder reads through the production application context. No public order endpoint was called because that endpoint can invoke projection repair when a result exists.
The committed packet stores only a redacted production order reference; the raw production order number is intentionally not retained in repository artifacts.

Observed order state:

```json
{
  "status": "pending",
  "payment_state": "pending",
  "grant_state": "not_started",
  "provider": "alipay",
  "target_attempt_id_present": true,
  "paid_at_present": false,
  "fulfilled_at_present": false,
  "last_reconciled_at_present": false
}
```

Observed payment state:

```json
{
  "payment_attempts_count": 1,
  "latest_payment_attempt_state": "client_presented",
  "external_trade_no_present": false,
  "provider_trade_no_present": false,
  "callback_received_at_present": false,
  "verified_at_present": false,
  "finalized_at_present": false,
  "payment_events_count": 0
}
```

Observed grant/projection state:

```json
{
  "benefit_grants_count": 0,
  "unified_access_projection_exists": false,
  "result_exists": true,
  "access_state": "locked",
  "report_state": "ready",
  "pdf_state": "missing",
  "reason_code": "projection_missing_result_ready",
  "ready_to_enter": false
}
```

## Interpretation

This is not a paid-no-grant case at the time of verification. It is a pending Alipay client-presented order with no provider trade number, no callback, no verified payment attempt, and no payment event.

Because `payment_state` remains `pending`, the backend must not grant benefits or mark the unified access projection ready. A full result redirect after payment requires payment confirmation first, then grant/projection readiness.

## What Was Not Done

- No production write was performed.
- No env value was edited.
- No migration was run.
- No collector, scheduler, or repair command was run.
- No benefit grant was created.
- No unified access projection was written.
- No order state was changed.
- No public order endpoint or builder repair path was invoked.

## Next Step

Recommended next task:

`PAYMENT-ALIPAY-RETURN-VERIFY-01` - investigate why this Alipay order remained `client_presented` / `payment_state=pending` after the user believed payment completed, using read-only provider-safe evidence first.
