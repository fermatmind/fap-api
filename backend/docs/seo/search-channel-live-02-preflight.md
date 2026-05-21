# SEARCH-CHANNEL-LIVE-02-PREFLIGHT

Status: ready for exact human approval after IndexNow live configuration
Date: 2026-05-21

## Scope

This rerun returns to the small human-approved Search Channel live submission canary after `SEARCH-CHANNEL-LIVE-02-EXECUTOR` added the guarded single-item executor, production was deployed to a release that contains that executor, and the IndexNow live configuration was added to production.

No live search API call, URL submission, scheduler activation, DNS change, or additional queue write was performed in this preflight task. The production env/config cache was updated only to add the IndexNow key/keyLocation and one-shot live gates needed for the next approved canary attempt.

## Executor Evidence

- `SEARCH-CHANNEL-LIVE-02-EXECUTOR` merged in PR #1529.
- Executor merge commit: `af8a050bfed88631cfc687775402548848ba87a1`.
- Current production release: `20260521220637`.
- Current production revision: `35d1f33b038df4eac330475d072f25fbbfd66364`.
- The current production revision contains the executor merge commit.
- The production runtime exposes `seo-intel:search-channel-submit`.

## Canary Candidate

The production dry-run confirmed the intended canary candidate:

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

## Production Dry-Run Evidence

The guarded live submission executor was run in dry-run mode only:

```bash
cd /var/www/fap-api/current/backend
php artisan seo-intel:search-channel-submit --queue-item-id=1 --dry-run --json
```

Result:

- `status=success`
- `queue_item_id=1`
- `channel=indexnow`
- `canonical_url=https://fermatmind.com/en`
- `execution_state=dry_run_ready`
- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `writes_attempted=false`
- `writes_committed=false`
- `scheduler_enabled=false`
- `bulk_submission=false`

The queue planner was also run in no-write dry-run mode:

```bash
cd /var/www/fap-api/current/backend
php artisan seo-intel:search-channel-queue --dry-run --no-write --json --channel=indexnow --limit=1
```

Result:

- `status=success`
- `candidate_count=1`
- `eligible_count=1`
- `blocked_count=0`
- `planned_queue_count=1`
- `channel_breakdown.indexnow=1`
- `writes_attempted=false`
- `writes_committed=false`
- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `write_gate_enabled=false`
- `scheduler_enabled=false`

## IndexNow Live Configuration Evidence

Production now has the live submission gates and IndexNow key metadata in Laravel config cache:

- `SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED=true`
- `SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED=true`
- `SEO_INTEL_INDEXNOW_LIVE_API_ENABLED=true`
- `SEO_INTEL_INDEXNOW_KEY` present
- `SEO_INTEL_INDEXNOW_KEY_LOCATION` present
- Laravel config cache rebuilt
- public keyLocation host `fermatmind.com` returned the expected 32-byte key file

The key value and full keyLocation are intentionally not written to this artifact.

The guarded executor was also checked without the approval phrase:

```bash
cd /var/www/fap-api/current/backend
php artisan seo-intel:search-channel-submit --queue-item-id=1 --json
```

Result:

- `status=blocked`
- only blocking issue: `approval_phrase_mismatch`
- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `writes_attempted=false`
- `writes_committed=false`

This confirms the live gates and key metadata are now present while the exact human approval phrase still gates any external call or write.

## Approval Phrase

`SEARCH-CHANNEL-LIVE-02` may proceed only after the user sends this exact phrase:

```text
I explicitly approve SEARCH-CHANNEL-LIVE-02 live submission for queue item 1 channel indexnow URL https://fermatmind.com/en.
```

## Decision

`SEARCH-CHANNEL-LIVE-02-PREFLIGHT` is no longer blocked.

The next allowed task is `SEARCH-CHANNEL-LIVE-02`, and it may execute only the approved single queue item, only on channel `indexnow`, only for `https://fermatmind.com/en`, and only after the exact approval phrase above is present. Scheduler activation, bulk submission, persistent expansion, additional URL submission, DNS changes, and unrelated production env edits remain out of scope.

After the canary attempt, disable the one-shot IndexNow live gates and rebuild Laravel config cache.
