# Competitor Alternative Legal Claim Review Handoff

Date: 2026-07-06

## Scope

This handoff covers held competitor Alternative page candidates:

- 16P alternative
- Truity alternative
- 123test alternative

It does not approve implementation.

## Review Checklist

| Review area | Required reviewer decision |
| --- | --- |
| Competitor naming | Approve exact names, capitalization, and route slugs |
| Source ledger | Approve every source URL, retrieved date, and claim mapping |
| Comparison framing | Approve whether "alternative" framing is allowed and under what language |
| FermatMind claims | Confirm every FermatMind claim is backed by backend/CMS public authority |
| Competitor claims | Confirm every competitor claim is backed by approved source ledger evidence |
| Superiority claims | Reject unless specifically approved with strong evidence |
| Free/paid claims | Require current source evidence for both sides |
| Methodology claims | Require source evidence and avoid overstating scientific/clinical authority |
| Legal/privacy claims | Require official policy sources and conservative wording |
| Indexability | Approve index/noindex, sitemap, `llms`, canonical, and schema policy |
| Expiry | Define source recrawl/review expiration date |

## Default Forbidden Claims

The following are forbidden unless explicitly approved:

- `FermatMind is more accurate than <competitor>`
- `best alternative`
- `official alternative`
- `clinical-grade`
- `validated replacement`
- `trusted by more users`
- `guaranteed free full result` as a competitor comparison
- implied affiliation, endorsement, certification, or partnership
- hidden schema claims not visible on-page
- unsupported claims about competitor pricing, methodology, privacy, reports, or user count

## Required Approval Record

Any future implementation PR must link a review record containing:

```json
{
  "review_id": "",
  "reviewer": "",
  "approved_competitors": [],
  "approved_routes": [],
  "approved_sources": [],
  "approved_claims": [],
  "rejected_claims": [],
  "indexability_policy": "",
  "expires_at": ""
}
```

## Stop Lines

Stop before implementation if:

- source ledger is missing or stale
- legal/brand review is incomplete
- route/indexability policy is unclear
- claim mapping does not cover both FermatMind and competitor sides
- implementation would require production import/deploy without exact SHA authorization

## Boundary

This handoff is not legal advice and not publication approval. It is an internal review checklist.
