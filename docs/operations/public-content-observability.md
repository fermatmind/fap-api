# Public content observability

## Authority and privacy boundary

Public content runtime metrics cover only exact allowlisted anonymous `GET` route
templates with `org_id=0`. The allowlist lives in
`backend/config/public_content_observability.php`. It deliberately excludes
attempt, report, result, order, payment, shortlist, internal, CMS write, and Ops
routes.

Metrics contain only route family, L1/L2/L3 priority, normalized locale, status
class counters, and duration histograms. They never persist a concrete URL,
slug, query string, request or response body, user/admin identifier, cookie,
token, IP address, order identifier, attempt identifier, or report identifier.

The runtime middleware rejects authenticated, tenant-scoped, session-bearing,
and non-allowlisted requests before recording. Metric writes are deferred until
after the response and wrapped in a fail-open boundary, so telemetry failure
cannot replace, delay, or reclassify the public content response.

## Retention and aggregation

- Redis-compatible minute buckets retain seven days of aggregate data.
- A scheduled bounded rollup waits two complete minutes before sealing a
  bucket, resumes from the first recorded cursor after scheduler downtime, and
  writes idempotent daily aggregates to `public_content_runtime_daily`.
- Daily aggregates older than 90 days are pruned by the rollup service.
- Histogram buckets provide aggregate p50/p95/p99 estimates without saving raw
  request samples.
- Queries inside seven days use minute resolution. Longer queries use complete
  UTC daily buckets through the Redis boundary day, then resume minute buckets
  on the next UTC day; `effective_start_at` and `aggregation_granularity` make
  that boundary explicit without gaps or double counting.

This PR adds migration code and local migration coverage only. Applying the
migration in production remains a separately authorized deployment operation.
The migration is forward-only so rollback cannot silently delete operational
history; schema reversal requires a separately reviewed forward fix migration.

## Read-only Ops API

`GET /api/v0.5/ops/public-content-health/runtime` is protected by the existing
admin session and CMS read authorization chain. Query parameters are
`window_minutes`, `route_family`, and `locale`. The response is aggregate-only
and has no mutation, retry-write, warm, purge, publish, or deploy action.
Storage failures return a bounded `503 metrics_unavailable` aggregate envelope
instead of exposing infrastructure details or breaking the Ops page contract.

The later CMS health dashboard consumes the same service as a read-only view;
backend/CMS remain the content and publication authority.

## Fixed delivery probes and publication readback

`public-content:probe-delivery` performs a real anonymous HTTP `GET` against
one fixed target per scheduled run. The rotation is L1 MBTI detail, L2 Big Five
hub asset, then L3 Career Industries. Targets, query parameters, payload budget,
timeouts, and cache-state expectations are code-reviewed in
`public_content_observability.probe`; operators cannot supply arbitrary URLs or
private paths on the command line.

The probe follows no redirects and sends no token, cookie, admin session, or
tenant identity. Every request is `org_id=0`. It streams at most 512 KiB from
the response and never writes the URL, query, slug, body, headers outside the
cache-state allowlist, exception message, or user data to logs or cache.

Only the latest bounded result per fixed target is retained for seven days:
status/status class, elapsed milliseconds, bytes, normalized cache state, and
a profile-specific publication readback. Readback fields are an explicit
allowlist of public contract/version timestamps or aggregate counts; the
fingerprint hashes only those fields, never the body. A stale/bypass MBTI or
Big Five cache, oversized payload, non-2xx response, or missing required
readback field marks the probe failed. The scheduled command never warms,
purges, publishes, retries a write, or mutates CMS content.

## Repository rule impact

This observability layer does not change public content ownership, publishing,
indexability, sitemap, llms, canonical, structured data, or frontend fallback
rules. It observes backend-authoritative delivery only.
