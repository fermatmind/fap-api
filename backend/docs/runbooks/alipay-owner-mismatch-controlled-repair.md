# Alipay Owner Mismatch Controlled Repair

## Purpose

This runbook covers a narrow state-sync repair for Alipay orders previously
classified by `PAYMENT-ALIPAY-OWNER-MISMATCH-REVIEW-03`.

It applies only when an order is already paid, already has an active benefit
grant, already has a ready unified access projection, and only the order
lifecycle fields are stale.

## Allowed Repair

After exact approval and a fresh dry-run:

- update `orders.grant_state` to `granted`;
- update `orders.status` to `fulfilled`;
- set `orders.fulfilled_at` only if it is still null;
- update `orders.updated_at`.

## Required Preflight

Before any write:

1. Confirm the exact approval phrase names
   `PAYMENT-ALIPAY-OWNER-MISMATCH-CONTROLLED-REPAIR-04`.
2. Confirm the base paid/no-grant owner-mismatch count.
3. Confirm exactly one state-sync candidate exists.
4. Confirm the candidate has active `benefit_grants` evidence through the same
   `target_attempt_id`.
5. Confirm the same `target_attempt_id` has a ready
   `unified_access_projections` row.
6. Stop if the candidate count is not exactly one.

## Forbidden Actions

- Do not create `benefit_grants`.
- Do not mutate `unified_access_projections`.
- Do not run `commerce:compensate-pending-orders`.
- Do not run generic post-commit repair for owner mismatch.
- Do not read raw logs.
- Do not print order numbers, attempt IDs, user IDs, anon IDs, emails, provider
  trade numbers, access tokens, or payment payloads.
- Do not deploy, mutate CMS/content, submit URLs, trigger Search Channel, or
  change fap-web.

## Post-Repair Verification

The expected aggregate result is:

- paid/no-grant owner-mismatch count decreases by one;
- state-sync candidate count becomes zero;
- remaining owner-mismatch items stay blocked for human ownership review;
- benefit grant creation count remains zero;
- projection mutation remains false.
