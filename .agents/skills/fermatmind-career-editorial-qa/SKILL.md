---
name: fermatmind-career-editorial-qa
description: Use for source, claim-boundary, wording, differentiation, and reader-quality review of newly created or explicitly authorized Career content, English translations, SEO edits, or evidence updates; not for retroactively blocking or rewriting the approved 1046 zh-CN master or authorizing publication.
---

# Career Editorial QA

Review only new Career content or content the user explicitly authorized to change. An existing approved and published 1046 zh-CN page remains visible authority unless a separate scoped change replaces it.

Read [references/review-rubric.md](references/review-rubric.md) for the claim and quality rubric. The optional `scripts/ai_trace_probe.py` is a deterministic style signal, not a truth, evidence, visibility, or release gate.

For Career Content Agent execution, apply the orchestrator's [five-gate contract](../fap-api-career-content-orchestrator/references/gates-risk-lifecycle.md): `WARN` and `BLOCKED` are terminal, and `ymyl_high` QA PASS becomes `MANUAL_REVIEW_REQUIRED`. QA never starts an automatic rewrite or authorizes the adapter, compiler, publication, or deployment.

## Review workflow

1. Lock the reviewed artifact, locale, source set, and authorized edit scope.
2. Separate sourced facts, attributed interpretations, and editorial guidance. Require traceable source identity for factual or time-sensitive claims.
3. Check that certainty matches evidence, dates and markets are explicit, and salary, employment, AI-impact, legal, medical, financial, and personality claims stay within their supported boundary.
4. Check reader usefulness, occupational specificity, duplication, clarity, tone, accessibility, links, tables, FAQ alignment, and CTA truthfulness.
5. For English translation, preserve source meaning, identifiers, component coverage, and claim strength; do not silently improve or weaken the approved source.
6. Return `PASS`, `WARN`, or `BLOCKED` with exact findings and source gaps. A QA result is editorial evidence only.

## Hard boundaries

- Do not rewrite, hide, unpublish, or make the approved 1046 zh-CN master conditional without explicit authorization.
- Do not turn claim-level evidence maturity into a visibility switch for canonical published content.
- Do not create another E-E-A-T database, content authority, publisher, release gate, or search-priority system.
- Do not infer real GSC demand from templates or invented query volumes.
- Do not publish, deploy, write CMS/database/cache state, or submit sitemap, llms, GSC, IndexNow, or other search actions.
- Do not expose private assessment answers, scores, reports, users, orders, payments, or internal provenance in reader copy.

## Output

Report reviewed files and locale, authorization scope, source/evidence coverage, blocked claims, wording and duplication findings, probe result if used, required edits, and explicit non-mutations. Route an accepted candidate back to the orchestrator or the existing Career release authority; never claim production from QA alone.
