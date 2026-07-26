# SEO Search-to-Result Funnel

`SEO-SEARCH-TO-RESULT-FUNNEL-01` adds a read-only backend contract that connects
GSC page aggregates to existing product funnel aggregates.

## Sources and join

- GSC source: `seo_gsc_daily`
- Product source: `seo_event_funnel_daily`
- Public/indexability authority: `seo_urls`
- Join grain: `report_date + canonical_url_hash + source_engine`
- GSC rows are collapsed to page level across query/device/country dimensions.
- `data_origin` remains visible and is read from sanitized GSC metadata. Only
  origins allowed by `seo_intel.gsc_data_quality` may enter a report; a
  forbidden, unknown, or mixed trusted/untrusted source blocks the complete
  window instead of contaminating its metrics.
- `page_family` comes from backend URL Truth `page_entity_type`.

The output uses only the SHA-256 canonical URL hash. It never returns a raw URL,
raw query, result identifier, attempt identifier, order identifier, recovery
token, or payment identifier.

## Product event mapping

The historical daily columns are exposed with the current product vocabulary:

| Output | Existing aggregate column |
| --- | --- |
| `start_test_count` | `start_attempt_count` |
| `complete_test_count` | `submit_attempt_count` |
| `view_result_count` | `view_result_count` |

`start_test_per_1000_impressions` is `start_test_count * 1000 / impressions`.
Step rates are integer parts-per-million. The service reports observed aggregates;
it does not claim user-level or causal attribution.

`valid_product_start_count` is non-zero only when the canonical hash resolves to
a backend-authoritative, public, non-private `seo_urls` row with
`indexability_state=indexable`, and the event aggregate is production traffic.
The accepted URL Truth authorities are `backend_cms`, `backend_registry`,
`backend_sitemap_source`, `scale_catalog`, and configured
`search_channel_queue.approved_source_authorities` such as
`backend_public_surface`; an arbitrary non-backend source cannot validate a
product start. The canonical URL must also be HTTPS on the configured public
canonical host and contain no credentials, query, or fragment. Both
`environment=production` and the shared `environment=prod` alias are production
traffic.

## Command

```bash
php artisan seo-intel:search-to-result-funnel-report \
  --from=2026-07-01 \
  --to=2026-07-07 \
  --source-engine=google \
  --page-family=test_detail \
  --json
```

Both dates are required and inclusive. The command is read-only and returns a
non-zero exit code for an invalid window, missing required source schema, empty
GSC evidence, an empty page-family result, or a disallowed GSC data origin.

## Boundaries

- GSC is search observation only, never order, payment, purchase, or revenue truth.
- The canonical private-path policy is read from
  `seo_intel.core_entry_slo.private_path_segments`; it excludes nested `take`,
  `result`, `attempt`, `order`, `recovery`, `payment`, `checkout`, `report`,
  `share`, and `account` flows before output.
- Unknown and non-indexable hashes may retain privacy-safe search/funnel
  observation, but never count as valid indexed product starts.
- No database/CMS write, publication/indexability change, Search Channel action,
  URL submission, sitemap submission, external API call, migration, or deploy is
  performed.

Repository rule impact: backend URL Truth and product aggregates remain the
authority. This task adds a read-only analytical projection and does not change
content ownership, publication, indexability, sitemap, llms, or frontend
fallback behavior.
