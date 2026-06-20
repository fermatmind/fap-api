# GSC Hong Kong Sidecar Runner

Task: `SEO-GSC-HK-SIDECAR-RUNNER-01`

This runbook defines the safe execution shape for Google Search Console live read-only collection when mainland production egress cannot reach Google OAuth or Search Console.

## Decision

Use a Hong Kong sidecar runner for GSC read-only collection.

Do not move `fap-api` production to Hong Kong. Do not turn the 88CN production web app into a shared automation host. The runner must be isolated from the 88CN app process and may only execute bounded FermatMind SEO Intel read-only commands.

## Verified Candidate Environment

- Provider: Aliyun ECS
- Region: China Hong Kong
- Instance role: candidate sidecar runner
- Observed OS: Ubuntu 24.04 LTS
- Observed Google egress checks:
  - `oauth2.googleapis.com:443` TCP connection succeeded
  - `searchconsole.googleapis.com:443` TCP connection succeeded

The IP address, SSH credentials, service-account JSON, credential paths, and account identifiers must stay out of repository files, generated artifacts, PR bodies, logs, and public dashboards.

## Allowed Commands

Credential/readiness preflight, no Google call:

```bash
php artisan seo-intel:collect --collector=gsc_foundation --gsc-live-preflight --dry-run --no-write --json
```

Bounded live read, Google read-only call allowed:

```bash
php artisan seo-intel:collect --collector=gsc_foundation --gsc-live-read --dry-run --no-write --json --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD --limit=250
```

## Required Runtime Gates

- `SEO_INTEL_GSC_ENABLED=true`
- `SEO_INTEL_GSC_LIVE_API_ENABLED=true`
- `SEO_INTEL_ALLOW_EXTERNAL_API_CALLS=true`
- `SEO_INTEL_GSC_PROPERTY_URL=sc-domain:fermatmind.com`
- `SEO_INTEL_GSC_AUTH_MODE=service_account`
- `SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH` points to a root-readable or app-user-readable secret file outside git
- command includes `--dry-run --no-write --json`
- live read command includes explicit `--gsc-live-read`

## Secret Placement

The service-account JSON must be installed through an operator-approved secret channel only.

Recommended shape:

```text
/opt/fermatmind/seo-gsc-runner/secrets/gsc-service-account.json
```

Permissions should allow only the runner user and root to read it. Do not store the secret under `/var/www/88cn`, the 88CN repository, the FermatMind repository checkout, shell history, generated artifacts, or PR attachments.

## Output Boundary

The runner may emit a sanitized JSON artifact containing:

- command status
- rows seen
- date window
- dimensions
- HTTP status
- readiness metadata
- data-quality gate status
- hashed canonical URL
- hashed query
- masked query display
- clicks, impressions, CTR ppm, average-position milli

The runner must not emit:

- raw query
- raw URL
- access token
- service-account JSON
- private key
- client email
- credential path
- cookie
- session
- search submission result
- CMS content
- order, payment, or attempt identifiers

## Explicit Non-goals

- no DB write
- no `seo_gsc_daily` import/backfill
- no opportunity queue enqueue
- no Search Channel enqueue, approve, submit, or retry
- no GSC URL Inspection request indexing
- no sitemap submission
- no CMS draft, publish, unpublish, or mutation
- no 88CN runtime deployment
- no generic proxy or arbitrary egress tunnel
- no scheduler activation until a later approval

## Next Approval Gate

Before installing credentials or running the live read on the Hong Kong host, require explicit operator approval naming:

- provider
- host role
- secret destination
- exact env keys
- exact command
- date window
- artifact destination

Example approval phrase:

```text
CONFIRM_GSC_HK_SIDECAR_SECRET_INSTALL: provider=aliyun, region=cn-hongkong, host_role=gsc-sidecar-runner, secret_path=/opt/fermatmind/seo-gsc-runner/secrets/gsc-service-account.json
```

```text
CONFIRM_GSC_HK_SIDECAR_LIVE_READ: property=sc-domain:fermatmind.com, start_date=YYYY-MM-DD, end_date=YYYY-MM-DD, limit=250, writes=false, queue=false, search_submit=false
```
