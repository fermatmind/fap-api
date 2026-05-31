# Alipay Owner Mismatch Review

## Purpose

This runbook covers Alipay orders that are already marked `paid` but remain
blocked from entitlement repair because the payment event or repair guard found
`ATTEMPT_OWNER_MISMATCH`.

Owner mismatch is a trust-boundary condition. It must not be folded into the
automatic Alipay pending compensation scheduler.

## Read-Only Review Rules

- Do not run `commerce:compensate-pending-orders` for these orders.
- Do not run `commerce:repair-paid-orders` for these orders.
- Do not read raw logs.
- Do not print order numbers, attempt IDs, user IDs, anon IDs, emails, provider
  trade numbers, access tokens, or payment payloads.
- Use aggregate counts and one-way hashes when an item needs stable tracking.
- Treat unresolved owner mismatch as a blocker for automatic repair.

## Classification

Use the following buckets:

- `safe_order_grant_state_sync_after_approval`: the order already has an active
  benefit grant and a ready unified access projection, but `orders.grant_state`
  remains stale. A future controlled repair may sync lifecycle state only after
  explicit approval.
- `human_ownership_review_required`: the order is paid and has a result, but no
  active benefit grant or ready access projection exists. A human must compare
  order ownership, attempt ownership, and payment context before any repair.
- `automatic_repair_forbidden`: true or unresolved owner mismatch. The scheduler
  must not auto-grant or bypass the owner guard.

## Future Controlled Repair Requirements

Before any write:

1. Confirm exact scoped approval phrase for a controlled owner-mismatch repair.
2. Re-run a read-only snapshot and confirm counts have not drifted.
3. For state-sync-only items, verify active grant and ready projection still
   exist before syncing order lifecycle fields.
4. For human-review items, verify ownership using approved internal context
   without exposing PII in command output or PR artifacts.
5. Stop if any item looks like a real cross-owner payment or attempt mismatch.

## Non-Goals

- No production repair in the review PR.
- No automatic scheduler bypass for `ATTEMPT_OWNER_MISMATCH`.
- No Search Channel action, URL submission, CMS mutation, deploy, or frontend
  change.
