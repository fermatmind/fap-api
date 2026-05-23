# SEO-GROWTH-MBTI-ACTION-01B-R2-GATE-PREFLIGHT

## Purpose

Prepare the one-shot Search Channel queue write gate plan for the EN MBTI test URL. This task was read-only: it did not edit production env, open any gate, enqueue a queue item, submit to IndexNow, or write SEO Intel data.

## Target

- URL: `https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types`
- channel: `indexnow`

## Production read-only checks

Production release:

- release: `search-channel-single-url-20260523-d6a599a8`
- deployed SHA: `d6a599a8dad0e0cc8fb6aba0c2ac2a216f7ebddc`

Command help confirmed support for:

- `--canonical-url`
- `--enqueue`

Dry-run command:

```bash
cd /var/www/fap-api/current/backend
php artisan seo-intel:search-channel-queue \
  --dry-run \
  --no-write \
  --json \
  --channel=indexnow \
  --canonical-url=https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types
```

Dry-run result:

- `status=success`
- `candidate_count=1`
- `eligible_count=1`
- `planned_queue_count=1`
- `duplicate_detected=false`
- `selected_candidate.canonical_url` matched the target URL
- `selected_candidate.page_entity_type=test_detail`
- `selected_candidate.source_authority=scale_catalog`
- `selected_candidate.claim_boundary_state=claim_safe`
- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `live_submission_attempted=false`

## Gate state

Current masked env/config state:

- `SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED`: missing / config false
- `SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED`: present masked / config false
- `SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED`: present masked / config false
- `SEO_INTEL_INDEXNOW_LIVE_API_ENABLED`: present masked / config false

Live gates are closed.

## Duplicate check

Read-only duplicate check:

```bash
cd /var/www/fap-api/current/backend
php artisan tinker --execute='echo json_encode([
    "active_duplicate_count" => DB::connection((string) config("seo_intel.connection", "seo_intel"))
        ->table("seo_search_channel_queue_items")
        ->where("canonical_url", "https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types")
        ->where("channel", "indexnow")
        ->count(),
], JSON_UNESCAPED_SLASHES);'
```

Observed result:

- `active_duplicate_count=0`

## One-shot gate plan

The later approved operation should temporarily open only:

- `SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED=true`

It must keep closed:

- `SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED=false`
- `SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED=false`
- `SEO_INTEL_INDEXNOW_LIVE_API_ENABLED=false`

Future enqueue command:

```bash
cd /var/www/fap-api/current/backend
php artisan seo-intel:search-channel-queue \
  --enqueue \
  --json \
  --channel=indexnow \
  --canonical-url=https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types
```

Future queue item verification command:

```bash
cd /var/www/fap-api/current/backend
php artisan tinker --execute='echo json_encode([
    "queue_item_count" => DB::connection((string) config("seo_intel.connection", "seo_intel"))
        ->table("seo_search_channel_queue_items")
        ->where("canonical_url", "https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types")
        ->where("channel", "indexnow")
        ->count(),
], JSON_UNESCAPED_SLASHES);'
```

Future duplicate verification command:

```bash
cd /var/www/fap-api/current/backend
php artisan seo-intel:search-channel-queue \
  --dry-run \
  --no-write \
  --json \
  --channel=indexnow \
  --canonical-url=https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types
```

After enqueue, this should report `duplicate_detected=true` or equivalent existing-item evidence. It must not create a second queue item.

Future no-live-submission verification command:

```bash
cd /var/www/fap-api/current/backend
php artisan tinker --execute='echo json_encode([
    "live_submission_gate" => (bool) config("seo_intel.search_channel_queue.live_submission.enabled", false),
    "external_api_gate" => (bool) config("seo_intel.search_channel_queue.live_submission.external_api_calls_enabled", false),
    "indexnow_live_api_gate" => (bool) config("seo_intel.indexnow_live_api_enabled", false),
], JSON_UNESCAPED_SLASHES);'
```

Gate close step for the later approved task:

1. Set `SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED=false` or remove the temporary env value through the approved production env update mechanism.
2. Rebuild Laravel config cache for the current backend release.
3. Re-run config verification and confirm `queue_write_gate=false`.

## Future approval phrase

```text
I explicitly approve opening Search Channel queue write gate for one enqueue of https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types via indexnow, then immediately closing the queue write gate. Do not perform live submission.
```

## Decision

`mbti_action_01b_r2_gate_preflight_ready_for_one_shot_enqueue_approval`

## Next task

`SEO-GROWTH-MBTI-ACTION-01B-R3｜One-shot queue gate open, enqueue, gate close`
