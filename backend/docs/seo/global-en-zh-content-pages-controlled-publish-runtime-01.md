# GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-PUBLISH-RUNTIME-01

## Executive Summary

This PR adds an official fail-closed Artisan runtime for the Wave 1 English `content_pages` controlled publish path:

```bash
php artisan content-pages:publish-controlled \
  --locale=en \
  --keys=brand,charter,foundation,careers,policies \
  --dry-run \
  --json
```

Execute mode requires `--execute`. The default path is dry-run/no-write. This PR does not run production publish and does not mutate production CMS.

## Runtime Command

Command:

```bash
php artisan content-pages:publish-controlled
```

Supported options:

- `--locale=en`
- `--keys=brand,charter,foundation,careers,policies`
- `--dry-run`
- `--execute`
- `--json`

The command refuses ambiguous `--dry-run --execute` usage.

## Preflight Contract

The runtime verifies:

- locale is exactly `en`
- keys are exactly the five allowlisted Wave 1 English content page keys
- no extra or duplicate key is present
- all target records exist in `content_pages`
- no record creation or upsert path is used
- target records retain the `GLOBAL-EN-ZH-CONTENT-PAGES-CMS-DRAFT-UPDATE-01` source marker
- R2 readiness artifact exists and recommends `all_five_pages`
- foundation content retains planned public-benefit shareholding language
- foundation content does not contain forbidden legal/foundation overclaims
- target pages are non-indexable, preventing sitemap/llms coupling in this task

## Dry-run Contract

Dry-run emits JSON with:

- `dry_run=true`
- `writes_committed=false`
- target counts and would-publish counts
- before-state and after-state preview
- `would_create_count=0`
- closed Search Channel, URL submission, external API, deploy, sitemap/llms/footer flags

## Execute Contract

Execute mode:

- requires `--execute`
- re-runs the same preflight
- wraps writes in a transaction
- publishes only the existing target `content_pages` rows
- never creates records
- never upserts missing records
- never touches protected existing English published pages or non-content-page surfaces
- remains idempotent when run again after a successful publish

## Foundation Governance Boundary

Required foundation fact state:

- `planned_public_benefit_shareholding`

Allowed framing:

- `Public-Benefit Mission and Governance`
- planned public-benefit shareholding arrangement
- public-benefit governance path

Forbidden claims:

- registered foundation
- nonprofit legal status
- charity registration
- donation program
- grant program
- formal board governance
- legal fiduciary duty
- exact ownership percentage
- completed equity transfer
- completed foundation holding

## Discoverability Coupling Policy

The runtime preserves `is_indexable=false`. It fails if any target is already indexable before publish because published + public + indexable content pages are eligible for sitemap inclusion under the existing sitemap generator.

This PR does not add an `--allow-coupled-discoverability` flag. Sitemap/llms/footer/nav exposure remains a separate explicit task.

## Search Channel Safety

The runtime does not call Search Channel queue or submit commands, does not enqueue, and does not submit URLs.

## Idempotency / Transactionality

All execute writes run inside a database transaction. A second execute run skips already-published target records and does not create duplicate records or revisions.

## What Was Not Done

- No production publish
- No production CMS mutation
- No deploy
- No URL submission
- No Search Channel enqueue
- No external search API call
- No sitemap/llms/footer/nav exposure
- No fap-web change

## Final Decision

`content_pages_controlled_publish_runtime_completed_ready_for_backend_deploy_readiness`

## Next Task

`BACKEND-DEPLOY-READINESS｜Deploy content pages controlled publish runtime`
