# SEARCH-CHANNEL-LIVE-ZH-MBTI-01 Preflight

## Executive Summary

This rerun preflight reviewed production state for queue item 3 before any ZH MBTI IndexNow live submission, after the frontend IndexNow keyLocation fix was deployed.

The queue item, duplicate protection, closed gate state, submit dry-run, IndexNow keyLocation readiness, public runtime, and protected EN queue item 2 are stable. The configured IndexNow `keyLocation` now returns HTTP 200 on the public apex host, with a 32-byte text body whose SHA-256 hash matches the configured key hash. The raw key was not printed.

No live submission was performed. No external search API was called. No queue item was enqueued. No CMS, sitemap, llms, or fap-web mutation was performed.

## Queue Item 3 Verification

- Queue item id: `3`
- URL: `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`
- Channel: `indexnow`
- Locale: `zh-CN`
- Page entity type: `test_detail`
- Source authority: `scale_catalog`
- Source table: `scales_registry`
- Approval state: `pending`
- Execution state: `dry_run_ready`
- Eligibility state: `eligible`
- Claim boundary state: `claim_safe`
- Private flow: `false`

## Duplicate / Event Check

Exactly one active queue item exists for the ZH MBTI URL/channel. A no-write duplicate queue dry-run now blocks with `existing_active_queue_item`, `duplicate_detected=true`, and `planned_queue_count=0`.

Queue item 3 has the planned queue event only. No `live_submission_approved`, `live_submission_response`, accepted submission, retry storm, or bulk submit event was observed.

## Queue Item 2 Verification

Protected EN MBTI queue item 2 remains unchanged:

- URL: `https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types`
- Channel: `indexnow`
- Approval state: `approved`
- Execution state: `submitted`

No duplicate EN queue item was observed.

## Gate State

Production gates remain closed:

- `SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED=false`
- `SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED=false`
- `SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED=false`
- `SEO_INTEL_INDEXNOW_LIVE_API_ENABLED=false` or not enabled

## IndexNow Key Readiness

The IndexNow key is configured, and the configured `keyLocation` is:

`https://fermatmind.com/8d59565935303aad72c5eb0ec5bfa42e.txt`

Public read-only verification returned HTTP 200. The response is `text/plain`, 32 bytes, and its SHA-256 hash matches the configured key hash. The raw key was not printed. IndexNow keyLocation readiness is now satisfied.

## Submit Dry-run

Command:

```bash
php artisan seo-intel:search-channel-submit --queue-item-id=3 --dry-run --json
```

Result:

- Status: `success`
- External calls attempted: `false`
- Search submission attempted: `false`
- Writes attempted: `false`
- Writes committed: `false`
- Submission status: `not_attempted`
- Issues: `[]`

## Public Runtime Check

The public ZH MBTI URL returned HTTP 200 with exact apex canonical:

`https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`

The page emitted `robots=index, follow`, no `noindex`, and no staging canonical. Public runtime was used only as observation, not as URL Truth.

## Staging / Baidu Sidecar

Staging containment remains active:

- `X-Robots-Tag: noindex, nofollow, noarchive`
- HTML robots: `noindex, nofollow, noarchive, nocache`
- Canonical points to production apex

Known Baidu stale staging result remains a sidecar and was not mutated.

## Future Approval Phrase

Runtime dry-run returned the exact approval phrase required by the current live submission command:

`I explicitly approve SEARCH-CHANNEL-LIVE-02 live submission for queue item 3 channel indexnow URL https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types.`

The next task remains the scoped ZH MBTI live submission task. The runtime approval phrase uses the generic `SEARCH-CHANNEL-LIVE-02` label from the deployed command and must be used exactly unless the command is changed before execution.

## Final Decision

`ready_for_human_approved_zh_mbti_live_submission`

## Next Task

`SEARCH-CHANNEL-LIVE-ZH-MBTI-02｜Human-approved live submission for queue item 3`
