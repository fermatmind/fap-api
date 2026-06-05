# DailyGiving Redacted Public Proof Gate

Date: 2026-06-05

PR train item: `DAILY-GIVING-REDACTED-PUBLIC-PROOF-01`

Mode: public-proof readiness gate, generated artifact, and focused contract test only. This PR does not create, edit, upload, or publish a proof file. It does not mutate production records, CMS, media library, search channels, social channels, deploy state, or indexability.

## Decision

The first DailyGiving record may not become public until a separate redacted public proof artifact exists, has been reviewed, and is bound through `proof_public_url` with `proof_status=redacted_available`. The raw transaction proof and raw receipt proof remain private-only operator assets.

## Source Selection

Use the receipt or donor confirmation proof as the preferred source for the public proof artifact. Do not use a bank or wallet transaction detail screenshot as public proof unless a reviewer confirms all account, balance, transaction, and device metadata fields are fully removed.

## Required Redactions

The redacted public proof must remove or mask every sensitive field if present:

- donor personal identifiers beyond the public brand name approved for display;
- donor email, phone, address, account, card, wallet, or payment account details;
- full receipt id, full transaction serial, full order id, and payment-provider user id;
- balance, account/card tail data, billing metadata, QR token, session id, auth token, and private receipt URLs;
- local device UI metadata, inbox UI, browser UI, private local paths, or admin comments.

The reviewer may leave only public-safe fields needed to support the record:

- recipient name;
- recipient public website;
- donation date;
- amount and currency;
- non-sensitive issuer context;
- short masked public reference if needed.

## URL Gate

`proof_public_url` may be set only after review. It must:

- use `https://`;
- point to the redacted artifact, not a raw receipt or transaction proof;
- avoid private, raw, auth, session, token, account, order, or receipt-private indicators;
- follow the existing storage gate shape such as a reviewed `/media/`, `/public/`, or `redacted` URL;
- be different from `proof_private_path`.

## Release Blockers

Before any later public activation:

- raw proof is confirmed private-only;
- redacted public proof exists as a separate reviewed artifact;
- reviewer confirms no sensitive field remains visible;
- `proof_status=redacted_available`;
- `proof_public_url` points only to the reviewed public artifact;
- `receipt_reference_redacted`, if used, is short and masked;
- `proof_redaction_notes` and private receipt references remain admin-only;
- claim lint rejects official partnership, endorsement, certification, guaranteed impact, or UNICEF/UN backing implications;
- `is_indexable=false` remains set;
- no trust badge, paid-page trust claim, search submission, social distribution, or public amplification is enabled.

## Deferred Sidecar

This PR intentionally leaves the actual image redaction and public media URL creation outside the repository. The next production activation remains blocked until an operator supplies a reviewed public media URL and explicitly authorizes production record mutation.
