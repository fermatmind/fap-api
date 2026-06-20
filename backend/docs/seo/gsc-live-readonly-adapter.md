# GSC Live Read-only Adapter

Task: `SEO-GSC-LIVE-READONLY-ADAPTER-01`

This PR adds the first backend read-only Google Search Console adapter boundary for FermatMind SEO Agent.

It does not enable live GSC by default, write `seo_intel`, create CMS drafts, enqueue Search Channel records, submit URLs, request indexing, activate schedulers, edit production environment files, or expose credentials.

## Runtime Boundary

Default `gsc_foundation` behavior remains fixture-only unless a caller explicitly asks for the live preflight:

```bash
php artisan seo-intel:collect --collector=gsc_foundation --gsc-live-preflight --dry-run --no-write --json
```

After credential preflight is ready, a bounded live read must still use a second explicit gate:

```bash
php artisan seo-intel:collect --collector=gsc_foundation --gsc-live-read --dry-run --no-write --json --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD --limit=250
```

The preflight checks only sanitized readiness facts:

- GSC collector enabled flag
- GSC live API enabled flag
- configured GSC property
- configured auth mode
- presence and shape of either an access token or service-account credential
- external API gate configuration

The preflight does not call Google. It never prints access tokens, private keys, client emails, cookies, sessions, raw credential paths, or raw credential JSON.

## Live Read Adapter

`GscReadonlyLiveAdapter` contains a read-only Search Analytics client guarded by all of these gates:

- `seo_intel.gsc_enabled=true`
- `seo_intel.gsc_live_api_enabled=true`
- `seo_intel.allow_external_api_calls=true`
- configured `seo_intel.gsc_property_url`
- valid `seo_intel.gsc_readonly_adapter.auth_mode`
- valid credential presence
- explicit runtime option `execute_live_read=true`

The adapter returns Search Analytics rows only to the caller. It does not persist rows. A later PR must separately approve any import/backfill into `seo_gsc_daily` and must pass `GscDataQualityGate` before opportunity scoring can use live rows.

The `--gsc-live-read` command returns only sanitized artifacts: row counts, date window, dimensions, hashed URL/query identifiers, masked query display, safe metrics, readiness metadata, and data-quality gate state. It must not print raw query strings, raw URLs, credential paths, access tokens, service-account JSON, private keys, client emails, cookies, or session values.

## Supported Credential Shapes

Allowed safe-secret sources:

- `SEO_INTEL_GSC_AUTH_MODE=access_token` with `SEO_INTEL_GSC_ACCESS_TOKEN`
- `SEO_INTEL_GSC_AUTH_MODE=service_account` with `SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON`
- `SEO_INTEL_GSC_AUTH_MODE=service_account` with `SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH`

Credentials must come from a safe secret channel. They must not be committed, printed, logged, pasted into docs, stored in generated artifacts, or exposed in PR bodies.

## Explicit Non-goals

- no CMS write
- no opportunity queue enqueue
- no Search Channel enqueue, approve, submit, or retry
- no GSC URL Inspection request indexing
- no sitemap submission
- no Baidu or IndexNow call
- no scheduler or queue worker activation
- no production config mutation
- no GSC-as-URL-Truth behavior

## Hong Kong Sidecar Runner Boundary

When mainland production egress cannot reach Google OAuth or Search Console, the approved safe architecture is a separate Hong Kong sidecar runner. The runner may execute only the read-only preflight and bounded live-read commands above from a secure runtime that can reach Google. It must not run inside the 88CN web application process, share 88CN PM2 process state, modify 88CN app files, proxy arbitrary traffic, write `seo_intel`, enqueue Search Channel records, mutate CMS, submit URLs, or request indexing.

## Next Safe Step

After this PR is merged, the next safe operational step is a credential-side preflight using the command above in the Hong Kong sidecar environment. If the preflight reports `ready`, a bounded `--gsc-live-read` may produce a sanitized JSON artifact. Any import/backfill into `seo_gsc_daily` remains a later separately approved PR and must keep `GscDataQualityGate` as the opportunity-queue gate.
