# SEO Agent Opportunity Aggregator

`SEO-AGENT-OPPORTUNITY-AGGREGATOR-01` adds the first unified opportunity pool for the FermatMind SEO Agent L4 loop.

## Command

```bash
php artisan seo-agent:opportunity-aggregate \
  --inputs=/path/to/scanner-a.json,/path/to/scanner-b.json \
  --limit=100 \
  --artifact-dir=/path/to/artifacts \
  --json
```

## Contract

The command accepts sanitized readonly opportunity artifacts from existing scanner sources such as `cms_tdk_gap`, `runtime_seo_qa`, and `cms_faq_gap`. It emits `seo-agent-opportunity-aggregate.v1` with ranked, deduplicated candidates.

Deduplication is limited to `subject_type + subject_ref + source_family + sorted gap_types`. Cross-source candidates for the same subject remain separate so Codex review can see distinct reasons.

Ranking is deterministic: severity first, then source weight (`runtime_seo_qa`, `cms_tdk_gap`, `cms_faq_gap`, `gsc_performance`), then evidence count.

## Boundaries

This command writes only a sanitized JSON artifact. It does not write databases, mutate CMS content, enqueue Search Channel records, submit indexing requests, call Google APIs, activate schedulers, start queue workers, or change production environment variables.
