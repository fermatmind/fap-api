# Research URL Truth Observation

## Purpose

`RESEARCH-PUBLISH-02-FIX` adds backend/CMS Research report candidates to the disabled-by-default `url_truth_inventory` collector so published Research URLs can be observed by SEO Dash after a controlled publish.

This PR does not publish Research content, submit URLs, run production collector writes, enable scheduler, read crawler logs, deploy, or change sitemap/llms behavior.

## Candidate Source

Research candidates are sourced only from backend/CMS `research_reports` records.

Eligible records must be:

- `status = published`
- `review_state = approved`
- `is_public = true`
- `is_indexable = true`
- published now or earlier
- canonical Research route family: `/en/research/{slug}` or `/zh/research/{slug}`
- methodology present
- sample disclaimer present
- claim boundary present
- references present

The emitted URL Truth record uses:

- `page_entity_type = research_report`
- `source_authority = backend_cms`
- `indexability_state = indexable`
- `entity_source = research_reports`
- target tables: `seo_urls`, `seo_url_entities`

## Safety Gates

The source skips:

- drafts
- unpublished records
- private records
- noindex records
- unapproved or claim-unsafe records
- missing methodology, sample disclaimer, claim boundary, or references
- missing or stale slugs
- the stale `turnover-rate-report` slug
- `/articles` paths
- `/reports` paths
- frontend fallback
- static sitemap fallback
- static llms fallback
- Node2 local sources

The collector remains dry-run by default and bounded writes still require `--limit` or `--canary` with write enablement explicitly set by the operator.

## Non-goals

- No Research content publish.
- No CMS record creation or mutation.
- No production collector write.
- No scheduler activation.
- No GSC, Baidu, IndexNow, 360, Sogou, or Shenma live call.
- No crawler log read.
- No sitemap or llms behavior change.
- No Metabase change.
- No deployment.

## Next Task

Next task: `RESEARCH-PUBLISH-02-RERUN`.
