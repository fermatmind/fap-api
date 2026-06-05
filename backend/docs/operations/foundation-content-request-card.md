# Foundation Content Request Card

Date: 2026-06-05

PR train item: `FOUNDATION-CONTENT-REQUEST-CARD-01`

Mode: GPT input requirements only. This file is not public Foundation copy, not a CMS draft, not final metadata, not FAQ answers, not CTA copy, not social copy, not trust badge copy, and not a publish approval.

## Purpose

Give GPT enough source-backed context to help prepare CMS content inputs for the Foundation plan page without inventing public claims or writing publishable copy.

The output expected from GPT is a structured content brief for operator review. Final content authority remains CMS/backend `content_pages`.

## Evidence Inputs GPT Must Use

| Evidence | Source | Current value |
| --- | --- | --- |
| Foundation route status | `foundation-trust-page-asset-inventory-2026-06-04.md` | EN/ZH public routes return 200 |
| Foundation indexability | `foundation-trust-page-asset-inventory-2026-06-04.md` | Foundation is indexable and present in sitemap |
| Foundation authority | production content page API scan | CMS `content_pages`, published, public, indexable, approved |
| DailyGiving public state | public records and months API scan | public records count 0, public months count 0 |
| DailyGiving indexability | public route scan | noindex/nofollow/noarchive/nocache |
| DailyGiving discoverability | sitemap/llms scan | not present in sitemap, `llms.txt`, or `llms-full.txt` |
| Claim boundary | `foundation-claim-boundary-contract.md` | no official partner, endorsement, certification, guaranteed impact, or trust badge claims |
| Operator policy | archived Window 7 operator decisions | CNY 10 daily intention, United Nations Foundation recipient-only boundary |
| Proof policy | archived proof redaction SOP input | raw proof private, public proof redacted or withheld with reviewer reason |

Unknowns must stay Unknown. In particular, private draft record count, storage-level private disk configuration, and future proof availability must not be inferred from empty public APIs.

## Required GPT Output Shape

GPT should return a structured input package with these sections:

1. `content_objective`
2. `source_evidence_table`
3. `module_inventory`
4. `cms_field_requirements`
5. `claim_boundary_matrix`
6. `privacy_and_proof_requirements`
7. `daily_giving_relationship`
8. `faq_question_inventory`
9. `operator_review_checklist`
10. `codex_validation_handoff`

## Module Inventory Requirements

GPT should define module intent and required CMS fields only. It should not draft final paragraphs.

Required modules:

| Module key | Required planning question |
| --- | --- |
| `project_identity` | What public-benefit posture can Foundation explain without claiming legal foundation or nonprofit status? |
| `independent_giving_plan` | How should the CNY 10 daily intention and recipient-only boundary be represented as policy inputs? |
| `evidence_before_public_records` | What evidence is needed before any DailyGiving record supports a public claim? |
| `privacy_and_proof_handling` | What is private, what may be public, and what requires redaction or withholding reason? |
| `non_claims` | Which claims must be explicitly excluded from the content brief? |
| `daily_giving_future_ledger` | How should the page explain DailyGiving as gated/noindex until records and proof gates pass? |

## CMS Field Requirements

GPT should request fields for a CMS `content_page` only. It must not ask Codex to add frontend fallback content.

Required field groups:

- route and locale identifiers for `foundation`
- page status and indexability fields
- body/module structure fields
- claim boundary version
- public benefit policy version
- proof policy summary
- review owner and review timestamp fields
- optional FAQ question inventory
- schema enablement gate tied to visible approved FAQ content

## Claim Boundary Rules

GPT must preserve these constraints:

- United Nations Foundation can be referenced only as a recipient named in operator-reviewed records or policy inputs.
- No official partnership, official cooperation, endorsement, certification, authorization, fundraising authority, or guaranteed impact wording.
- No trust badge language.
- No stable daily giving operation claim while public records and months are zero.
- No public ledger claim until public API returns eligible completed or verified public records.
- No proof availability claim until redacted public proof exists or proof is explicitly withheld with an approved reviewer reason.

## FAQ Inventory Rules

GPT may propose FAQ questions only. GPT must not write final FAQ answers, FAQ schema, H1, meta title, meta description, CTA copy, social copy, or trust badge copy.

FAQ questions should cover:

- public-benefit plan
- recipient-only boundary
- DailyGiving gated status
- record review process
- raw proof versus public proof
- redaction and withheld proof
- unsupported claims prevention
- price/report boundary if needed

## Codex Handoff Requirements

The GPT output should give Codex enough structure to validate:

- CMS/backend source of truth
- no frontend editorial fallback
- claim lint pass
- DailyGiving noindex retained
- DailyGiving absent from sitemap and llms while gated
- FAQ schema disabled unless visible approved FAQ content exists
- public API not used to infer private records

## Explicit Non-Goals

- No CMS mutation.
- No DailyGiving record creation.
- No proof upload or proof processing.
- No publishable Foundation page copy.
- No final metadata, H1, CTA, FAQ answers, social copy, or trust badge copy.
- No search submission.
- No deploy.
