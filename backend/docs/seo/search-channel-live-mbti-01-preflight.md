# SEARCH-CHANNEL-LIVE-MBTI-01

## Purpose

Perform a read-only live submission preflight for EN MBTI Search Channel queue item `2`.

This task did not open any live gate, did not call IndexNow, did not call any external search API, did not enqueue any URL, and did not mutate CMS, URL Truth, or production env.

## Target queue item

- `queue_item_id=2`
- `batch_id=2`
- `canonical_url=https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types`
- `channel=indexnow`
- `approval_state=pending`
- `execution_state=dry_run_ready`
- `source_authority=scale_catalog`
- `page_entity_type=test_detail`

## Queue item verification

Production read-only checks confirmed:

- queue item `2` exists
- `batch_id=2`
- URL and channel match the intended EN MBTI test candidate
- `indexability_state=indexable`
- `claim_boundary_state=claim_safe`
- `private_flow=false`
- batch `2` exists with `status=dry_run` and `item_count=1`

## Duplicate verification

Exact idempotency-key review showed:

- `duplicate_count=1`
- the only matching row is queue item `2`

This means there is no duplicate active queue row beyond the expected planned item.

## Gate verification

Production config remained closed:

- `SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED=false`
- `SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED=false`
- `SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED=false`
- `SEO_INTEL_INDEXNOW_LIVE_API_ENABLED=false`

## IndexNow key readiness

Production config contains IndexNow live submission metadata:

- IndexNow key present
- key length `32`
- keyLocation present
- keyLocation host `fermatmind.com`

Public keyLocation verification succeeded:

- URL: `https://fermatmind.com/8d59565935303aad72c5eb0ec5bfa42e.txt`
- HTTP `200`
- content type `text/plain; charset=UTF-8`
- body length `32`
- public file SHA-256 matched the configured IndexNow key SHA-256

The raw key value is intentionally not recorded here.

## Submit dry-run verification

Read-only submit dry-run command:

```bash
cd /var/www/fap-api/current/backend
php artisan seo-intel:search-channel-submit --queue-item-id=2 --dry-run --json
```

Observed result:

- `status=success`
- `queue_item_id=2`
- `channel=indexnow`
- `canonical_url=https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types`
- `execution_state=dry_run_ready`
- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `writes_attempted=false`
- `writes_committed=false`

## Exact future approval phrase

The current executor requires this exact phrase:

```text
I explicitly approve SEARCH-CHANNEL-LIVE-02 live submission for queue item 2 channel indexnow URL https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types.
```

This phrase is taken from the current production executor contract and must match exactly for a future live submission task.

## Required gates to open

Only for the later human-approved live submission task:

- `SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED=true`
- `SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED=true`
- `SEO_INTEL_INDEXNOW_LIVE_API_ENABLED=true`

Queue write must remain closed:

- `SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED=false`

## Required gates to close after submission

Immediately after the one-shot live submission:

- `SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED=false`
- `SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED=false`
- `SEO_INTEL_INDEXNOW_LIVE_API_ENABLED=false`

Queue write stays closed throughout:

- `SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED=false`

## No-go conditions

Do not proceed with live submission if any of the following becomes true:

- queue item `2` is missing
- queue item `2` URL, channel, source authority, or page entity type no longer matches the approved candidate
- `approval_state` is not `pending`
- `execution_state` is not `dry_run_ready`
- duplicate count is greater than `1`
- any live gate is already unexpectedly open before the approved operation
- IndexNow key or keyLocation is missing
- public keyLocation no longer matches the configured key
- submit dry-run fails or attempts any external call
- queue item becomes non-indexable, private, claim-unsafe, or host-disallowed

## Decision

`ready_for_human_approved_live_submission`
