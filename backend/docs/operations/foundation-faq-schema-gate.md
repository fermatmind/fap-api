# Foundation FAQ Schema Gate

Date: 2026-06-05

PR train item: `FOUNDATION-FAQ-SCHEMA-GATE-01`

Mode: documentation, generated artifact, and contract test only. This PR does not create FAQ answers, JSON-LD runtime output, CMS records, DailyGiving records, proof files, public copy, search submissions, or deploys.

## Decision

Foundation FAQ schema remains blocked.

Reason: the current content request card permits FAQ question inventory only. A question inventory is not visible CMS-approved FAQ answer content and must not unlock `FAQPage` JSON-LD.

## Gate Rules

| Gate | Required before FAQPage schema | Current state |
| --- | --- | --- |
| visible FAQ answers | visible, CMS/backend-authoritative answers exist on the public Foundation page | not created by this train |
| CMS approval | FAQ answers are reviewed and approved in CMS/backend authority | not created by this train |
| claim lint | answers pass Foundation claim boundary lint | pending future content |
| DailyGiving boundary | FAQ does not imply public ledger, stable operation, trust badge, official partnership, endorsement, certification, or guaranteed impact | required |
| schema source | schema mirrors visible approved FAQ content | blocked |

## Allowed GPT Output

GPT may produce:

- FAQ question inventory.
- Risk tags for each question.
- Required evidence per question.
- Reviewer checklist.
- Codex validation handoff.

GPT must not produce:

- final FAQ answers;
- final `FAQPage` JSON-LD;
- final H1, metadata, CTA, social copy, or trust badge copy;
- official partnership, endorsement, certification, guaranteed impact, or stable DailyGiving operation claims.

## Codex Validation Rule

Codex should treat Foundation FAQ schema as disabled unless all conditions pass:

1. visible CMS/backend-authoritative FAQ answers exist;
2. the visible answers are approved;
3. schema entries match the visible answers;
4. claim lint passes;
5. DailyGiving remains noindex while gated;
6. DailyGiving records/proof state is not inferred from private or unknown data.

## Explicit Non-Goals

- No public FAQ answers.
- No JSON-LD runtime implementation.
- No CMS mutation.
- No publish.
- No search submission.
- No deploy.
