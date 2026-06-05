# DailyGiving Proof Storage Gate

Date: 2026-06-05

PR train item: `DAILY-GIVING-PROOF-STORAGE-GATE-01`

Mode: scoped backend contract and tests. This PR does not create records, upload proof, process proof files, mutate CMS, publish, index DailyGiving, create trust badges, submit search URLs, or deploy.

## Decision

DailyGiving records now have a model-level proof storage gate. Any save path must reject obvious raw-proof/public-proof boundary violations before a record can be persisted.

## Enforced Rules

- `proof_private_path` must look like a private disk or private bucket path.
- `proof_private_path` must not be a public URL or public media path.
- `proof_public_url` must be an HTTPS operator-approved public media URL.
- `proof_public_url` may point to the original charity donation proof image; a separate redacted derivative is not required.
- `proof_public_url` must not contain private/admin/token/secret indicators.
- `proof_public_url` must not equal `proof_private_path`.
- `proof_public_url` requires `proof_status=operator_approved_available`.
- `proof_status=withheld` requires admin-only `proof_redaction_notes`.

## Public Projection Boundary

The public array must not expose:

- `proof_private_path`
- `proof_redaction_notes`
- `receipt_reference_private`
- `internal_notes`
- admin user ids

## Still Deferred

This gate does not upload or inspect real proof files. Storage-level private disk/bucket confirmation remains a deployment/configuration prerequisite for the later first-record authorization step.
