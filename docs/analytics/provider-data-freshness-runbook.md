# Analytics provider data freshness

## Boundary

`analytics_funnel_daily` remains the backend business-truth read model for attempts, orders, payments, unlocks, and reports. GA4 and Baidu Tongji are consent- and ad-block-sensitive browser telemetry. Their site-wide aggregates are directional operations signals only and are compared exclusively with `org_id=0`; they are never compared with the currently selected organization or used to overwrite business facts.

The integration reads aggregate daily counts only. It does not request visitor, client, session, raw-log, or other identifiable dimensions. The cache contains sanitized timestamps, status codes, and aggregate metrics; it contains no provider identifier, access token, private key, credential JSON, authorization header, or response body.

## Configuration and activation

All settings are server-only `ANALYTICS_PROVIDER_FRESHNESS_*` environment variables declared in `backend/.env.example`. Defaults are disabled. Activation requires a separately controlled runtime configuration change:

1. Configure the GA4 property authorization and a service account limited to the `analytics.readonly` scope.
2. Configure the Baidu site authorization with an access token or the official refresh-token inputs.
3. Set the individual provider flags and the global flag to `true`.
4. Verify config-cache refresh and scheduler ownership in the target environment.

Do not commit real identifiers or credentials. Do not paste credentials into command output, logs, tickets, or the Ops UI.

## Runtime behavior

The hourly scheduler registers `analytics:refresh-provider-freshness --json` only when the global feature flag is enabled. It uses `withoutOverlapping(20)` and `onOneServer()`. Each HTTP request has a maximum ten-second timeout and a finite retry budget. Only connection failures, HTTP 429, and HTTP 5xx are retried with bounded jitter; 401, 403, invalid configuration, and malformed responses are not retried indefinitely.

The query date is the last complete Asia/Shanghai calendar day (D-1). A versioned cache snapshot records last attempt, last success, data-through date, sanitized status, aggregate metrics, and whether the last-known-good values are in use. A failed refresh updates attempt diagnostics but preserves successful metrics and timestamps. `--dry-run` performs the read and reconciliation without writing cache.

Do not run the command against live providers until credentials, site/property authorization, quota ownership, and activation have been separately approved.

## Status interpretation

- `healthy`: directional backend and provider signals are present within their freshness windows; this is not an equality or accuracy claim.
- `degraded`: one provider is unavailable or directionally disagrees with the other while backend activity is sufficient.
- `stale`: backend refresh or provider success/data-through is older than its configured window.
- `unconfigured`: a provider flag or credential set is disabled or incomplete; no request is sent.
- `unknown`: the global backend row is missing, activity is below the minimum, or evidence is otherwise insufficient. Missing is never converted to zero.
- `investigate`: backend activity meets the threshold while both provider aggregates are zero.

Provider metrics can differ from backend metrics because browser consent, blockers, provider processing, and taxonomy differ. Use the panel to locate a collection or processing problem, not to claim match rate or replace backend truth.

## Safe diagnostics

Use the authenticated existing Ops Funnel & Conversion page or a sanitized `--json --dry-run` fixture run. The page stays available when provider cache/config reads fail. Diagnostic codes are intentionally coarse. If a request fails, inspect provider access and quota in its controlled console without copying tokens, property/site identifiers, response bodies, or credential material into repository artifacts.

## Repository rule impact

This adds an opt-in backend operations integration and extends the existing private Ops funnel page. It does not change content ownership, public APIs, CMS, SEO, sitemap, llms, frontend authority, database schema, or deployment behavior. Backend business truth remains authoritative; provider telemetry is read-only and directional.

## Official protocol references

- Google Analytics Data API `runReport`: <https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport>
- Google service-account OAuth flow: <https://developers.google.com/identity/protocols/oauth2/service-account>
- Baidu Tongji `getData` report API: <https://tongji.baidu.com/api/manual/Chapter1/getData.html>
- Baidu OAuth token flow: <https://openauth.baidu.com/doc/doc.html>
