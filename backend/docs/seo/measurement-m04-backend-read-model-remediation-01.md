# MEASUREMENT-M04-BACKEND-READ-MODEL-REMEDIATION-01

## Scope

This backend-only change repairs two M04 read-model contracts without executing a refresh, production migration, deployment, or historical backfill:

1. `report_ready` is the first verified content-availability stage after a valid start and successful submit. It is independent of result view and the order → paid → unlock chain.
2. `from` and `to` are inclusive `Asia/Shanghai` reporting dates over UTC-stored timestamps. Database queries use a UTC-equivalent half-open interval.

The `analytics_funnel_daily` grain remains `day × org_id × scale_code × locale`.

## Report-ready contract

A candidate counts once per attempt when all of the following are true:

- the attempt has a non-null start row;
- the attempt has `submitted_at` or a successful `attempt_submissions` row;
- a ready report snapshot with readable report content exists, or a complete ready access projection plus result and projection receipt exists;
- the selected readiness time is not earlier than submit.

A ready snapshot does not require an order, payment, benefit grant, unlock, or first result/report view. Snapshot and projection candidates are de-duplicated by attempt and use the earliest valid readiness timestamp. Missing submit, submit before start, ready before submit, a non-ready snapshot, or a non-ready projection fails closed.

The existing commercial chain remains unchanged: view gates order, order gates payment, and payment gates unlock. This change does not alter payment, grant, or GA4 event taxonomy.

## Reporting-day contract

- `ANALYTICS_FUNNEL_REPORTING_TIMEZONE` defaults to `Asia/Shanghai`.
- `ANALYTICS_STORAGE_TIMEZONE` defaults to `UTC`.
- Invalid or empty timezone configuration throws; it never falls back to the application timezone.
- For reporting day `2026-07-13`, the storage query window is `[2026-07-12T16:00:00Z, 2026-07-13T16:00:00Z)`.
- `2026-07-13T15:59:59Z` aggregates to reporting day `2026-07-13`.
- `2026-07-13T16:00:00Z` aggregates to reporting day `2026-07-14`.

Builder and refresh-command output expose:

- `reporting_timezone`
- `storage_timezone`
- `window_utc_start`
- `window_utc_end_exclusive`

## Activation boundary

Repository merge establishes code readiness only. `MEASUREMENT_M04_BACKEND_READ_MODEL_REMEDIATION_01_CODE_COMPLETE` may be recorded after the PR is merged and post-merge checks pass. Production read-model truth remains inactive until a separately controlled deployment and same-window `2026-07-13..2026-08-09` refresh/readback complete.

This scope does not modify the M05 attribution controller, event taxonomy, measurement event contract, failure-cohort implementation, database schema, CMS, frontend, experiment state, or production data.
