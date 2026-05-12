---
name: fap-api-commerce-payment-entitlement
description: Use for fap-api commerce work involving payments, orders, subscriptions, receipts, entitlement grants, refunds, webhooks, or premium access checks.
---

## Purpose
Protect payment integrity and entitlement correctness for fap-api commerce flows.

## When to use
- Use for payment callbacks, order state, subscription state, entitlement gates, refund handling, and premium access.
- Use when a change affects what a user paid for or can access.

## When not to use
- Do not use for unrelated marketing copy or frontend-only paywall styling.
- Do not use to infer entitlement on the client side.

## Hard invariants
- Do not modify unrelated files.
- Do not stage unrelated dirty files.
- Do not process Informational findings unless explicitly requested.
- Do not expose exploit-ready details in public PR titles/bodies.
- Do not merge unless required checks pass and scope is clean.
- Do not close security findings unless source/test evidence proves fixed.
- Stop if active Critical/High/Medium appears during Low/Informational work.
- Do not weaken previously fixed security boundaries.
- Required checks for fap-api are hygiene, verify-mbti-v2, and verify-mbti-legacy.
- Deploy Application must remain green for deploy or runtime-impacting PRs.
- Backend payment and entitlement state remains the source of truth.

## Standard workflow
1. Identify the payment provider event, order state transition, entitlement record, and idempotency rule.
2. Verify signatures, replay protection, amount/currency matching, and user ownership where relevant.
3. Preserve existing entitlement gates and fail closed on uncertain payment state.
4. Avoid public PR text that reveals provider verification internals.
5. Run common acceptance commands and commerce-specific tests when available.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Report payment boundary, entitlement rule, idempotency behavior, tests, and residual risk.

## Stop conditions
- Stop if a change could grant unpaid access, double-charge, lose refund state, bypass provider verification, or weaken an entitlement gate.
