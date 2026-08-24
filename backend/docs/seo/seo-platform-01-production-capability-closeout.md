# SEO-PLATFORM-01：生产 SEO 能力实况与即时数据质量闭环验收

Status: complete by immediate authenticated production data-quality validation.

On 2026-08-24 the user explicitly authorized this completion condition:
**scheduled relay is intentionally deferred and non-blocking for the immediate closeout**.
This is not a scheduled relay closeout. Fresh API pagination, scheduled restricted-egress,
scheduled overlap, and scheduled rerun accumulation remain `production_unproven`.

No manual Nightly, Recovery workflow, temporary workflow or cron, external egress bypass,
credential copy, CMS write, URL Truth write, issue mutation, or search submission was used.

## Release Chain

The aggregate-only production read surface was deployed at
`022b73a793a87626ec82c747023f7f340b858f60`. Exact-SHA CI run `32706502269`
completed successfully at `2026-08-24T08:33:56Z`. Deploy run `32706956241`
completed staging smoke at `2026-08-24T08:45:40Z` and production smoke at
`2026-08-24T08:49:49Z`; automatic LKG restoration was not used.

Every required SEO Operations, GSC, classifier, runtime-binding, closeout, and URL Truth
classification revision is an ancestor of that active production revision. The evidence
document revision is docs-only: it must receive an exact-SHA deploy-skip receipt and has
`Production: not applicable`; it must not be described as a production deployment.

## Production Capability Truth

Authenticated production readback verified all six SEO Operations workspaces: Overview,
Search Performance, Technical Audit, Keyword and Content Opportunities, AI Visibility,
and Execution Center.

- `production_healthy`: SEO Operations UI, readonly GSC readmodel, aggregate closeout,
  GSC data-quality queue, Issue Queue, issue clustering/priority, runtime audit,
  opportunity queue, and the CMS execution boundary.
- `production_degraded`: URL Truth, because 17 current backend/CMS-authority-qualified
  public candidates are absent from the current URL Truth inventory.
- `production_unproven`: fresh GSC API pagination/completeness and all scheduled-only
  relay, restricted-egress, overlap, and rerun evidence.
- `deployed_disabled`: Search Channel submission boundary.
- `external_not_connected`: Core Web Vitals, rank tracking, AI Visibility, and backlinks.
  Production explicitly labels them not connected and shows no synthetic data.

## Immediate GSC Readmodel Validation

Evidence source is the current persisted production readmodel, not a new GSC API call.

- Property: `sc-domain:fermatmind.com`; timezone: UTC; search type: `web`.
- Requested read window: 90 days, anchored to the latest persisted report date,
  `2026-05-24` through `2026-08-21`.
- Observed date coverage: 83 date points, `2026-05-31` through `2026-08-21`.
- Latest data lag at readback: 3 days. UI last-success display: `2026-08-24 01:58:11`.
- Full detail rows: 15,165; natural unique keys: 15,165; duplicates: 0.
- Clicks: 278; impressions: 22,438; CTR: 1.239%; average position: 14.8737.
- The unbounded database aggregate exactly matches the full-detail aggregation. The old
  2,000-row read limit is absent.

Queries remain masked. The read surface returned no query, URL, hash, private path, or
identity data. Because no fresh full-window API receipt exists, `pages_fetched` and row
completeness remain `production_unproven`; tests are not used as production evidence.

## Unmapped Production Distribution

Classification authority is restricted to backend/CMS authority and persisted URL Truth.
GSC, sitemap, HTML, and crawler observations did not create or mutate URL Truth.

- Unmapped detail rows: 4,662.
- Unique normalized canonical URL identities: 395.
- Unique query/page/date combinations: 3,328.
- Family: Tests 18; Articles 7; Career 287; Personality 65; Other 18.
- Locale: zh-CN 245; en 143; unknown 7.
- Root causes: `host_canonical_normalization` 0;
  `locale_path_normalization` 0; `current_url_truth_missing` 17;
  `historical_url` 0; `redirect_alias` 0;
  `query_parameters_or_malformed_url` 3; `retired_noindex` 0;
  `not_published` 0; `private_deny_path` 1; `raw_canonical_missing` 0;
  `unknown` 374.
- `current_url_truth_missing_handoff_count`: 17.

Unknown means the stored production row exposes only an opaque canonical hash and no
approved authority source resolves it. The closeout does not reverse-create a URL or infer
a private path. No URL Truth write or repair was performed.

## Queue Reconciliation: 2,073 versus 5

The two figures come from independent production tables and use different units.

- `seo_gsc_data_quality_queue`: 2,073 total; 2,073 open; 0 processed; 403 unique URL
  hashes; one root cause, `canonical_url_not_in_url_truth`. These are historical snapshot
  quality rows keyed by report date + canonical hash + issue code.
- `seo_issue_queue`: 5 total; 5 open; 0 processed; 5 unique URL hashes; one root cause,
  `missing_lastmod_for_indexable_url`. These are independent operational issues.
- Shared unique URL hashes: 0. There is no foreign key or row equivalence. The 2,073
  quality items are not the five Issue Queue rows and are not the three issue clusters.

Issue Queue still uses `SeoIssueClusterReadService`, clustered by
`detector + root_cause + page_family + authority_revision`: five open issues form three
P3 clusters affecting five URLs. P0/P1/P2/P3 is 0/0/0/3; historical-noise candidates are
5; duplicate candidates and auto-close candidates are 0. No row was closed, deleted, or
fixed.

## Roadmap Decision

Task #5 is next. Production proves 17 current backend/CMS-authority-qualified candidates
that should be represented by URL Truth but are missing. That evidence outranks the three
P3 lastmod clusters. Task #2 follows for the 374 unresolved opaque identities and the
small parameter/private-path cohorts. Task #10 is not selected or implemented in this
closeout.

Tasks #3 and #6 remain `delta_only`. The instruction and repository do not provide
authoritative definitions for #4, #8, #9, #11, or #12; they remain scope-unknown.

## Remaining Non-Blocking Risks

- Scheduled relay is intentionally deferred and non-blocking for the immediate closeout.
- Fresh API `pages_fetched`, row completeness, scheduled restricted-egress receipt,
  scheduled overlap, and scheduled rerun accumulation remain `production_unproven`.
- 374 opaque unmapped identities require approved backend/CMS publication history or an
  approved alias registry before a more specific root cause is possible.
- Core Web Vitals, rank tracking, AI Visibility, and backlinks remain unconnected.
- Search submission remains disabled. No CMS publication, canonical/noindex/lastmod
  change, URL Truth write, issue closure, or external vendor hookup was performed.
