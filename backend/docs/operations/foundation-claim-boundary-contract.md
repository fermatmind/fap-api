# Foundation Public Benefit Claim Boundary Contract

Date: 2026-06-05

PR train item: `FOUNDATION-CLAIM-BOUNDARY-CONTRACT-01`

Mode: contract and docs only. This PR does not create Foundation public copy, DailyGiving promotional copy, CMS records, DailyGiving records, proof files, social posts, trust badges, search submissions, or deploys.

## Decision

Foundation and DailyGiving public-benefit language must stay record-backed and boundary-first.

Foundation may describe an independent public-benefit plan and operator-reviewed record system. DailyGiving may not be described as a public trust asset, trust badge, paid-page proof, search/social amplification asset, or stable daily operation until public records and proof gates pass.

## Allowed Claim Categories

- Independent public-benefit plan.
- Operator-reviewed DailyGiving record process.
- Redacted proof when available and reviewed.
- Public ledger when release gates are met.
- DailyGiving remains noindex until release gates pass.
- United Nations Foundation may be referenced only as the recipient named in reviewed records.
- Proof can be withheld only with an approved reviewer reason.

## Forbidden Claim Categories

- `UN official partner`
- `联合国官方合作`
- `official endorsement`
- `官方背书`
- `certified by`
- `官方认证`
- `guaranteed impact`
- `formal affiliation`
- `authorized by UN`
- `approved by the UN`
- `fundraising for UN`
- `official fundraising partner`
- `registered foundation`
- `nonprofit` unless legal status is separately documented
- stable daily giving operation before public records exist
- all records are public
- trust badge claim without separate readiness

## Evidence-Required Claim Categories

| Claim category | Required evidence before use |
| --- | --- |
| Daily donation is active | Private receipt exists, date verified, recipient verified, amount verified or variance explained |
| Public ledger exists | Public API returns at least one completed or verified public record |
| Public proof available | Redacted public proof URL is reviewed and private proof is not public |
| Amount donated | Record amount matches operator policy or variance is documented |
| Continuous streak | Multiple dated records exist and public API supports the streak without gaps |
| Recipient confirmation | Recipient-side public confirmation exists; otherwise do not imply it |
| Social sync claim | Manual social URL exists and was reviewed for claim safety |

## Before Public Records Exist

Allowed:

- Plan language.
- Governance and review language.
- Proof readiness language.
- Noindex and gated-ledger language.
- Statement that public records are not yet available if needed in internal docs or request cards.

Forbidden:

- Public DailyGiving ledger is live.
- Verified daily donation record exists.
- Stable daily giving operation.
- Trust badge.
- Public amplification proof.
- Search submission proof.

## After Public Records Exist

Allowed only if gates pass:

- A public record is available.
- The record was reviewed under the release gate.
- Public proof is redacted and available, or proof is withheld with approved reviewer reason.
- DailyGiving remains noindex unless a later indexability preflight is separately approved.

Still forbidden:

- Official partnership.
- Official endorsement.
- Certification.
- Authorization by United Nations Foundation or UN.
- Guaranteed impact.
- Trust badge without separate readiness.
- Search submission without separate authorization.

## Social Sync Constraints

Social sync is manual only. Stored social URLs may point to human-reviewed public posts, but the system must not create automatic posts, handle credentials, run scheduled posting, or infer social URLs from record text.

Social content must not imply official partnership, endorsement, certification, authorization, guaranteed impact, fundraising authority, or trust badge readiness.

## Proof Withheld Constraints

Withheld proof is allowed only as a privacy or safety exception with reviewer reason. Withheld proof cannot power trust badges, high-amplification claims, paid-page proof, search submission claims, or guaranteed-impact claims.

## GPT And Codex Boundaries

GPT may produce request cards, module inventories, CMS field needs, claim matrix drafts, SOP inputs, and review templates.

Codex may implement docs, generated artifacts, contract tests, public API smoke, noindex gates, and storage gates.

Neither GPT nor Codex may write final publishable Foundation copy, CTA copy, social copy, trust badge copy, or unsupported DailyGiving promotional copy in this train.
