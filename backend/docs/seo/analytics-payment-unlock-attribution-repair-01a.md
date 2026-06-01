# ANALYTICS-PAYMENT-UNLOCK-ATTRIBUTION-REPAIR-01A

## Purpose

This PR adds a read-only attribution diagnostic for the gap between payment success, benefit grant creation, and report access projection readiness.

The diagnostic does not repair orders, create grants, refresh projections, enqueue Search Channel items, call payment providers, submit URLs, mutate CMS content, or change analytics settings.

## Source of Truth

- `orders.payment_state` and paid timestamps indicate backend-confirmed payment state.
- Active `benefit_grants` indicate report unlock truth.
- `unified_access_projections` indicate whether the result/report entry surface is ready.
- `payment_events` explain semantic rejects and post-commit failures.
- `skus.kind=report_unlock` scopes the diagnostic to report unlock products only.

Frontend state, GA/Baidu dashboards, crawler logs, public pages, sitemap, llms, and local copies are observation-only and are not used as authority.

## Diagnostic Categories

- `paid_granted_projection_ready`
- `paid_granted_projection_missing`
- `paid_granted_projection_not_ready`
- `paid_no_grant_owner_or_scale_mismatch`
- `paid_no_grant_post_commit_failed`
- `paid_no_grant_repairable_candidate`
- `payment_pending_client_presented`
- `payment_not_paid_other`

Samples use a short SHA-256 reference instead of raw order numbers.

## Deferred

- No production repair write is run in this PR.
- No Alipay provider verification is run in this PR.
- No owner-mismatch override is implemented.
- No Ops UI is added in this PR.
- No GA/Baidu key-event or analytics configuration change is made.

## Next Task

`ANALYTICS-PAYMENT-UNLOCK-ATTRIBUTION-REPAIR-01B｜Expose or run read-only payment-to-unlock attribution diagnostics`
