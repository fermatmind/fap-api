# SEO-GROWTH-MBTI-ACTION-01B-FIX-02-WWW-CANONICAL-CLEANUP-RUNTIME

## Purpose

This PR adds the official bounded runtime command for the MBTI FIX-02 URL Truth
cleanup path:

```bash
php artisan seo-intel:mbti-url-truth-cleanup --preset=mbti-fix-02-www-research-apex --dry-run --no-write --json
```

The command is fail-closed and defaults to dry-run/no-write. It does not enqueue
Search Channel items, submit URLs, call external search APIs, mutate CMS content,
or use sitemap, `llms.txt`, frontend fallback, crawler logs, search engine
responses, Digital PR mentions, or local copies as authority.

## Runtime command

Command:

```bash
php artisan seo-intel:mbti-url-truth-cleanup
```

Supported options:

- `--preset=mbti-fix-02-www-research-apex`
- `--dry-run`
- `--no-write`
- `--execute`
- `--json`

Default behavior is dry-run/no-write. Future write execution requires the exact
preset and `--execute` without `--dry-run` or `--no-write`.

## Cleanup behavior

Stale `www` Research rows:

- `https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

Replacement apex Research rows:

- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

Additional safe write candidate:

- `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`

Already submitted and excluded:

- `https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types`
- queue item `2`
- channel `indexnow`
- state `approved/submitted`

## Entity mapping behavior

The command demotes stale `www` Research `seo_url_entities` mappings to:

```text
authority_status=superseded_canonical
```

It upserts replacement apex Research mappings from backend CMS Research authority:

```text
entity_source=research_reports
authority_status=published_approved
```

It upserts the ZH MBTI apex mapping from backend scale authority:

```text
entity_source=scales_registry
authority_status=observed
```

## Search Channel safety

Old Research `www` rows are made Search Channel-ineligible by setting:

```text
seo_urls.indexability_state=superseded_canonical
```

The existing Search Channel eligibility evaluator rejects every row whose
`indexability_state` is not `indexable`.

The command never writes queue rows and never submits URLs.

## Transaction and idempotency

In execute mode the command uses one `seo_intel` transaction for:

1. stale `www` row retirement
2. stale entity mapping demotion
3. apex Research URL Truth upsert
4. apex Research entity mapping upsert
5. ZH MBTI URL Truth upsert
6. ZH MBTI entity mapping upsert

The command is exact-URL bounded and idempotent. If stale rows are already
`superseded_canonical`, the command verifies them and does not retire them again.
If replacement apex rows already exist with the expected canonical identity,
upsert preserves a single row per `canonical_url_hash + locale`.

## Future production approval phrase

Future phrase only:

```text
I explicitly approve bounded URL Truth cleanup/write for MBTI FIX-02: retire old Research www rows, write Research apex rows, and write ZH MBTI apex row. Do not enqueue Search Channel items. Do not submit URLs.
```

## Next task

`BACKEND-DEPLOY-READINESS｜Deploy MBTI URL Truth cleanup runtime`
