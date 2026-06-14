# SEO Intel Two-stage URL Truth Handoff

## Decision

`SEO-INTEL-TWO-STAGE-URL-TRUTH-HANDOFF-PR-00` defines a controlled handoff path for backend-authoritative URL Truth observation while `fap-api` production and the verified `seo_intel` RDS remain split across clouds.

The current MVP policy is:

- Tencent `fap-api-prod` may run `url_truth_inventory` in dry-run/no-write mode and export a handoff JSON artifact.
- Aliyun runner validates that artifact before any write.
- Aliyun runner may perform a bounded write only after validation passes and only with explicit write flags.
- The handoff writes only to `seo_urls` and `seo_url_entities`.
- `seo_issue_queue`, Search Channel submission tables, crawler log tables, attribution tables, business tables, and Metabase configuration are not touched by this handoff.

This avoids cross-cloud private networking work for this stage and avoids making Tencent `fap-api-prod` a direct writer to Aliyun RDS.

## Source-side Export

The source side uses:

```bash
php artisan seo-intel:url-truth-handoff \
  --export=/secure/path/research-url-truth-handoff.json \
  --dry-run \
  --json \
  --limit=20 \
  --page-type=research_report
```

For published CMS articles, use the same no-write handoff path with `page-type=article`:

```bash
php artisan seo-intel:url-truth-handoff \
  --export=/secure/path/article-url-truth-handoff.json \
  --dry-run \
  --json \
  --limit=20 \
  --page-type=article
```

The export is candidate-only. It does not write to `seo_intel`, does not call external search APIs, does not read crawler logs, and does not submit URLs.

The artifact path must be an absolute `.json` path in an existing non-symlink directory. Export refuses to overwrite an existing file or symlink.

## Runner-side Validation

The Aliyun runner validates the artifact with:

```bash
php artisan seo-intel:url-truth-handoff \
  --import=/secure/path/research-url-truth-handoff.json \
  --dry-run \
  --json \
  --page-type=research_report \
  --limit=20
```

For article artifacts:

```bash
php artisan seo-intel:url-truth-handoff \
  --import=/secure/path/article-url-truth-handoff.json \
  --dry-run \
  --json \
  --page-type=article \
  --limit=20
```

Validation is fail-closed. Research artifacts are accepted only when every candidate is:

- `page_entity_type = research_report`
- `source_authority = backend_cms`
- `entity_source = research_reports`
- `authority_status = published_approved`
- `indexability_state = indexable`
- non-private
- claim-safe
- routed under `/en/research/{slug}` or `/zh/research/{slug}`

Validation rejects `/articles`, `/reports`, stale `turnover-rate-report` slugs, draft/private/noindex/claim-unsafe candidates, and sensitive metadata keys.

Article artifacts are accepted only when every candidate is:

- `page_entity_type = article`
- `source_authority = backend_cms`
- `entity_source = articles`
- `authority_status = published_approved`
- `indexability_state = indexable`
- non-private
- claim-safe
- routed under `/en/articles/{slug}` or `/zh/articles/{slug}`
- backed by a numeric article entity id from backend CMS authority

Article validation rejects `/research`, `/reports`, stale `turnover-rate-report` slugs, draft/private/noindex/claim-unsafe candidates, and sensitive metadata keys.

Import validates artifact path safety before reading. It accepts only regular `.json` files under an existing non-symlink directory and rejects stream wrappers, relative paths, missing files, symlinks, and oversized artifacts.

## Runner-side Bounded Write

The runner may write only after validation and SHA256 confirmation:

```bash
SEO_INTEL_ENABLED=true \
SEO_INTEL_WRITE_ENABLED=true \
php artisan seo-intel:url-truth-handoff \
  --import=/secure/path/research-url-truth-handoff.json \
  --write \
  --confirm-artifact-sha256=<artifact_sha256> \
  --json \
  --page-type=research_report \
  --limit=20
```

Write mode is still bounded by `--limit`, requires a matching artifact SHA256, and targets only:

- `seo_urls`
- `seo_url_entities`

## Hard Boundaries

This handoff does not:

- submit URLs to Google, Baidu, IndexNow, 360, Sogou, or Shenma
- run scheduler
- read production crawler logs
- call external search APIs
- connect business DB
- connect Tencent RDS
- connect Node2 local DB
- expose Metabase publicly
- create Metabase dashboards or datasources
- write `seo_issue_queue`
- change sitemap or `llms.txt`

## Next Task

After this PR is deployed to the runner/source environments, the next operation is:

`RESEARCH-PUBLISH-02-RERUN`
