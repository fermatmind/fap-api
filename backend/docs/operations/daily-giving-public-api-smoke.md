# DailyGiving Public API Smoke

Date: 2026-06-05

PR train item: `DAILY-GIVING-PUBLIC-API-SMOKE-01`

Mode: backend smoke contract and test fixtures only. This PR does not create real DailyGiving records, upload proof, process proof files, mutate CMS, publish, index DailyGiving, create trust badges, submit search URLs, run social distribution, or deploy.

## Smoke Coverage

- A test fixture public record makes `/api/v0.5/foundation/giving-records` return at least one item.
- The months endpoint returns at least one month for the fixture public record.
- The show endpoint returns the fixture record by public `record_code`.
- Public responses exclude private proof paths, private receipt references, redaction notes, internal notes, and admin user ids.
- The fixture remains `is_indexable=false`.

## Boundary

This smoke test proves backend public API projection behavior with controlled test data. It does not prove production has real public records, does not upload or redact proof, and does not authorize indexing or trust badges.

## Next Gate

DailyGiving indexability remains blocked until page-level noindex, sitemap, and llms gates are verified separately.
