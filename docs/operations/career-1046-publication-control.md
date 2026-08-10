# Career 1046 protected publication control

This runbook governs the **new controlled publication** path for the immutable
30 + 1016 Career manifest. It is not a disaster-recovery claim and it does not
infer publication eligibility from files, search-entry review, sitemap output,
or frontend routes.

## Immutable inputs

Every run is bound to:

- latest `main` control-plane SHA;
- exact active production revision and release directory;
- `detail-ready-1046-rollout-manifest.v1.json` bytes;
- exact zero-write preflight run, receipt SHA-256, and artifact digest;
- one batch id, one ordered slug list, its set SHA-256, and an identical
  rollback-group SHA-256;
- the current materialized runtime projection SHA-256;
- one failure policy (`rollback` or `quarantine`);
- for apply, one successful verify run, receipt SHA-256, artifact digest, and
  exact receipt-generated operator phrase.

The slug-set hash is SHA-256 over the sorted unique slugs, one slug per line,
including the final newline.

## Ordered modes

### 1. `verify`

`verify` is read-only. It requires a successful, same-control-plane
`Career 1046 Rollout Zero Write Preflight` receipt. The control then:

1. verifies the actual latest materialized projection selected by the runtime;
2. independently builds the live database projection;
3. requires the loader, materialized artifact, and live projection to expose
   the same published canonical slug set;
4. requires the live 1046 target to contain 2092 locale rows;
5. requires all 30 baseline slugs to remain published;
6. requires published delta slugs to be a strict prefix of the immutable
   1016-slug delta;
7. requires the submitted batch to be the next contiguous prefix;
8. runs `career:execute-canonical-rollout-batch` with `--dry-run` and
   `--no-audit-write`;
9. emits `PASS_CANARY_READY` or `PASS_BATCH_READY` plus the exact apply phrase.

The canary is exactly `accountants-and-auditors`. A normal batch is exactly
10, 25, 50, or 100 slugs. A normal batch cannot start before the canary is
published and read back.

### 2. `apply`

`apply` is a production database, publication, cache, and private-artifact
write. A workflow dispatch does not authorize itself: the operator must supply
the exact phrase emitted by the matching verify receipt.

The workflow rechecks all verify inputs and the unchanged before-projection
bytes, then executes one batch without automatic retry. On success it performs:

1. canonical batch promotion;
2. prepared bilingual detail activation;
3. cohort-preserving directory/job-index activation;
4. full release-ledger materialization;
5. runtime projection materialization from that ledger;
6. actual loader readback against the new projection.

The receipt records before/after projection hashes, ledger hash, batch and
rollback identities, promoted row counts, database/publication counts, and
whether rollback or quarantine executed. It does not expose private paths,
database identities, payload bodies, hostnames, or credentials.

This workflow does **not** deploy code, run migrations, warm sitemap/llms,
submit a sitemap, request indexing, or call a Search Channel API.

### 3. `incident_assessment`

If SSH transport is ambiguous, the apply receipt is
`HOLD_AMBIGUOUS_DISCONNECT` or `HOLD_AMBIGUOUS_APPLY` and
`automatic_retry_allowed=false`. Do not redispatch apply.

Run `incident_assessment` with the exact failed-run receipt and before
projection. It performs read-only authority and loader inspection and returns:

- `PASS_APPLY_OBSERVED`: the entire batch is published and the artifact moved;
- `PASS_NO_APPLY_OBSERVED`: none of the batch is published and the artifact is
  unchanged;
- `HOLD_PARTIAL_OR_ARTIFACT_DRIFT`: partial or conflicting state; no automatic
  retry or inferred rollback is allowed.

Any new apply after `PASS_NO_APPLY_OBSERVED` still requires a fresh verify
receipt and a new explicit operator phrase. Partial state requires a separately
authorized, receipt-bound remediation action.

## Batch progression and closeout

The control never hard-codes total sitemap size and never warms discoverability.
After the final batch reaches 1046 published slugs / 2092 locale rows, execute
the separately controlled Task 3 sequence:

1. detail coverage 2092/2092;
2. directory-only rebuild;
3. EN/ZH directory readback at 1046 each;
4. sitemap-source warm;
5. sitemap, llms, canonical, and hreflang readback.

Tencent retirement, sitemap submission, URL indexing requests, GA4/GSC
conclusions, and the final 25-question closure remain outside this workflow.
