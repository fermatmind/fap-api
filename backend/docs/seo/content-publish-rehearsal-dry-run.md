# Content Publish Rehearsal Dry-run

## Purpose

CONTENT-OPS-02B adds a reusable backend dry-run validator for content publish
rehearsal. The first runtime surface is `article` because the controlled
article publish path already has a mature preflight vocabulary.

The dry-run validator does not publish, does not mutate CMS content, does not write `seo_intel`, does not write Observation Queue rows, does not enqueue Search Channel Queue rows, does not submit URLs, and does not change sitemap or `llms.txt`.

## Runtime

Service:

- `App\Services\SeoIntel\ContentOps\ContentPublishRehearsalDryRun`

Command:

```bash
php artisan seo-intel:content-publish-rehearsal --article=123 --dry-run --no-write --json
```

The command is intentionally strict. `--dry-run`, `--no-write`, and `--json` are
required so it can be used in CI and manual review without accidentally creating
write paths.

## Output Contract

The runtime output includes:

- `status`
- `rehearsal_state`
- `dry_run=true`
- `no_write=true`
- `writes_attempted=false`
- `cms_mutation_attempted=false`
- `article_publish_attempted=false`
- `search_channel_enqueue_attempted=false`
- `search_submission_attempted=false`
- `sitemap_mutation_attempted=false`
- `llms_mutation_attempted=false`
- `observation_queue_write_attempted=false`
- `planned_observation_events`
- `claim_lint_state`
- `internal_link_readiness_state`
- `blockers`
- `warnings`

## Article Checks

The article dry-run checks:

- status and review state
- public/indexable flags
- working revision approval
- latest editorial import gate
- body hash alignment
- SEO title, description, and canonical URL
- robots/indexability state
- references
- CTA slots
- FAQ items
- media / cover readiness
- claim lint state
- backend-declared internal link targets
- Search Channel eligibility as dry-run output only
- planned Observation Queue events as dry-run output only

## Claim and Link Gates

Claim lint may return `safe`, `needs_review`, or `blocked`. Boundary-context
warnings can be acknowledged for dry-run review by passing
`--ack-claim-warning=<article_id>`. Non-boundary warning matches remain blocked.

Internal link readiness is based on backend editorial package targets such as
`target_topics` and `target_tests`. Frontend fallback links, static sitemap,
static `llms.txt`, crawler logs, search engine responses, and local copies are
not link authority.

## Safety Boundary

This PR introduces no mutation path. It does not call publish services, does not
run migrations, does not read production crawler logs, does not touch fap-web,
does not expose Metabase, does not add scheduler jobs, and does not create pSEO.

The planned Observation Queue and Search Channel fields are advisory dry-run
signals only. They are not URL Truth and do not create queue rows.

## Final Decision

`content_publish_rehearsal_dry_run_ready`

Next task: `INTERNAL-LINK-01A`
