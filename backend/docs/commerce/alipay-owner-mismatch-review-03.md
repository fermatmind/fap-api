# PAYMENT-ALIPAY-OWNER-MISMATCH-REVIEW-03

## Executive Summary

Read-only production verification found eight Alipay orders with
`payment_state=paid` and `grant_state!=granted` that are blocked by
`ATTEMPT_OWNER_MISMATCH`.

The automatic Alipay pending compensation scheduler is running, but owner
mismatch remains intentionally outside automatic repair. The scheduler should
not grant entitlements or bypass ownership guards for these orders.

## Scope

This packet is a repair-preflight review only. It records the current safe
classification and future repair policy for the eight owner-mismatch items.

No production repair, production compensation, raw log read, CMS mutation,
deploy, Search Channel action, URL submission, or fap-web change was performed
for this PR.

## Findings

The eight paid/no-grant orders split into two operational groups:

- One item is a historical state inconsistency. It already has active
  entitlement evidence and a ready unified access projection, but
  `orders.grant_state` is stale. This can be considered for a future
  state-sync-only repair after explicit approval.
- Seven items are paid and have result rows, but lack active benefit grants and
  unified access projections. They require human ownership review before any
  controlled repair.

All eight have rejected payment events with `ATTEMPT_OWNER_MISMATCH`. This is a
blocking semantic reject reason and must remain outside automatic scheduler
repair.

## Pending Compensation Context

The reconciled pending backlog is a separate issue. The sampled pending orders
that were updated by the scheduler do not have `external_trade_no`,
`provider_trade_no`, provider session references, or payment events. Their
attempts show `query_unknown`, so provider lookup cannot safely confirm payment.

This PR does not change pending compensation behavior.

## Future Repair Policy

Future work should be split:

1. A controlled state-sync repair for the one item that already has active grant
   and ready projection evidence.
2. A separate human-reviewed owner-mismatch decision packet for the seven items
   without active grants.
3. A controlled repair only for items whose ownership is explicitly approved.

Confirmed or unresolved owner mismatch must not be repaired automatically.

## Validation Contract

The generated JSON for this PR is the machine-readable source for the focused
test. It asserts:

- eight current paid/no-grant owner-mismatch items;
- one state-sync candidate;
- seven human-review candidates;
- automatic owner-mismatch repair is forbidden;
- production mutation gates are closed.

## Final Decision

`owner_mismatch_review_completed_ready_for_controlled_repair_planning`

## Next Task

`PAYMENT-ALIPAY-OWNER-MISMATCH-CONTROLLED-REPAIR-04` only after explicit future
approval.
