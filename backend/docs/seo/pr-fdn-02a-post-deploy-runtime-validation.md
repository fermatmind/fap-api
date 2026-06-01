# PR-FDN-02A-POST-DEPLOY-RUNTIME-VALIDATION

## Executive Summary

PR-FDN-02A backend MVP is deployed and the public Foundation Daily Giving API contract is reachable through the production runtime.

Read-only runtime checks on 2026-06-01 confirmed:

- `https://api.fermatmind.com/api/v0.5/foundation/giving-records` returns HTTP 200 through Node HTTPS.
- `https://api.fermatmind.com/api/v0.5/foundation/giving-records/months` returns HTTP 200 through Node HTTPS.
- `https://fermatmind.com/api/v0.5/foundation/giving-records` returns HTTP 200 through apex same-origin.
- `https://fermatmind.com/api/v0.5/foundation/giving-records/months` returns HTTP 200 through apex same-origin.
- Current public record count is 0, so the live payload is an empty public ledger response, not a private-data fixture.

The only remaining runtime sidecar is the already-known direct `curl` path issue to `api.fermatmind.com`, where macOS LibreSSL reports `SSL_ERROR_SYSCALL`. Application-layer Node HTTPS and same-origin apex API reads are healthy.

## Backend MVP Source

The backend MVP was introduced by fap-api PR #1758:

- PR: `PR-FDN-02A: Add daily giving ledger backend MVP`
- Merge commit: `dc51d31168027a105b644da5e6e85338cbbb4277`
- Merged at: `2026-05-30T02:10:57Z`
- GitHub checks: passed

## Route Contract

Local route registration confirms the Foundation Daily Giving public API routes are present:

- `GET /api/v0.5/foundation/giving-records`
- `GET /api/v0.5/foundation/giving-records/{recordCode}`
- `GET /api/v0.5/foundation/giving-records/months`
- `GET /api/v0.5/foundation/giving-records/months/{yearMonth}`

## Runtime Contract

Observed production payload shapes:

- List endpoint: `ok`, `items`, `pagination`
- Months endpoint: `ok`, `months`

The live list currently returns `items=[]` and pagination total `0`. That is acceptable for a deployed MVP with no public ledger rows yet.

## Privacy Boundary

The deployed source contract and focused backend tests keep private operational fields out of public API payloads:

- `proof_private_path`
- `receipt_reference_private`
- `internal_notes`
- `proof_redaction_notes`
- `created_by_admin_user_id`
- `updated_by_admin_user_id`

The public API uses the `publishedPublic()` record scope and the `toPublicArray()` model boundary.

## Publication Gate

The public API is bounded to published public records. Planned, voided, and non-public records are excluded by model scope and feature tests.

## Sidecar Issues

`curl` direct to `https://api.fermatmind.com` still fails from the local macOS path with LibreSSL `SSL_ERROR_SYSCALL`. This is classified as the existing OPS API direct TLS path sidecar. It does not block the backend MVP validation because:

- Node HTTPS direct API reads return HTTP 200.
- Apex same-origin API reads return HTTP 200.
- Frontend Node1 can use same-origin public API rewrites for Foundation surfaces.

## What Was Not Done

- No production data was mutated.
- No CMS data was mutated.
- No deploy was performed.
- No Search Channel action was performed.
- No URLs were submitted.
- No external search or social API was called.
- No credentials were handled.
- No env, DNS, or nginx changes were made.

## Final Decision

`pr_fdn_02a_post_deploy_runtime_validation_completed_with_ops_sidecar`

## Next Task

`PR-FDN-02B-POST-DEPLOY-RUNTIME-SMOKE`
