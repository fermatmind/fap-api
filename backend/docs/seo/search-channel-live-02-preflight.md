# SEARCH-CHANNEL-LIVE-02-PREFLIGHT

Status: blocked
Date: 2026-05-21

## Scope

This preflight checks whether the already-enqueued Search Channel Queue canary item can safely advance to a human-approved small live URL submission.

No live search API call, URL submission, scheduler activation, production env edit, deploy, or additional queue write was performed.

## Evidence

- `SEARCH-CHANNEL-QUEUE-02-CANARY-ENQUEUE` completed with one IndexNow queue item.
- The latest queue item is `id=1`, `channel=indexnow`, `canonical_url=https://fermatmind.com/en`.
- The item is `approval_state=pending`, `execution_state=dry_run_ready`, `eligibility_state=eligible`, `indexability_state=indexable`, and `claim_boundary_state=claim_safe`.
- `source_authority=backend_public_surface`, `source_table=backend_authority_canary_contract`.
- The queue write gate is disabled.
- Public `https://fermatmind.com/en` returns HTTP 200.
- A fresh no-write dry-run for `--channel=indexnow --limit=1` returned `candidate_count=1`, `eligible_count=1`, `planned_queue_count=1`, `writes_committed=false`, `external_calls_attempted=false`, and `search_submission_attempted=false`.

## Blocker

The current backend has no live submission executor for Search Channel Queue items.

`seo-intel:search-channel-queue` can plan and enqueue dry-run-ready items, but its command contract and implementation explicitly keep:

- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `safety_flags.no_live_submission=true`
- `safety_flags.no_submit_mode=true`

The existing IndexNow/Baidu collector foundations also block real URL submission. Because there is no live executor to approve, this preflight must not output a live submission approval phrase.

## Decision

`SEARCH-CHANNEL-LIVE-02` is blocked until a separate scoped runtime task adds a guarded live submission executor with:

- exact approved URL and channel matching;
- one-shot human approval phrase enforcement;
- queue item approval transition;
- idempotency protection;
- external response capture;
- audit events before and after submission;
- hard scheduler and bulk-submission disablement.

The current approved action remains: no live submission.
