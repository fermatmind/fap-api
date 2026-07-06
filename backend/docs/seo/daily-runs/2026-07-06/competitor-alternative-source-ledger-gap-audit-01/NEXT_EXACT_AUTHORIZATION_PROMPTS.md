# Next Exact Authorization Prompts

## Legal Claim Review Handoff

Authorize `COMPETITOR-ALTERNATIVE-LEGAL-CLAIM-REVIEW-HANDOFF-01` as a generated-only handoff.

Allowed scope:

- Summarize source ledger gaps.
- Create legal/brand claim review checklist.
- Keep implementation held.

Forbidden:

- public copy
- routes
- CMS writes
- comparative claims
- schema
- sitemap or `llms`
- metadata
- canonical/noindex changes
- frontend code
- production import or deploy

## Future Source Capture

Use only after legal/claim handoff:

```text
Authorize COMPETITOR-ALTERNATIVE-SOURCE-CAPTURE-READONLY-01.

Allowed scope:
- capture exact approved source URLs
- record retrieved_at
- map one source to one narrow claim
- produce generated-only ledger draft

Forbidden:
- public page implementation
- copy generation for publication
- claims not directly supported by approved sources
- sitemap/llms/schema/metadata changes
```
