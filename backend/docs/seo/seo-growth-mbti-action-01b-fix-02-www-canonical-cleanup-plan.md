# SEO-GROWTH-MBTI-ACTION-01B-FIX-02-WWW-CANONICAL-CLEANUP-PLAN

## Executive summary

`SEO-GROWTH-MBTI-ACTION-01B-FIX-02-PROD-PREFLIGHT` confirmed that production
backend-authoritative dry-runs now emit apex Research URL Truth candidates, but
persisted production URL Truth still contains active stale Research `www` rows.

This plan selects Option C: add a future scoped runtime cleanup command before
any bounded production URL Truth write. A manual DB update, manual delete, blind
apex insert, or Search Channel enqueue is not acceptable.

Existing schema can support the cleanup outcome without a migration:

- `seo_urls.indexability_state` can make stale rows Search Channel-ineligible
  because the planner only treats `indexability_state=indexable` as eligible.
- `seo_url_entities.authority_status` can mark old entity mappings as
  superseded while apex rows receive fresh authoritative mappings.
- `metadata_json` may carry audit detail, but must not be the only source of
  retirement truth.

Existing runtime does not yet provide a safe official cleanup path that validates
old rows, validates replacement apex candidates, retires old rows, writes apex
rows, updates entity mappings, and emits an audit artifact in one bounded flow.
That runtime command is required before production write execution.

## Current conflict

Stale Research `www` URL Truth rows:

- `https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

Replacement Research apex URL Truth rows:

- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

Additional safe write candidate from FIX-02:

- `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`

Already submitted and excluded from this cleanup/write:

- `https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types`
- queue item: `2`
- channel: `indexnow`
- state: approved/submitted

## Recommended cleanup strategy

Selected strategy: Option C, future scoped runtime command.

The command must:

1. Validate exact stale `www` Research rows by canonical URL, locale, page type,
   source authority, indexability state, private-flow flag, and entity slug.
2. Validate exact replacement apex Research candidates from the backend
   authority collector dry-run.
3. Validate exact ZH MBTI apex candidate from the backend authority collector
   dry-run.
4. Refuse execution if the EN MBTI already-submitted URL is included.
5. Refuse execution if Search Channel has active queue items for old Research
   `www` URLs.
6. In one transaction, mark old `www` `seo_urls` rows non-eligible with a
   reserved non-indexable state such as `superseded_canonical`.
7. In the same transaction, mark old `seo_url_entities.authority_status` as
   `superseded_canonical`.
8. Write or upsert the apex Research rows and ZH MBTI apex row from validated
   backend-authoritative candidates only.
9. Recreate or upsert apex `seo_url_entities` mappings for the replacement rows.
10. Emit a local/report audit artifact summarizing old hashes, replacement
    hashes, entity mappings, no-write dry-run evidence, and final write result.
11. Never enqueue Search Channel items and never submit URLs.

## Why this is safest

Option A is incomplete because existing schema can exclude stale rows from Search
Channel, but there is no existing official bounded runtime that performs the
retire-and-replace flow safely.

Option B is unsafe because updating `canonical_url` in place would fight the
existing `canonical_url_hash + locale` identity model and risks corrupting
idempotency/audit history.

Option D is unnecessary for the immediate cleanup because the current string
fields can express non-eligibility and entity demotion. A later schema enhancement
for explicit lifecycle state may still be useful, but it is not required before
the first safe cleanup runtime.

## Entity mapping plan

Old `www` `seo_url_entities` rows must not remain authoritative after cleanup.
They should be updated to `authority_status=superseded_canonical`, preserving the
old `canonical_url_hash`, locale, page type, entity slug, and source metadata for
audit.

Replacement apex rows must receive fresh `seo_url_entities` mappings with:

- page_entity_type: `research_report`
- entity_id_or_slug: `mbti-personality-types-salary-turnover-report`
- entity_source: `research_reports`
- authority_status: `published_approved`

The ZH MBTI apex row must receive:

- page_entity_type: `test_detail`
- entity_id_or_slug: `mbti-personality-test-16-personality-types`
- entity_source: `scales_registry`
- authority_status: `observed`

## Search Channel exclusion plan

Search Channel eligibility currently rejects any `seo_urls` row where
`indexability_state !== indexable`. Therefore stale `www` rows must be moved from
`indexable` to a reserved non-indexable state before or atomically with apex row
creation.

After cleanup, dry-run checks must prove:

- old EN Research `www` URL is blocked
- old ZH Research `www` URL is blocked
- EN Research apex URL is present and eligible
- ZH Research apex URL is present and eligible
- ZH MBTI apex URL is present and eligible
- no Research `www` URL is planned for IndexNow
- EN MBTI queue item `2` remains untouched

## Idempotency and duplicate prevention

The runtime command must be exact-URL bounded and idempotent:

- If old `www` rows are already `superseded_canonical`, do not demote them again.
- If replacement apex rows already exist with the expected authoritative state,
  verify and report them instead of creating duplicates.
- If both old active `www` rows and apex rows exist, fail closed until the old
  rows are retired.
- If any unexpected active Research row exists for the same entity family, fail
  closed.
- Never use sitemap, `llms.txt`, fap-web fallback, crawler logs, search engine
  responses, Digital PR mentions, or local copies as URL Truth.

## Rollback and recovery plan

The future runtime command must support a dry-run preview and produce enough
audit detail for recovery. If a write partially fails, transaction rollback must
restore previous `seo_urls` and `seo_url_entities` state.

If post-write verification fails after commit, recovery must be a separate
human-approved forward fix that either:

- restores old rows to `indexable` only if apex rows are removed or marked
  non-authoritative, or
- completes the apex write and keeps old rows superseded.

Manual DB rollback is rejected.

## Production preflight sequence

Before the future write:

1. Verify deployed SHA contains the cleanup runtime command.
2. Run URL Truth collector dry-run/no-write for `test_detail`.
3. Run URL Truth collector dry-run/no-write for `research_report`.
4. Run cleanup command in dry-run/no-write mode for the exact old/replacement
   URL set.
5. Verify old Research `www` rows are currently active and not already queued.
6. Verify replacement apex candidates exist and are claim-safe.
7. Verify EN MBTI queue item `2` remains approved/submitted and excluded.
8. Verify Search Channel dry-run still has no enqueue and no submission.
9. Require exact human approval before write.

## Future human approval phrase

Future phrase only:

```text
I explicitly approve bounded URL Truth cleanup/write for MBTI FIX-02: retire old Research www rows, write Research apex rows, and write ZH MBTI apex row. Do not enqueue Search Channel items. Do not submit URLs.
```

## No-go conditions

Stop if any of these are true:

- old Research `www` row is missing or does not match expected active state
- replacement apex candidate is missing from backend-authoritative dry-run
- apex row already exists but conflicts with expected authority fields
- Search Channel has active old Research `www` queue items
- EN MBTI already-submitted URL is included in the write set
- write gate would enqueue Search Channel items
- live submission gate is enabled
- runtime command would use sitemap/llms/frontend/crawler/search response as
  URL Truth
- production state cannot be read clearly

## Final decision

`mbti_action_01b_fix_02_www_cleanup_plan_merged_ready_for_cleanup_runtime`

## Next task

`SEO-GROWTH-MBTI-ACTION-01B-FIX-02-WWW-CANONICAL-CLEANUP-RUNTIME`
