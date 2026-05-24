# SEO-GROWTH-MBTI-ACTION-01B-FIX-02-PROD-PREFLIGHT

## Executive summary

Production read-only verification confirms that the deployed backend release includes
`SEO-GROWTH-MBTI-ACTION-01B-FIX-02` and that the backend-authoritative URL Truth
collector dry-run now emits the expected apex candidates for:

- `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`
- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

No production URL Truth write was performed. No Search Channel enqueue or live
submission was performed. No external search API was called.

The preflight is blocked for the full three-URL bounded write because production
`seo_urls` still contains active `www.fermatmind.com` Research rows while the new
apex Research rows are absent. The current URL Truth writer upserts by
`canonical_url_hash + locale`, so a later apex write would add new Research rows
without replacing or retiring the old `www` rows. That creates duplicate canonical
cluster risk unless a cleanup or retirement path is defined first.

## Deployment verification

- Deployed backend SHA observed by read-only SSH: `0338122739bbe4fd9645943d4f08372a3881f560`
- FIX-02 merge commit: `dcc557ce959a2d282d9199ee37da0e70f1102ef0`
- Verification result: the deployed SHA contains the FIX-02 merge commit.
- Latest merged backend PRs observed locally included PR #1646 and PR #1645 after FIX-02.

## URL Truth dry-run results

### `test_detail`

Command:

```bash
php artisan seo-intel:collect --collector=url_truth_inventory --dry-run --no-write --json --page-type=test_detail --limit=100
```

Result:

- status: `success`
- dry_run: `true`
- writes_attempted: `false`
- writes_committed: `false`
- external_calls_attempted: `false`
- items_seen: `22`
- planned_url_count: `16`
- expected ZH MBTI candidate: present
- `www.fermatmind.com` candidates: none observed in backend-authoritative source
- stale `turnover-rate-report` candidates: none observed

Expected ZH MBTI candidate:

```text
https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types
```

Observed properties:

- locale: `zh-CN`
- page_entity_type: `test_detail`
- source_authority: `scale_catalog`
- indexability_state: `indexable`
- is_private_flow: `false`

### `research_report`

Command:

```bash
php artisan seo-intel:collect --collector=url_truth_inventory --dry-run --no-write --json --page-type=research_report --limit=100
```

Result:

- status: `success`
- dry_run: `true`
- writes_attempted: `false`
- writes_committed: `false`
- external_calls_attempted: `false`
- items_seen: `22`
- planned_url_count: `2`
- expected EN Research apex candidate: present
- expected ZH Research apex candidate: present
- `www.fermatmind.com` candidates: none observed in backend-authoritative source
- stale `turnover-rate-report` candidates: none observed

Expected Research candidates:

```text
https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report
https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report
```

Observed properties:

- page_entity_type: `research_report`
- source_authority: `backend_cms`
- indexability_state: `indexable`
- is_private_flow: `false`

## Persisted URL Truth state

Read-only production `seo_urls` / `seo_url_entities` checks showed:

| Target | `seo_urls` | `seo_url_entities` | Notes |
| --- | --- | --- | --- |
| ZH MBTI apex | absent | 0 | Candidate exists in dry-run; persisted row not yet written. |
| EN Research apex | absent | 0 | Candidate exists in dry-run; persisted row not yet written. |
| ZH Research apex | absent | 0 | Candidate exists in dry-run; persisted row not yet written. |
| EN Research www | present | 1 | Active `research_report`, `backend_cms`, `indexable`. |
| ZH Research www | present | 1 | Active `research_report`, `backend_cms`, `indexable`. |

The old `www` Research rows were last seen on `2026-05-20 12:30:28` and remain
active indexable URL Truth rows.

## Conflict risk

Conflict risk is blocking for the full three-URL bounded write.

- Writing the ZH MBTI apex row appears safe in isolation because no persisted ZH
  MBTI apex row currently exists and no old `www` MBTI row was identified in this
  target check.
- Writing Research apex rows without a cleanup step would create parallel active
  URL Truth rows for the same Research entity family.
- The URL Truth writer uses `canonical_url_hash + locale` for `seo_urls` upsert.
  Since apex and `www` URLs hash differently, apex writes do not replace the old
  `www` rows.
- A cleanup, retire, or superseded-canonical plan is needed before writing the
  Research apex rows.

## Search Channel current state

Command:

```bash
php artisan seo-intel:search-channel-queue --dry-run --no-write --json --channel=indexnow --limit=50
```

Result:

- status: `success`
- dry_run: `true`
- no_write: `true`
- writes_attempted: `false`
- writes_committed: `false`
- enqueue_attempted: `false`
- enqueue_committed: `false`
- external_calls_attempted: `false`
- search_submission_attempted: `false`
- candidate_count: `9`
- eligible_count: `9`
- planned_queue_count: `7`
- duplicate_detected: `true`
- write_gate_enabled: `false`

Canonical URL filtered dry-runs showed:

| URL | Current Search Channel state |
| --- | --- |
| ZH MBTI apex | `canonical_url_not_found`; not currently eligible until URL Truth row exists. |
| EN Research apex | `canonical_url_not_found`; not currently eligible until URL Truth row exists. |
| ZH Research apex | `canonical_url_not_found`; not currently eligible until URL Truth row exists. |
| EN Research www | eligible as an old persisted URL Truth row. |
| ZH Research www | eligible as an old persisted URL Truth row. |

No enqueue was performed.

## Public runtime checks

Public runtime checks were used only as runtime confirmation, not as URL Truth.

| URL | HTTP | Canonical | Robots | Notes |
| --- | --- | --- | --- | --- |
| `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types` | 200 | exact apex URL | `index, follow` | no `noindex` observed |
| `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report` | 200 | exact apex URL | `index, follow` | no `noindex` observed |
| `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report` | 200 | exact apex URL | `index, follow` | no `noindex` observed |
| stale EN `turnover-rate-report` URL | 404 | none | not indexable response | no live indexable stale page observed |
| stale ZH `turnover-rate-report` URL | 404 | none | not indexable response | no live indexable stale page observed |

## Bounded write recommendation

Do not run the requested full bounded production URL Truth write for all three
targets yet.

Recommended next step:

1. Define a scoped Research `www` URL Truth cleanup or retirement plan.
2. Keep Search Channel write gates closed.
3. After the cleanup path is approved, perform a human-approved bounded write for
   the ZH MBTI apex row and Research apex rows.
4. Re-run Search Channel dry-run after URL Truth write evidence exists.

## Safety boundary

- no_write_performed: `true`
- no_enqueue: `true`
- no_submission: `true`
- no_external_api_call: `true`
- no_cms_mutation: `true`
- sitemap_llms_authority_used: `false`

## Final decision

`mbti_action_01b_fix_02_prod_preflight_blocked_www_conflict_requires_cleanup_plan`

## Next task

`SEO-GROWTH-MBTI-ACTION-01B-FIX-02-WWW-CANONICAL-CLEANUP-PLAN`
