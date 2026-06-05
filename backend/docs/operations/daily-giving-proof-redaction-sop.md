# DailyGiving Proof Redaction SOP

Date: 2026-06-05

PR train item: `DAILY-GIVING-PROOF-REDACTION-SOP-01`

Mode: SOP, generated artifact, and contract test only. This PR does not create records, upload proof, process proof, mutate CMS, publish, index DailyGiving, create trust badges, submit search URLs, or deploy.

## Decision

Raw proof must stay private. Public proof may exist only as a separate redacted public artifact after review. Withheld proof is allowed only as a reviewer-approved privacy or safety exception, and cannot power a trust badge or public amplification claim.

## Storage Classes

| Proof class | Storage rule | Public access |
| --- | --- | --- |
| raw receipt / raw proof | private disk or private bucket only | forbidden |
| admin proof access | private storage driver or short-TTL signed admin URL | admin-only |
| redacted public proof | public-safe media path or public signed URL after review | allowed after review |
| withheld proof | private raw proof retained with reviewer reason | public proof unavailable |

## Redaction Standard

No redaction is allowed only when reviewer inspection confirms no sensitive fields exist.

Fields that must be redacted if present:

- donor legal name unless explicitly approved;
- donor email, phone, address;
- bank account, card number, payment account id;
- full transaction number or full order number;
- payment provider user id;
- billing or invoice address;
- QR payment token;
- session id, auth token, private receipt URL, browser URL with private token;
- internal admin comments.

Fields that may remain visible after review:

- recipient name;
- donation date;
- amount and currency;
- non-sensitive receipt issuer name;
- proof status;
- short masked public reference.

## Withheld Proof Rule

`proof_status=withheld` requires a reviewer reason. It may support a public record only when the record itself passes public-release review and the public page/API makes clear that public proof is withheld. It must not support trust badge, paid-page proof, high-amplification claim, or guaranteed-impact claim.

## Public API Rule

Public API must not expose:

- `proof_private_path`
- `proof_redaction_notes`
- `receipt_reference_private`
- `internal_notes`
- admin user ids
- raw transaction ids
- payment account details
- private sync diagnostics

Public API may expose only reviewed public fields such as recipient, date, amount, currency, proof status, redacted proof URL, redacted receipt reference, social links after review, and public notes after claim lint.

## Review Checklist

Before `is_public=true`:

- raw proof is private;
- public proof URL, if present, points only to redacted proof;
- sensitive fields are redacted or reviewer confirms none exist;
- withheld proof has reviewer reason;
- recipient/date/amount are verified;
- public notes pass claim lint;
- `is_indexable=false` remains set;
- no trust badge is enabled.
