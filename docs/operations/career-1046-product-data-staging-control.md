# Career 1046 same-generation product-data staging control

## Scope

`Career 1046 Product Data Staging Production Ops` is a protected, manual-dispatch-only two-phase control. This repository change defines the control; it does not authorize or execute either phase.

The control stages exactly one immutable generation directory containing eight Task 4 documents: projection, ledger, EN/ZH details, EN/ZH directories, generation manifest, and candidate receipt. Every document is bound to one `career-1046-<32 hex>` generation ID and the frozen 1046 slug / 2092 locale-row authority.

## Required evidence

Both modes require exact latest `main`, an exact active release whose SHA equals that control plane, an unexpired GitHub Actions artifact named `career-1046-immutable-candidate`, its GitHub artifact digest, raw candidate-bundle SHA-256, canonical candidate-receipt SHA-256, the current 342/30 generation ID, and the raw current pointer SHA-256.

Apply additionally requires a successful fresh preflight receipt from this workflow, its exact run/attempt and receipt SHA-256, plus the workflow-generated approval phrase binding every preceding identity. Candidate and preflight artifacts are downloaded by immutable IDs. A moving branch, mismatched digest, extra candidate file, stale release, changed pointer, existing destination, or prior staging residue fails closed.

## Phase boundaries

Preflight performs zero remote writes. It validates the candidate, the active 342/30 pointer and the absence of the destination/residue.

Apply makes one no-clobber temporary directory, writes exactly eight canonical JSON files with exclusive creation, performs per-file full SHA-256 readback, renames the directory once, then repeats committed readback. It verifies `active-generation.json` is byte-identical before and after. It does not create or switch any active or public-cache pointer.

The control never writes DB or CMS state, publishes content, deploys, migrates, restarts, warms, releases sitemap/llms state, or submits Search, IndexNow, GSC, or URLs. It has no automatic retry, cleanup, rollback, or transport-outcome inference. If an apply transport or receipt is ambiguous, the runner-side receipt records an indeterminate write state for human review.

## Repository-only acceptance

This PR must not dispatch the workflow or establish an SSH session. Validate only the control plane:

```bash
cd backend
php artisan test tests/Sre/Career1046ProductDataStagingWorkflowTest.php --no-ansi
vendor/bin/pint --test scripts/operations/career_1046_product_data_staging.php tests/Sre/Career1046ProductDataStagingWorkflowTest.php
php -l scripts/operations/career_1046_product_data_staging.php
php -l tests/Sre/Career1046ProductDataStagingWorkflowTest.php
```

```bash
actionlint .github/workflows/career-1046-product-data-staging-production-ops.yml
php backend/scripts/operations/verify_career_1046_current_state_freeze.php
ruby -e "require 'yaml'; YAML.load_file('docs/codex/pr-train.yaml'); puts 'yaml ok'"
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
git diff --check
```
