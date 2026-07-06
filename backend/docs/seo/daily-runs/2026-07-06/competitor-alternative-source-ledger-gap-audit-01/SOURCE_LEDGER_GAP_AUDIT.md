# Competitor Alternative Source Ledger Gap Audit

Date: 2026-07-06

## Candidate Pages

These candidates remain held:

- 16P alternative
- Truity alternative
- 123test alternative

This audit does not verify or state facts about those competitors. It defines what evidence would be required before any page can exist.

## Required Source Ledger Fields

| Field | Requirement |
| --- | --- |
| `competitor_key` | Stable internal key, for example `16p`, `truity`, or `123test` only after approval |
| `source_url` | Exact public URL or archived source |
| `source_type` | Official page, pricing page, help page, methodology page, terms/policy, public documentation, or approved third-party source |
| `retrieved_at` | Date and time of capture |
| `claim_supported` | One narrow claim supported by this source |
| `allowed_surface` | Page body, comparison table, FAQ, disclaimer, metadata, schema, or none |
| `claim_risk` | low, medium, high |
| `quote_limit` | Short excerpt only when legally safe; otherwise paraphrase |
| `review_owner` | Operator/legal/brand reviewer |
| `approval_status` | held, approved, rejected, expired |

## Source Categories Needed

| Category | Needed for | Current status |
| --- | --- | --- |
| Official product positioning | Any description of what the competitor offers | missing |
| Pricing / free tier / paid tier | Any free-vs-paid comparison | missing |
| Methodology / assessment model | Any method, model, science, or validity comparison | missing |
| Terms / acceptable-use / policy | Any legal, privacy, or usage boundary | missing |
| Public help docs | Any feature availability or account-flow statement | missing |
| FermatMind authority | Any statement about FermatMind offering, free result, claim boundary, and CTA | must come from backend/CMS authority |
| Legal/brand review | Any comparative or alternative framing | missing |

## Forbidden Until Ledger Exists

- "more accurate than"
- "better than"
- "best alternative"
- "official alternative"
- "clinically validated"
- "scientifically proven superior"
- "trusted by more users"
- "free full report" as a competitor comparison unless both sides are source-backed
- "no paywall" as a competitor comparison unless both sides are source-backed
- implied endorsement, affiliation, partnership, certification, or official replacement

## Source-to-Claim Rules

1. One source should support one narrow claim.
2. Every comparative claim needs source coverage for both FermatMind and the competitor.
3. If a competitor source is missing, write `Unknown`, not an inferred comparison.
4. Schema must not contain claims that are not visibly present and source-backed.
5. Metadata must not make stronger claims than body copy.
6. Sitemap and `llms` inclusion requires separate indexability approval.

## Output Status

The source ledger is not ready for implementation. The next allowed card is `COMPETITOR-ALTERNATIVE-LEGAL-CLAIM-REVIEW-HANDOFF-01`.

## Boundary

This audit is not public copy, not a source ledger, and not implementation authorization.
