# CAREER-1046-TRUTH-RESCAN-06

## Purpose

This control records current Career publication and production truth without changing it. The current runtime projection and two fresh `PASS_C03_REVERIFIED_NO_APPLY_REQUIRED` receipts are the authority. The historical 1046/2092 totals are acceptance thresholds only and never supply a crawl cohort.

The final verdict is one of:

- `PASS`: exact 1046 unique slugs, 1046 English targets, 1046 Chinese targets, 2092 localized targets, active C05 cold-start protection, stable receipts, exact public-set equality, and no transport, metadata, or privacy failure.
- `PARTIALLY_BLOCKED`: the current public cohort is safe and internally consistent, but the required population totals or active C05 gate are incomplete.
- `NO_GO`: receipt drift, incomplete evidence, timeout/5xx, private leakage, authority-row duplication, conflicting URL forms for one identity, locale identity failure, metadata failure, or public-set mismatch.

Every verdict leaves `CAREER_LINK_PUBLICATION_GATE` physically `CLOSED`. A `PASS` only permits the separately scoped next step; this PR never activates an edge or changes publication state.

## Evidence sequence

1. Lock the exact latest `main` SHA and verify C05 merge `4ad35bd2b15448569a3bafc6bd27f6ad115dc014` is contained.
2. Dispatch a fresh read-only C03 `incident_closeout` on that exact main, then bind its exact run, attempt, receipt SHA-256, and artifact digest into a fresh C03 `verify`.
3. Download the successful immutable pre-scan receipt and verify its GitHub artifact digest and local receipt SHA-256.
4. Run `scan`. It derives the public cohort from the receipt-bound EN/ZH Jobs and Directory read models, then performs two GET-only rounds across current detail APIs/pages, sitemap, llms, llms-full, family, and industry projections.
5. Dispatch and download a second C03 `verify` receipt.
6. Run `finalize`, then `validate`. Commit only the three safe evidence files.

The two receipt runs must use the same exact control-plane SHA and active revision and must expose identical authority, inventory, published-cohort, detail-coverage, and target-set identities. The C03 workflow also requires the incident-closeout receipt to have that same control-plane SHA. Main movement or runtime drift invalidates the window and requires a new scan.

## Draft/HOLD behavior

No scan or generated verdict evidence may be created until a verifiable pre-C03 PASS receipt exists. If the historical closeout cannot bind the latest control plane, a same-main read-only closeout may re-attest the exact incident facts. If that closeout or either verify run emits a HOLD receipt, the failure lineage is recorded only in the PR-train state, the generated evidence directory remains absent, the PR remains Draft/HOLD, and automatic continuation stops when the receipt forbids retry.

The first execution window on `92e372f8b6adb775a90765d40a6ceaf339717105` reached this boundary. Verify run `31382701613` rejected the historical closeout because its control-plane SHA differed. Same-main read-only closeout run `31382787907` then reported two incomplete public transfers and emitted `HOLD_CONTROL_INCOMPLETE` with `automatic_retry_allowed=false`. Both controls reported zero writes, so no scan snapshot or finalized evidence was produced.

The bounded transport repair merged in PR #3624 as `4c3f775151ad62b6750e7d45319d75aa49adf8b2`. A new same-main read-only closeout run `31387930639` then emitted `PASS_INCIDENT_CLOSED` with zero retries, transport failures, HTTP failures, private leakage, or writes. The required pre-C03 verify run `31388220236` did not emit the scan-authorizing status: it emitted `PASS_RECOVERY_REQUIRED` with `public_converged=false`, even though the authority remained 342/684, the published cohort and detail coverage remained the same 30/60 set, the internal job/directory/sitemap-source checks were converged, the detail repair target count was zero, and all transport/HTTP/private/write counts were zero. This scope explicitly forbids `apply` and requires a stop on recovery-required, so scan, post-verify and finalize were not run. `origin/main` also advanced to `53917f63469b603104e68e2129011fa0d92de5fa` before any scan began; no temporary scan snapshot exists and PR6 remains prohibited.

The independent six-surface diagnostic and portable shared-readback repair subsequently merged as `058fd37125b532bd716149f2fe2977191136dfa4` and `935584a5f26b05ba987f271529ea3bb5ff02008a`. On the latter exact main, closeout run `31394324050` emitted `PASS_INCIDENT_CLOSED` and pre-verify run `31394772837` emitted `PASS_C03_REVERIFIED_NO_APPLY_REQUIRED`; all six fixed public surfaces matched the expected 30/60 cohort, terminal transport and write counts were zero, and the Career link gate remained `CLOSED`. PR5 then started a temporary scan, which failed closed before retaining any response body or target rows with `SURFACE_DUPLICATE_IDENTITY`. The required post-verify run `31395418526` emitted `HOLD_CONTROL_INCOMPLETE` at its mode-input boundary with `automatic_retry_allowed=false`. Although its visible inputs matched the preceding successful verify, the control contract prohibits an automatic retry. The temporary snapshot is not versioned evidence, `finalize` was not run, #3619 remains Draft/HOLD, and PR6 remains prohibited.

That historical scan classified every repeated Career URL as a duplicate identity. The repaired scanner instead records safe per-surface aggregates for each round: occurrence count, unique identity count, repeated-reference count, conflicting-identity count, unique row-set SHA-256, and expected-set match. An identical sitemap/llms/llms-full URL may appear more than once without changing the unique identity set and is diagnostic only. A Jobs or Directory authority-row duplicate remains a hard failure, as does one identity resolving to multiple host, locale, path, query, slash, or other URL forms. No aggregate contains a URL, response body, cache key, private path, or infrastructure value.

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

Semantic mismatches are retained as safe aggregate facts so a successful post-C03 receipt can close the window with a formal `NO_GO`. Repeated text-surface references are informational; only conflicting identity forms or a unique-set mismatch make the text surface unsafe.

A discarded pre-final window exposed one detail response with status zero but no curl errno. That observation identified a runner contract gap: detail non-200 and non-terminal transport failures must become target-level semantic failures and must not prevent the second round. The focused repair now keeps those rows with failed metadata booleans, while timeout and 5xx remain the only detail errors that block the round. The discarded snapshot is not evidence and is not reused.

## Final read-only window

The completed window is bound to exact main `c8015fac05ae544c8730adac6e18f387c76b2e33` and active revision `40020ab7ef269ee56ce597e9f2fd2fbb99e83549`:

- incident closeout run `31404596553` emitted `PASS_INCIDENT_CLOSED`;
- pre-verify run `31404898744` and post-verify run `31406086532` both emitted `PASS_C03_REVERIFIED_NO_APPLY_REQUIRED`;
- pre/post authority, inventory, published cohort, detail coverage, and target-set identities were stable;
- both scan rounds completed with 30 unique slugs / 60 locale rows, zero timeout, 5xx, redirect, private leakage, or conflicting text-surface identity;
- sitemap and llms each exposed 60 occurrences / 60 unique identities; llms-full exposed 120 occurrences / 60 unique identities, with 60 identical repeated references and zero conflicts per round;
- 24 targets per round failed the public detail API indexability contract, split evenly as 12 English and 12 Chinese targets. HTTP, page canonical, reciprocal hreflang, page robots, identity, and all public-set membership checks otherwise passed.

The finalized verdict is `NO_GO`. The authority population is also only 342/684, the public cohort is 30/60, and the active revision does not contain the C05 cold-start gate. Those shortfalls would be `PARTIALLY_BLOCKED` only if the scan were otherwise safe; the repeated API indexability mismatch is a hard failure, so `NO_GO` takes precedence. The evidence is complete and mergeable under the PR5 lifecycle, but PR6 production execution remains prohibited and the Career link publication gate remains `CLOSED`.

## Evidence boundary

The repository retains only:

- normalized allowlisted receipt fields plus immutable receipt/artifact identities;
- per-target status and boolean readback in CSV;
- aggregate counts, set hashes, source lineage, C05 activation facts, zero-write facts, and verdict.

It does not retain response bodies, approval phrases, SSH values, infrastructure topology, raw server paths, cache keys, logs, user data, held-path probes, or private routes. No POST, deploy, migration, cache warm, CMS/database write, publication/indexability mutation, sitemap/llms mutation, Search Channel action, URL submission, queue action, process restart, or edge activation is available through this runner.

## Repository rule impact

This is read-only evidence. It does not change backend content authority, public API contracts, Career eligibility, SEO/GEO enumeration, production deployment behavior, or frontend fallback rules.
