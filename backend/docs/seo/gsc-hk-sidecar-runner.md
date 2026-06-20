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

Repeatable sidecar wrapper:

```bash
php artisan seo-intel:gsc-sidecar-runner --mode=preflight --artifact-dir=/opt/fermatmind/seo-gsc-runner/artifacts
```

```bash
php artisan seo-intel:gsc-sidecar-runner --mode=live-read --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD --limit=250 --dimensions=query,page --artifact-dir=/opt/fermatmind/seo-gsc-runner/artifacts
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
- sidecar wrapper must call only `seo-intel:collect --collector=gsc_foundation`
- sidecar wrapper must force `--dry-run --no-write --json`
- sidecar wrapper must fail if the collector reports any write, CMS, Search Channel, or indexing boundary as enabled

## Secret Placement

The service-account JSON must be installed through an operator-approved secret channel only.

Recommended shape:

```text
/opt/fermatmind/seo-gsc-runner/secrets/gsc-service-account.json
```

Permissions should allow only the runner user and root to read it. Do not store the secret under `/var/www/88cn`, the 88CN repository, the FermatMind repository checkout, shell history, generated artifacts, or PR attachments.

## Credential Placement Evidence

Task: `SEO-GSC-HK-SIDECAR-CREDENTIAL-PREFLIGHT-01`

On 2026-06-20, the service-account JSON was placed on the Hong Kong sidecar runner at the approved isolated secret path:

```text
/opt/fermatmind/seo-gsc-runner/secrets/gsc-service-account.json
```

Non-sensitive verification evidence:

- SHA256: `97c1e4c44be769ec66ec1df5f8b816e114262edcd234f2df7fe6e9a835ced02c`
- File size: `2422` bytes
- Secret directory owner/group/mode: `root:fm-seo-gsc` / `0750`
- Secret file owner/group/mode: `root:fm-seo-gsc` / `0640`
- Verification commands were limited to `sha256sum`, `wc -c`, `ls -ld`, and `ls -l`.

No service-account JSON content, private key, access token, client email, cookie, session, raw credential value, or runtime environment secret was committed, printed into this evidence package, or attached to the PR.

This evidence PR did not execute a live GSC read, call Google APIs, write `seo_intel`, enqueue an opportunity, enqueue or submit Search Channel records, mutate CMS, edit production environment variables, or activate a scheduler.

## PHP 8.4 Runtime Evidence

Task: `SEO-GSC-HK-SIDECAR-PHP84-RUNTIME-01`

On 2026-06-20, the Hong Kong sidecar runner runtime was upgraded for sidecar-only execution:

- PHP CLI version: `8.4.22`
- Laravel runtime smoke: `Laravel Framework 12.62.0`
- Composer completed without `--ignore-platform-req=php`
- Verified CLI extensions included `bcmath`, `curl`, `intl`, `mbstring`, `pdo_mysql`, `pdo_sqlite`, `xml`, and `zip`

The existing service-account JSON remained at the approved isolated secret path:

```text
/opt/fermatmind/seo-gsc-runner/secrets/gsc-service-account.json
```

New sanitized evidence artifacts were generated on the sidecar host:

- Preflight artifact: `/opt/fermatmind/seo-gsc-runner/artifacts/gsc-live-preflight-php84-20260620.json`
  - File size: `1406` bytes
  - SHA256: `b48731deca0e7af7d3d10014dcb4a75765893fc2849d75fc0b539ee137dee800`
- Read-only live read artifact: `/opt/fermatmind/seo-gsc-runner/artifacts/gsc-live-read-php84-20260620-window-20260617.json`
  - File size: `2970` bytes
  - SHA256: `d08502f806672e26609f5af7b3ac5c354f464478f64e2cf8498dca821f738999`

Sanitized live-read summary:

- `status=success`
- `collector=gsc_foundation`
- `dry_run=true`
- `no_write=true`
- `external_calls_attempted=true`
- `writes_attempted=false`
- `writes_committed=false`
- `rows_seen=25`
- `date_window=2026-06-17..2026-06-17`
- `mode=gsc_live_readonly_sidecar_read`
- `data_quality_gate=pass`

This runtime evidence PR records the already-completed sidecar verification only. It did not change production `fap-api` environment variables, enable a scheduler, write a database row, import `seo_gsc_daily`, enqueue an opportunity, mutate CMS content, enqueue or submit Search Channel records, request GSC indexing, submit a sitemap, call Baidu, or call IndexNow.

No service-account JSON content, private key, access token, client email, cookie, session, raw query, raw URL, or runtime environment secret was committed, printed into this evidence package, or attached to the PR.

## Runner Wrapper Contract

Task: `SEO-GSC-HK-SIDECAR-RUNNER-WRAPPER-01`

`php artisan seo-intel:gsc-sidecar-runner` is the only approved repeatable wrapper for the Hong Kong GSC sidecar runtime. The wrapper exists to replace manual command assembly with a bounded, auditable command surface.

Wrapper guarantees:

- accepts only `--mode=preflight` or `--mode=live-read`
- live-read requires explicit `--start-date`, `--end-date`, `--limit`, `--dimensions`, and `--artifact-dir`
- live-read limits must stay within `1..250`
- internally invokes only `seo-intel:collect --collector=gsc_foundation`
- always forces `--dry-run --no-write --json`
- preflight always forces `--gsc-live-preflight`
- live-read always forces `--gsc-live-read`
- writes a sanitized JSON artifact to the requested artifact directory
- prints only artifact path, byte size, SHA256, and safe summary fields
- fails closed if any forbidden boundary reports enabled or attempted writes

Forbidden wrapper outcomes:

- no DB write
- no `seo_gsc_daily` import/backfill
- no opportunity queue enqueue
- no CMS write or draft mutation
- no Search Channel enqueue, approval, retry, or submission
- no GSC URL Inspection request indexing
- no sitemap submission
- no scheduler activation
- no queue worker activation
- no raw query, raw URL, credential, token, client email, cookie, session, or private key output

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
