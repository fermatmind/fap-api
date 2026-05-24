# SEO-GROWTH-MBTI-ACTION-01B-FIX-02-POST-WRITE-SEARCH-CHANNEL-REVIEW

## Executive Summary

The post-write production review confirms that the MBTI FIX-02 bounded URL Truth cleanup/write left production in the expected state.

The old Research `www` URL Truth rows are retained only as superseded canonicals and are not Search Channel eligible. The EN/ZH Research apex rows and ZH MBTI test apex row exist, are indexable, and are visible to the Search Channel dry-run as clean apex candidates.

Queue item 2 remains unchanged for the already-submitted EN MBTI test URL. No Search Channel enqueue, live submission, external API call, CMS mutation, sitemap mutation, llms mutation, fap-web mutation, collector write, migration, scheduler activation, raw crawler log read, Digital PR action, or production deploy was performed during this review.

## Persisted URL Truth State

Old retired rows:

- `https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

Both stale rows are present only as `indexability_state=superseded_canonical`. They are not Search Channel eligible and are blocked by the Search Channel dry-run with `reason_codes=["noindex"]`.

New apex Research rows:

- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

Both apex Research rows are present, `indexability_state=indexable`, `source_authority=backend_cms`, `private_flow=false`, and Search Channel dry-run eligible.

ZH MBTI test row:

- `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`

The ZH MBTI test row is present, `indexability_state=indexable`, `source_authority=scale_catalog`, `private_flow=false`, and Search Channel dry-run eligible.

## Entity Mapping State

The old Research `www` rows have `seo_url_entities.authority_status=superseded_canonical`.

The EN/ZH Research apex rows have `seo_url_entities.authority_status=published_approved` with `entity_source=research_reports`.

The ZH MBTI test apex row has a `seo_url_entities` mapping from `entity_source=scales_registry` with the observed backend scale authority state recorded by the cleanup command.

## Queue Item 2 Verification

Queue item 2 remains unchanged:

- `id=2`
- `canonical_url=https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types`
- `channel=indexnow`
- `approval_state=approved`
- `execution_state=submitted`

No duplicate EN MBTI queue item was detected. The persisted Search Channel queue count remained stable during the read-only review, and no retry storm or duplicate live event was observed in the bounded queue state checked for this task.

## Cleanup Command Idempotency Dry-run

The production cleanup command was rerun in dry-run/no-write mode:

```bash
php artisan seo-intel:mbti-url-truth-cleanup \
  --preset=mbti-fix-02-www-research-apex \
  --dry-run \
  --no-write \
  --json
```

Result:

- `status=dry_run_ready`
- `dry_run=true`
- `no_write=true`
- `writes_committed=false`
- `old_www_rows_found=2`
- `apex_research_candidates_found=true`
- `zh_mbti_candidate_found=true`
- `queue_item_2_untouched=true`
- `duplicate_cluster_prevented=true`
- `search_channel_enqueue_attempted=false`
- `live_submission_attempted=false`
- `external_api_call_attempted=false`
- `issues=[]`

## Search Channel Dry-run

The broad Search Channel dry-run/no-write completed without writes, enqueue, live submission, or external API calls.

Bounded canonical URL dry-runs confirmed:

- ZH MBTI apex: eligible
- EN Research apex: eligible
- ZH Research apex: eligible
- old EN Research `www`: blocked / not eligible
- old ZH Research `www`: blocked / not eligible

The old `www` Research URLs are blocked by `indexability_state=superseded_canonical` and `reason_codes=["noindex"]`.

## Public Runtime Checks

Safe public runtime checks returned HTTP 200, exact apex canonical, `index, follow`, and no `noindex` for:

- `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`
- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

No stale `turnover-rate-report` URL was observed. Dataset JSON-LD presence was recorded only as public runtime observation, not URL Truth.

## /ops/seo Visibility

Optional `/ops/seo` UI visibility was not checked. This does not block the review because production URL Truth, entity mappings, cleanup command idempotency, queue item 2 state, and Search Channel dry-run state were verified through read-only backend paths.

## Recommendation / Next Task

ZH MBTI can proceed to a human-approved Search Channel enqueue preflight.

Research should remain deferred to a separate stricter claim-sensitive enqueue preflight because the Research pages are claim-sensitive even though the Search Channel dry-run currently marks their URL Truth rows as eligible.

Recommended next task:

`SEO-GROWTH-MBTI-ACTION-ZH-MBTI-QUEUE-PREFLIGHT`

Deferred Research task:

`SEO-GROWTH-MBTI-ACTION-RESEARCH-QUEUE-PREFLIGHT`

## What Was Not Done

- No Search Channel enqueue.
- No live URL submission.
- No external search API call.
- No collector write.
- No CMS mutation.
- No article publish.
- No internal link creation.
- No sitemap or llms mutation.
- No fap-web mutation.
- No production deploy.
- No migration.
- No scheduler activation.
- No raw Nginx access log read.
- No Digital PR outreach.

## Final Decision

`mbti_action_01b_fix_02_post_write_review_completed_ready_for_zh_mbti_queue_preflight`

## Next Task

`SEO-GROWTH-MBTI-ACTION-ZH-MBTI-QUEUE-PREFLIGHT`
