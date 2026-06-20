# GSC Live Read-only Adapter

Task: `SEO-GSC-LIVE-READONLY-ADAPTER-01`

This PR adds the first backend read-only Google Search Console adapter boundary for FermatMind SEO Agent.

It does not enable live GSC by default, write `seo_intel`, create CMS drafts, enqueue Search Channel records, submit URLs, request indexing, activate schedulers, edit production environment files, or expose credentials.

## Runtime Boundary

Default `gsc_foundation` behavior remains fixture-only unless a caller explicitly asks for the live preflight:

```bash
php artisan seo-intel:collect --collector=gsc_foundation --gsc-live-preflight --dry-run --no-write --json
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

## Next Safe Step

After this PR is merged, the next safe operational step is a credential-side preflight using the command above in a secure environment. If the preflight reports `ready`, a later separately approved PR can add a bounded dry-run import/backfill path that normalizes live rows, writes only when explicitly approved, and keeps `GscDataQualityGate` as the opportunity-queue gate.
