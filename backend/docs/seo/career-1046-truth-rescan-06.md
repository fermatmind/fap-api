# CAREER-1046-TRUTH-RESCAN-06

## Purpose

This control records current Career publication and production truth without changing it. The current runtime projection and two fresh `PASS_C03_REVERIFIED_NO_APPLY_REQUIRED` receipts are the authority. The historical 1046/2092 totals are acceptance thresholds only and never supply a crawl cohort.

The final verdict is one of:

- `PASS`: exact 1046 unique slugs, 1046 English targets, 1046 Chinese targets, 2092 localized targets, active C05 cold-start protection, stable receipts, exact public-set equality, and no transport, metadata, or privacy failure.
- `PARTIALLY_BLOCKED`: the current public cohort is safe and internally consistent, but the required population totals or active C05 gate are incomplete.
- `NO_GO`: receipt drift, incomplete evidence, timeout/5xx, private leakage, duplicate or locale identity failure, metadata failure, or public-set mismatch.

Every verdict leaves `CAREER_LINK_PUBLICATION_GATE` physically `CLOSED`. A `PASS` only permits the separately scoped next step; this PR never activates an edge or changes publication state.

## Evidence sequence

1. Lock the exact latest `main` SHA and verify C05 merge `4ad35bd2b15448569a3bafc6bd27f6ad115dc014` is contained.
2. Dispatch the existing Career C03 cache-only workflow in `verify` mode using incident closeout run `31379780335` attempt `1` and its exact receipt/digest.
3. Download the successful immutable receipt and verify its GitHub artifact digest and local receipt SHA-256.
4. Run `scan`. It derives the public cohort from the receipt-bound EN/ZH Jobs and Directory read models, then performs two GET-only rounds across current detail APIs/pages, sitemap, llms, llms-full, family, and industry projections.
5. Dispatch and download a second C03 `verify` receipt.
6. Run `finalize`, then `validate`. Commit only the three safe evidence files.

The two receipt runs must use the same exact control-plane SHA and active revision and must expose identical authority, inventory, published-cohort, detail-coverage, and target-set identities. The C03 workflow also requires the incident-closeout receipt to have that same control-plane SHA. Main movement or runtime drift invalidates the window and requires a new scan.

## Draft/HOLD behavior

No scan or generated verdict evidence may be created until a verifiable pre-C03 PASS receipt exists. If the historical closeout cannot bind the latest control plane, a same-main read-only closeout may re-attest the exact incident facts. If that closeout or either verify run emits a HOLD receipt, the failure lineage is recorded only in the PR-train state, the generated evidence directory remains absent, the PR remains Draft/HOLD, and automatic continuation stops when the receipt forbids retry.

The first execution window on `92e372f8b6adb775a90765d40a6ceaf339717105` reached this boundary. Verify run `31382701613` rejected the historical closeout because its control-plane SHA differed. Same-main read-only closeout run `31382787907` then reported two incomplete public transfers and emitted `HOLD_CONTROL_INCOMPLETE` with `automatic_retry_allowed=false`. Both controls reported zero writes, so no scan snapshot or finalized evidence was produced.

## Runner interface

```bash
php backend/scripts/operations/career_1046_truth_rescan_06.php scan \
  --pre-c03-receipt=/absolute/path/pre.json \
  --pre-artifact-digest=sha256:<digest> \
  --output=/absolute/path/scan.json

php backend/scripts/operations/career_1046_truth_rescan_06.php finalize \
  --pre-c03-receipt=/absolute/path/pre.json \
  --post-c03-receipt=/absolute/path/post.json \
  --scan=/absolute/path/scan.json \
  --output-dir=backend/docs/seo/generated/career-1046-truth-rescan-06 \
  --base-main-sha=<exact-main-sha> \
  --c05-merge-sha=4ad35bd2b15448569a3bafc6bd27f6ad115dc014 \
  --pre-artifact-digest=sha256:<digest> \
  --post-artifact-digest=sha256:<digest>

php backend/scripts/operations/career_1046_truth_rescan_06.php validate \
  --evidence-dir=backend/docs/seo/generated/career-1046-truth-rescan-06
```

The HTTP policy is fixed: credential-free HTTPS GET, redirects disabled, maximum concurrency two, five-second connect timeout, twenty-second request timeout, two rounds, and a thirty-second inter-round gap. A timeout or 5xx stops further bounded scanning. C03 must already prove complete detail coverage, so the scan does not intentionally create cache misses or serve as a cache warm.

## Evidence boundary

The repository retains only:

- normalized allowlisted receipt fields plus immutable receipt/artifact identities;
- per-target status and boolean readback in CSV;
- aggregate counts, set hashes, source lineage, C05 activation facts, zero-write facts, and verdict.

It does not retain response bodies, approval phrases, SSH values, infrastructure topology, raw server paths, cache keys, logs, user data, held-path probes, or private routes. No POST, deploy, migration, cache warm, CMS/database write, publication/indexability mutation, sitemap/llms mutation, Search Channel action, URL submission, queue action, process restart, or edge activation is available through this runner.

## Repository rule impact

This is read-only evidence. It does not change backend content authority, public API contracts, Career eligibility, SEO/GEO enumeration, production deployment behavior, or frontend fallback rules.
