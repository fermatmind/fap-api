# Career 1046 public product verify-only control

## Scope

`Career 1046 Public Product Verify Only` is a protected, manual-dispatch-only production evidence lane. This repository change defines the lane; it does not dispatch it or establish an SSH session.

The lane requires exact latest `main`, an active production release whose SHA equals that control plane, the exact active Career 1046 generation ID, the raw SHA-256 of `active-generation.json`, and an exact operator phrase binding all of those identities. The protected production environment supplies routing and SSH material; receipts never expose those values or raw public payloads.

## Read-only assertions

The streamed runner first proves the active release name, `REVISION`, and checked-in runner SHA. It then reads the root pointer and byte-identical immutable generation pointer, validates the canonical pointer payload hash, frozen 1046 authority hashes, 1046/2092 counts, and closed sitemap/llms/Search flags. The root pointer must bind the exact generation manifest, EN/ZH directory documents, and EN/ZH detail documents by path and raw SHA-256.

The immutable product documents must contain one exact 1046-slug set in each locale and one exact 2092 locale-row set. Every directory row must bind the canonical hash of its same-generation detail payload. Every public request carries the verify-only marker, current timestamp, and a short-lived APP_KEY HMAC over its exact request URI. Only valid signatures on the exact Career directory/detail paths bypass public-content metrics and Career directory SLO recording; unsigned, expired, forged, or non-Career requests keep normal telemetry. Authorized directory and review-projection index reads suppress cache-state logging. Authorized details select a fail-closed response-cache read path that never promotes legacy state, clears negative cache state, dispatches a warm, logs cache recovery, or returns a degraded shell. Detail comparison treats integral JSON numbers such as `1` and `1.0` as semantically equal while preserving strict comparison for every other value. The public API is then read without redirects or retries:

- EN directory: exactly 1046 unique target slugs;
- ZH directory: exactly 1046 unique target slugs;
- EN/ZH detail targets: exactly 2092;
- missing, duplicate, extra, 404, 5xx, timeout, other status, and generation-payload mismatch: all zero.

After all public reads, `/current` is resolved again and its release name and `REVISION` must still match the approved release. Both active and immutable pointer bytes are then read again and must remain byte-identical to the approved pointer SHA. The initially uploaded failure-safe receipt contains no unvalidated workflow input; validated identities are added only after their bounded syntax and exact approval phrase pass. The final receipt contains only release/generation hashes, bounded counts, safe failure codes, and explicit zero-write guarantees. It never stores response bodies, raw paths, topology, SSH diagnostics, or slugs.

## Negative boundary

This lane has no apply mode. It does not repair, warm, rollback, deploy, migrate, restart, mutate DB/CMS/cache/pointers, release sitemap or llms state, submit Search/IndexNow/GSC/URL Inspection requests, or change canonical/noindex/JSON-LD/claims. It has no automatic retry. A timeout, transport error, malformed payload, pointer drift, generation mismatch, missing/duplicate/extra target, or any non-exact count fails closed.

## Repository-only acceptance

Do not dispatch the workflow during this PR. Validate the control plane only:

```bash
cd backend
php artisan test tests/Sre/Career1046PublicProductVerifyOnlyWorkflowTest.php tests/Feature/Observability/CareerRuntimeSloAlertingTest.php tests/Feature/Ops/PublicContentRuntimeMetricsTest.php --filter='(verify_only|workflow_is_manual_protected_read_only_and_uploads_every_receipt)' --no-ansi
vendor/bin/pint --test app/Http/Middleware/RecordCareerRuntimeSlo.php app/Http/Middleware/RecordPublicContentRuntime.php scripts/operations/career_1046_public_product_verify_only.php tests/Sre/Career1046PublicProductVerifyOnlyWorkflowTest.php tests/Feature/Observability/CareerRuntimeSloAlertingTest.php tests/Feature/Ops/PublicContentRuntimeMetricsTest.php
php -l scripts/operations/career_1046_public_product_verify_only.php
php -l tests/Sre/Career1046PublicProductVerifyOnlyWorkflowTest.php
```

```bash
actionlint .github/workflows/career-1046-public-product-verify-only.yml
php backend/scripts/operations/verify_career_1046_current_state_freeze.php
ruby -e "require 'yaml'; YAML.load_file('docs/codex/pr-train.yaml'); puts 'yaml ok'"
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
git diff --check
```
