# FermatMind English content parity program retrospective

Snapshot date: 2026-08-08

Program window: 2026-07-30 through 2026-08-08

Machine ledger: `backend/docs/seo/generated/english-content-parity-program-ledger.v1.json`

Schema: `backend/docs/schemas/english-content-parity-program-ledger.v1.schema.json`

## Executive summary

The W1-W9 English parity program achieved substantially more than a translation pass. It built English public and private content packages, independent QA, revision-safe CMS promotion, readback, rollback, and live verification across multiple authority stores. That architectural work explains why the execution history is much larger than the visible content inventory.

The reproducible scan found 221 merged program-related commit identities across `fap-web` and `fap-api`. Of these, 170 carry an explicit PR number; the remaining 51 are commit-only evidence or ledger/supporting changes and must not be presented as independently reviewed PRs. The machine ledger keeps both numbers and deduplicates by repository, PR number, task id, then commit fallback.

The largest avoidable cost came from the retired V1 control process:

- 58 W9/QA-related merged evidence identities;
- 46 status/CONTROL identities;
- 31 repair iterations, of which 8 are explicitly classified reset/rework/repair tasks;
- repeated candidate rebinding caused by a global mutable master SHA;
- separate BLOCKED, reset, refreeze, acceptance and human-approval transitions.

V2 removes those repeated approvals. A new cohort should normally use one Producer PR with same-PR W9, followed by exact-package automated dry-run, import, readback, publication and live QA. Package SHA remains an integrity and rollback identity, not an operator permission token.

## Current lane truth

Status is intentionally split into five independent questions. `asset_ready` means a frozen package exists; `qa_ready` means independent QA passed; `promotion_ready` means the package reached dry-run readiness; `live_verified` requires immutable live-QA or equivalent deployed verification; `control_synced` means the generated V2 view reflects stronger lane-local evidence.

| Lane | Initial cohort | Merged evidence identities | Repository truth | Live verified | Control synced | Remaining work |
| --- | --- | ---: | --- | --- | --- | --- |
| W1 MBTI | 7 comparisons + 46 result rows | 34 | `live_qa_pass` | yes | yes | none in this program |
| W2 Big Five | 52 public + 50 historical + 16 result units | 13 | `qa_pass` | no for the result cohort | yes | V2 promotion and live QA for the frozen result cohort |
| W3 Editorial CMS | 17 Articles + 20 Career Guides | 72 | aggregate `dry_run_ready` | partial: Articles have a live receipt chain; Career Guides do not | no at subscope level | register Article receipts; complete Career Guide promotion/live QA |
| W4 RIASEC | 14 groups / 1,550 rows | 30 | `live_qa_pass` | yes | yes | none in this program |
| W5 Enneagram | 58 public control + 630 private result payloads | 14 | `live_qa_pass` | yes | content state yes; inputs contain a duplicate receipt row | deduplicate V2 input registration |
| W6 IQ | operator-deferred | 0 | `deferred` | no | intentionally not applicable | no action until the operator reactivates IQ |
| W7 EQ | package-defined result/report/share cohort | 8 | `live_qa_pass` | yes | no | register the lane manifest and receipt facts in V2 inputs |
| W8 Career Job | 1,046 bilingual entities | 7 | `deployed_verified` | yes | no | register the lane manifest/closeout facts in V2 inputs |
| W9 QA | same-PR independent review capability | 23 | capability, not a production lane | not applicable | not applicable | continue as a required Producer-PR check |

The table is not a release authorization. It summarizes existing immutable evidence and the public readback captured by the scanner.

## Control-materialization drift

The scan found seven deterministic drift records:

1. W3 Articles has a `live_qa_pass` receipt chain while its materialized subscope remains `dry_run_ready`.
2. W5 registers the same receipt chain twice.
3. W7's lane manifest is `live_qa_pass`, but the master is `not_started`.
4. W7's lane manifest is absent from V2 `lane_manifests` inputs.
5. W7's receipt chain is ahead of the materialized master.
6. W8's lane manifest is `deployed_verified`, but the master is `not_started`.
7. W8's lane manifest is absent from V2 inputs.

These are summary/materialization defects, not evidence that the underlying successful packages should be rebuilt. The repair must register exact existing bytes, remove only the duplicate W5 input, and deterministically regenerate the read-only master. Unrelated main changes must not invalidate lane-local package SHAs.

## Live public scan

The 2026-08-08 run enumerated all 613 URLs in the public sitemap plus `llms.txt` and `llms-full.txt`. It also probed both locales of the public Article, Career Guide, Career Job, Personality asset, Personality, Topic, Scale and backend sitemap-source collection APIs.

The crawler is deliberately conservative:

- GET only; no cookies, credentials, tokens or private result identifiers;
- fixed allowlist for `fermatmind.com` and `api.fermatmind.com`;
- four concurrent requests, 20-second timeout, one bounded 429/5xx retry;
- maximum 5,000 URLs and 2 MB per response;
- no response body persisted; only hashes, counts, public paths and sanitized finding codes;
- private result/report/share/PDF/history coverage comes only from exact package, W9, receipt and contract evidence.

The baseline run identified a small set of actionable categories rather than treating every finding as a translation blocker:

- pages without hreflang declarations, concentrated on surface families that require authority-owner triage;
- four sitemap pages without a canonical, including the currently deferred IQ page and the EQ test entry;
- one English Article listing with a CJK visible-text ratio above the conservative threshold;
- transient transport failures that must be rerun before being classified as persistent defects.

The ledger contains URL hashes and public paths for deterministic triage. It does not change canonical, sitemap, llms, indexability or Search Channel state.

## Evidence precedence

When sources disagree, use this order:

1. exact package plus successful immutable promotion/live-QA receipts;
2. current backend authority and public API readback;
3. current public page readback;
4. lane manifest and V2 input registration;
5. generated V2 master;
6. V1 master, historical approvals and PR/commit titles.

A PR title proves that work was merged; it does not override package bytes, authority state or live readback.

## What should not be repeated

The following V1 workflow shapes are historical audit evidence only:

- CONTROL PRs solely to grant `launch_ready` or accept a status;
- standalone W9 evidence PRs;
- separate BLOCKED, reset, refreeze and acceptance PR chains;
- exact-SHA chat approval phrases and human approval artifacts;
- rebuilding a lane package because an unrelated lane or global master changed.

For a solo-owner project these created coordination cost without increasing content safety. Quality now belongs in deterministic package validation, same-PR independent W9, trusted promotion receipts, exact readback and rollback.

## Reusable scan

Run from `fap-api/backend`:

```bash
php artisan en-parity:scan-program \
  --site-base=https://fermatmind.com \
  --api-base=https://api.fermatmind.com \
  --fap-web-root=/absolute/path/to/fap-web \
  --fap-api-root=/absolute/path/to/fap-api \
  --since=2026-07-30T00:00:00+08:00 \
  --concurrency=4 \
  --timeout=20 \
  --max-urls=5000 \
  --output=docs/seo/generated/english-content-parity-program-ledger.v1.json \
  --json --no-ansi
```

Without `--output`, the command writes no repository artifact. A future scan should compare the source SHAs, sitemap URL-set SHA, task identities, lane package evidence and control drift instead of restarting the W1-W9 planning exercise.

## Repository rule impact

This PR adds a read-only audit capability and corrects documentation of already-merged promotion adapters. It does not create a content surface, change content ownership, authorize promotion, write CMS/database state, change SEO discoverability or trigger deployment.
