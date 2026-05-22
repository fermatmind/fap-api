# Internal Link Graph Dry-run Read Model

Task: `INTERNAL-LINK-01B`

This PR adds a backend dry-run/read model for semantic internal link graph readiness. It reports current backend/CMS link sources, graph family coverage, entity-key gaps, legacy pairing gaps, and unsafe fallback-source counters without writing links.

## Runtime Boundary

The dry-run runtime is:

- `App\Services\SeoIntel\InternalLink\InternalLinkGraphDryRun`
- optional command: `php artisan seo-intel:internal-link-graph --dry-run --no-write --json`

The command requires all three safety flags:

- `--dry-run`
- `--no-write`
- `--json`

## Output Contract

The JSON report includes:

- `dry_run=true`
- `writes_attempted=false`
- `cms_mutation_attempted=false`
- `link_mutation_attempted=false`
- `source_inventory`
- `graph_family_counts`
- `missing_entity_key_count`
- `legacy_unpaired_count`
- `candidate_opportunity_count`
- `unsafe_fallback_source_count`
- `warnings`

## Authority Rules

Backend/CMS entity graph remains internal link truth. The read model may count CMS-owned sources such as article `related_test_slug`, article editorial package target topics/tests, and career guide relation maps.

The following are signals only and must not become graph truth:

- fap-web rendered links
- frontend fallback links
- sitemap-derived links
- `llms.txt`-derived links
- crawler logs
- search engine responses
- GSC/GA4/referral data

GSC, GA4, and referral data may suggest opportunities in a future manual review workflow, but this runtime does not auto-create links.

## Entity Key Policy

The read model follows the governance contract:

- prefer `translation_group_uuid`
- use existing `translation_group_id` only as a transitional key where already supported
- mark missing coverage as `legacy_unpaired`
- title/slug similarity is a migration helper only, not authority

## Safety Guarantees

This dry-run:

- does not mutate CMS content
- does not create internal links
- does not modify fap-web
- does not run migrations
- does not read crawler logs
- does not claim crawler logs as authority
- does not use sitemap or `llms.txt` as graph truth
- does not enqueue Search Channel rows
- does not submit URLs
- does not write Observation Queue rows
- does not write `seo_intel`

## Next Task

Next task: `CLAIM-LINT-01A`
