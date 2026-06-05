# DailyGiving Proof Public Approval SOP

Date: 2026-06-05

PR train item: `DAILY-GIVING-PROOF-REDACTION-SOP-01`

Mode: SOP, generated artifact, and contract test only. This PR does not create records, upload proof, process proof, mutate CMS, publish, index DailyGiving, create trust badges, submit search URLs, or deploy.

## Decision

Raw private storage paths, admin-only notes, backend-only ledger fields, tokens, private URLs, secrets, and credentials must stay private. Public proof may be the original charity donation proof image when the operator approves that image for public media use. A separate redacted derivative is not required. Withheld proof is allowed only as a reviewer-approved privacy or safety exception, and cannot power a trust badge or public amplification claim.

## Storage Classes

| Proof class | Storage rule | Public access |
| --- | --- | --- |
| private raw proof path / private ledger proof | private disk or private bucket only | forbidden |
| admin proof access | private storage driver or short-TTL signed admin URL | admin-only |
| operator-approved public proof | public media URL after operator review | allowed after review |
| withheld proof | private raw proof retained with reviewer reason | public proof unavailable |

## Public Approval Standard

The original charity donation proof image may be public when the operator approves it for public use. Review focuses on URL and system-boundary safety, not on creating a mandatory derivative.

Fields that must never be exposed through public API, frontend rendering, sitemap, llms, social distribution, or search submission:

- private storage path;
- backend-only ledger field;
- proof redaction notes;
- session id, auth token, private receipt URL, browser URL with private token;
- secret, credential, or signed private URL;
- internal admin comments.

Fields that may remain visible after review:

- recipient name;
- donation date;
- amount and currency;
- non-sensitive receipt issuer name;
- proof status;
- operator-approved public receipt context.

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

Public API may expose only reviewed public fields such as recipient, date, amount, currency, proof status, `proof_public_url`, public receipt reference if approved, social links after review, and public notes after claim lint.

## Review Checklist

Before `is_public=true`:

- raw proof is private;
- public proof URL, if present, points only to operator-approved public media;
- private storage path, token, private URL, secret, credential, and backend-only fields are absent from the public API;
- withheld proof has reviewer reason;
- recipient/date/amount are verified;
- public notes pass claim lint;
- `is_indexable=false` remains set;
- no trust badge is enabled.
