# Quality Gate Matrix

| Check | Pass condition | Current read-only result | Repair action allowed now? |
| --- | --- | --- | --- |
| Data origin | `live_gsc_api` | Blocked; documented current foundation status is fixture. | No |
| Source engine | `google` | Gate requires this, but no live passable row set was produced in this PR. | No |
| Required row fields | `canonical_url_hash`, `query_hash`, `clicks`, `impressions` | Schema supports these; current passable rows not proven. | No |
| Date finalization lag | latest report date at least 3 days old | Gate enforces it; current passable rows not proven. | No |
| Freshness | latest report date within max age window, default 10 days | Gate enforces it; current passable rows not proven. | No |
| Forbidden source exclusion | no fixture/mock/static/unknown | Current recorded foundation status is fixture, so blocked. | No |
| Sanitized artifact boundary | no raw query, raw URL, tokens, credentials, cookie, session, raw payload | Contracts require this; no artifact imported in this PR. | No |
| Read model consumption | opportunity queue reads only imported `seo_intel` rows after quality gate pass | Required by contract; no eligible queue input in this PR. | No |
| Search/CMS execution | separate explicit authorization and passing gates | Not authorized. | No |

## Consequence

For the current PR train, GSC / seo_intel should be treated as:

- useful architecture evidence;
- not yet a repair trigger;
- not a source of purchase truth;
- not a substitute for GA/product analytics;
- not a URL Truth authority;
- not a direct Search Channel input.

## Allowed Next Use

The next safe GSC-related work would be another generated-only/read-only pass that either:

1. proves passable imported `seo_intel` rows already exist, using sanitized/hash evidence only; or
2. separately authorizes a live-read sidecar artifact and then a dry-run importer/readback path.

Neither is part of this PR.
