# DailyGiving Operator-Approved Public Proof Gate

Date: 2026-06-05

PR train item: `DAILY-GIVING-REDACTED-PUBLIC-PROOF-01`

Mode: public-proof readiness gate, generated artifact, and focused contract test only. This inherited file name still references the earlier redaction gate, but the current repository rule allows operator-approved original charity donation proof images as public proof media. This PR does not create, edit, upload, or publish a proof file. It does not mutate production records, CMS, media library, search channels, social channels, deploy state, or indexability.

## Decision

The first DailyGiving record may not become public until an operator-approved public media URL exists and is bound through `proof_public_url` with `proof_status=operator_approved_available`. A separate redacted derivative is not required when the operator approves the original charity donation receipt/proof image for public use.

## Source Selection

Use the charity donation receipt or donor confirmation proof as the preferred public proof source. Do not use a bank or wallet transaction detail screenshot as public proof. Raw private storage paths, admin-only ledger fields, tokens, private URLs, secrets, and credentials must remain private.

## Public Proof Boundary

The operator-approved public proof media may show donation-facing receipt content needed to support the public record, including:

- recipient name and public website;
- donation date;
- amount and currency;
- public receipt or donor-confirmation context approved by the operator.

The public API and frontend must still never expose private storage paths, admin-only notes, backend-only ledger fields, tokens, private URLs, secrets, credentials, or system metadata.

## URL Gate

`proof_public_url` may be set only after review. It must:

- use `https://`;
- point to operator-approved public media for the original charity donation proof image;
- avoid private, auth, session, token, secret, credential, or backend-only indicators;
- follow the existing storage gate shape such as a reviewed `/media/` or `/public/` URL;
- be different from `proof_private_path`.

## Release Blockers

Before any later public activation:

- operator-approved public proof media URL exists;
- `proof_status=operator_approved_available`;
- `proof_public_url` points only to the operator-approved public media asset;
- `proof_redaction_notes` and private receipt references remain admin-only;
- claim lint rejects official partnership, endorsement, certification, guaranteed impact, or UNICEF/UN backing implications;
- `is_indexable=false` remains set;
- no trust badge, paid-page trust claim, search submission, social distribution, or public amplification is enabled.

## Deferred Sidecar

This PR intentionally leaves public media upload outside the repository. The next production activation remains blocked until an operator supplies the real public media URL and explicitly authorizes production record mutation.
