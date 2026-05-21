# SEARCH-CHANNEL-LIVE-02-PREFLIGHT

Status: blocked on production executor deploy/read-only verification
Date: 2026-05-21

## Scope

This rerun returns to the small human-approved Search Channel live submission canary after `SEARCH-CHANNEL-LIVE-02-EXECUTOR` added the guarded single-item executor.

No live search API call, URL submission, scheduler activation, production env edit, production deploy, DNS change, or additional queue write was performed.

## Executor Evidence

- `SEARCH-CHANNEL-LIVE-02-EXECUTOR` merged in PR #1529.
- Merge commit: `af8a050bfed88631cfc687775402548848ba87a1`.
- GitHub checks for PR #1529 passed.
- The merged executor adds `seo-intel:search-channel-submit` with single queue item enforcement, exact approval phrase enforcement, disabled-by-default live gates, atomic claim/idempotency protection, sanitized audit events, and no scheduler or bulk mode.
- The push to `main` after PR #1529 deployed staging only. It did not deploy production.
- Latest confirmed production workflow dispatch is still run `26174360293`, completed on 2026-05-20, at SHA `effadd311f6c6bdfee137f8c9d10d9edb4ee23c4`.

## Canary Carried Forward

The carried-forward canary from PR #1528 remains the intended production canary candidate:

- queue item `id=1`
- channel `indexnow`
- canonical URL `https://fermatmind.com/en`
- `approval_state=pending`
- `execution_state=dry_run_ready`
- `eligibility_state=eligible`
- `indexability_state=indexable`
- `claim_boundary_state=claim_safe`
- `source_authority=backend_public_surface`
- `source_table=backend_authority_canary_contract`

This rerun did not refresh production DB state because the production executor is not verified on the active production runtime.

## Blocker

`SEARCH-CHANNEL-LIVE-02` is still blocked.

The blocker changed from "executor missing" to "executor not yet verified on production runtime":

- `main` contains the executor at `af8a050bfed88631cfc687775402548848ba87a1`.
- Production has not been confirmed deployed to that SHA.
- Local read-only SSH to `ubuntu@139.224.130.204` reached TCP/22 but timed out during SSH banner exchange, so this preflight could not run `php artisan seo-intel:search-channel-submit --queue-item-id=1 --dry-run --json` on production.
- The exact live approval phrase is intentionally withheld until production is deployed to the executor SHA and a production read-only dry-run confirms the current queue item state.

## Required Rerun

Before producing the live canary approval phrase, run backend deployment readiness for the current `main` SHA, deploy production only after the explicit deploy approval phrase, then rerun this preflight.

Required production read-only checks after deploy:

```bash
cd /var/www/fap-api/current/backend
php artisan seo-intel:search-channel-submit --queue-item-id=1 --dry-run --json
php artisan seo-intel:search-channel-queue --dry-run --no-write --json --channel=indexnow --limit=1
```

Passing criteria:

- active production runtime is at `af8a050bfed88631cfc687775402548848ba87a1` or newer;
- queue item `1` is still `pending` and `dry_run_ready`;
- executor dry-run returns `status=success`;
- `external_calls_attempted=false`;
- `search_submission_attempted=false`;
- `writes_attempted=false`;
- `writes_committed=false`;
- scheduler remains disabled for live submission;
- the approval phrase is produced only from that production dry-run evidence.

## Decision

Do not run `SEARCH-CHANNEL-LIVE-02` yet.

Next operational step is backend deploy readiness/deploy for the executor SHA, followed by another `SEARCH-CHANNEL-LIVE-02-PREFLIGHT` rerun. Only that rerun may output the exact human approval phrase for the live canary.
