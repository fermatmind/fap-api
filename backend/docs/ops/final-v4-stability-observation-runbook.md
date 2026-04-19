# Final V4 Stability Observation Runbook

## Purpose

This runbook defines the Phase 1 observation gate for the Final V4 upgrade. It decides whether the system can continue content migration work, whether frontend `/auth/guest` single-flight is justified, and when API/FPM resource isolation must be escalated.

## Scope

Observe the MBTI-first runtime path for 24 to 72 hours after any stability change:

- `POST /api/v0.3/attempts/start`
- `POST /api/v0.3/attempts/submit`
- `POST /api/v0.3/auth/guest`
- MBTI lookup/questions/read paths
- MBTI result retrieval
- PHP-FPM pool health
- HTTP 429 and 5xx rates

Do not use this runbook to approve content migrations for high-traffic hubs. Homepage, `/tests`, `/career`, and test category pages still require the Phase 3 stale last-known-good cache layer before migration.

## Business Priority

Runtime priority is fixed:

1. L1: MBTI
2. L2: Big Five
3. L3: SBTI, articles, topics, career recommendations, and non-core tests

Any degradation decision must preserve MBTI start, submit, take, and result before non-core CMS/API traffic.

## Required Signals

Collect these signals over the same window:

- `attempts/start` request count, p50, p95, p99, 429, 5xx
- `attempts/submit` request count, p50, p95, p99, 401, 429, 5xx
- `/auth/guest` request count, p50, p95, p99, 202/200, 401, 429, 5xx
- Ratio of `/auth/guest` calls to `attempts/submit` calls
- Ratio of `attempts/start` calls to `attempts/submit` calls
- `php-fpm` active processes, idle processes, listen queue, slow requests, and `max_children_reached`
- DB slow query telemetry for attempt write and result paths
- Cache hit/miss behavior for lookup/questions and result paths
- Any deploy or content release events during the window

## Pass Criteria

The observation window passes only when all of these are true:

- `attempts/start` has no sustained 429 or 5xx increase.
- `attempts/submit` has no sustained 429 or 5xx increase.
- `/auth/guest` volume is not amplifying relative to submit volume.
- PHP-FPM does not reach `max_children_reached`.
- PHP-FPM listen queue does not show sustained backlog.
- MBTI landing, take, submit, and result remain usable throughout the window.
- Non-core CMS/API traffic does not correlate with MBTI latency or error spikes.

## A3 Auth Guest Single-Flight Gate

Do not implement frontend `/auth/guest` single-flight unless the observation window proves amplification.

Single-flight is justified if at least one condition is true:

- `/auth/guest` request count is greater than 25% of `attempts/submit` count during normal traffic.
- A 401 burst on submit produces more than one `/auth/guest` call per browser session within a 10-second window.
- `/auth/guest` p95 latency or 429 rate increases during submit retry bursts.
- `/auth/guest` contributes to PHP-FPM saturation or queue backlog.

If none of these conditions are true, keep A3 deferred.

## A4 API/FPM Resource Isolation Gate

Escalate API/FPM resource isolation when any condition is true:

- Non-core CMS/API routes correlate with MBTI start/submit/result latency or 5xx.
- PHP-FPM reaches `max_children_reached` during mixed MBTI and CMS traffic.
- FPM listen queue backlog persists while MBTI traffic is active.
- Lookup/questions read paths and attempt write paths contend for the same saturated worker pool.
- A content release or CMS spike degrades MBTI submit or result paths.

Target isolation planes:

- Lookup/questions read paths
- Auth/start/submit/result write paths
- Non-core CMS/API paths

Acceptable implementation paths include separate API instances, separate FPM pools, route-based upstreams, or equivalent platform-level resource pools.

## Stop Conditions

Stop the train and do not start the next content migration PR if any of these happen:

- `attempts/submit` has sustained 5xx or 429.
- PHP-FPM reaches `max_children_reached`.
- `/auth/guest` amplification meets the A3 gate and remains unmitigated.
- Non-core CMS/API traffic degrades MBTI paths.
- Required telemetry is missing or ambiguous.

## Evidence To Record

Before marking the observation as passed or failed, record:

- Observation start and end timestamps.
- Deploy SHA and deploy timestamp.
- Request/error summaries for start, submit, guest auth, lookup/questions, and result.
- PHP-FPM active/idle/listen queue/max children summary.
- Slow query summary for attempt write and result paths.
- Decision: passed, failed, or inconclusive.
- Follow-up decision: continue migration, implement A3, implement A4, or pause.

## Repository Rule Impact

This runbook is backend runtime governance. It does not change content ownership, CMS resources, public content APIs, Media Library behavior, or frontend fallback behavior.
