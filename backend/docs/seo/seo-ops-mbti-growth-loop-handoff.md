# MBTI Growth Loop Handoff

Task: SEO-OPS-SOP-01E

Updated: 2026-07-15

Type: documentation and operating contract only.

The initial MBTI authority and discoverability build is complete for the frozen Chinese 52-URL cohort: 32 A/T profiles, 16 A/T comparisons, and 4 hot cross-type comparisons. The authoritative technical closeout is:

- `backend/docs/seo/mbti-full-personality-authority-closeout-2026-07-15.md`

This handoff now governs evidence-led growth after the content, CMS, API, indexability, sitemap, llms, schema, and readback gates have passed. It does not authorize CMS writes, deployment, GSC mutation, pSEO expansion, Digital PR, Search Channel mutation, or work outside MBTI.

The first governed growth loop is MBTI only. Any later scale requires its own evidence, authority, review, and release gates.

The frozen handoff contract also requires:

- Baseline snapshot requirements and telemetry contract requirements remain explicit before any repair decision.
- Search -> Content -> Test -> Result -> Report -> Revenue -> Observation -> Repair -> Next Action is the complete governed path.
- Backend payment, order, and report access events are truth, while public telemetry remains observation only.
- Digital PR mention is not backlink proof.
- The MBTI test page and Digital PR HRZone/HREC state stay inside the first review cohort.
- Do not generate pSEO, do not bulk submit URLs, and do not bulk outreach.
- Next task after this PR: `SEO-OPS-SOP-01F`.

## Current state

- 52/52 assets passed the full semantic, duplication, FAQ, and internal-link gate.
- 43 repair records completed the exact draft, public-content, and discoverability authority chain.
- 9 verify-only records remained unchanged after review and are included in the same release contract.
- two consecutive release-gate runs passed 52/52 for CMS/API, HTTP, canonical, robots, JSON-LD, visible FAQ parity, sitemap, llms, and llms-full.
- API timeouts and private URL leaks were zero in the accepted release evidence.
- GSC-44 created a read-only 28-day baseline and monitoring cohort. It did not submit URLs or request indexing.

The system is no longer waiting for an initial MBTI baseline contract. The next phase is observation, query-to-page diagnosis, and narrowly scoped repair when evidence supports it.

## Growth loop

```text
Search observation
  -> query/page match review
  -> visible content and claim review
  -> test and product-path observation
  -> backend truth review
  -> scoped repair or hold decision
  -> post-change release gate
  -> next observation window
```

Search -> Content -> Test -> Result -> Report -> Revenue remains the product path, but public search telemetry and private product truth must not be merged into one dataset without explicit privacy-safe contracts.

## Baseline evidence

The GSC-44 read-only baseline covers 2026-06-16 through 2026-07-13:

| Metric | Value |
| --- | ---: |
| Clicks | 32 |
| Impressions | 3106 |
| CTR | 1.0% |
| Average position | 9.1 |
| Query rows | 106 |

The baseline had no observed page-level rows for the 52-URL cohort. Do not assign aggregate query performance to a specific profile or comparison until GSC provides page/query evidence for that URL.

Recorded review dates:

- 7-day review: 2026-07-22.
- 14-day review: 2026-07-29.
- 28-day review: 2026-08-12.

## Review questions

### 7-day review

- Are all 52 URLs still HTTP 200, canonical, indexable, and present in the authorized feeds?
- Did any API timeout, noindex shell, schema mismatch, or private URL leak recur?
- Are page-level GSC rows beginning to appear?
- Is there a P0 product, privacy, or authority regression?

### 14-day review

- Which queries map to profiles, A/T comparisons, cross-type comparisons, the MBTI hub, or the test page?
- Are impressions growing without clicks because title/description intent is mismatched?
- Do visible answer blocks and FAQ answer the actual query without overclaiming?
- Are internal links helping the user move between hub, profile, comparison, and test surfaces?

### 28-day review

- Repeat the cohort, repair selected records, or hold?
- Which changes have page-level evidence rather than aggregate query speculation?
- Did CTR, average position, and index coverage change without harming product conversion or claim boundaries?
- Is there enough evidence to propose a separate expansion scope?

## Telemetry constraints

- frontend observation events are not backend truth.
- backend payment, order, and authorized report access events are product truth but remain private.
- bot and crawler traffic must be excluded from the product conversion funnel.
- entity keys must remain independent from URL slugs.
- Digital PR mentions are not backlink proof.
- GSC and crawler data are observation, not content or identity authority.
- private result, report, attempt, history, order, checkout, and payment URLs must not enter public SEO/GEO datasets.

## Repair rules

Every repair starts from the owner of the failed boundary:

- CMS wording, FAQ, answer blocks, sections, and SEO fields: backend/CMS content task.
- public profile or comparison projection: fap-api task.
- frontend authority consumption/rendering: fap-web task, with no editorial fallback.
- sitemap/llms eligibility: backend authority plus frontend enumeration contract.
- GSC submission or URL Inspection: separate explicitly authorized operations task.

One repair PR must address one evidence-backed scope. Do not bulk rewrite the cohort because a single page or query underperforms.

## Scale guards

- Do not expand beyond the frozen 52-URL cohort without a new inventory, content authority, QA, import, verification, and discoverability gate.
- Do not turn the completed MBTI authority chain into permission for MBTI x career, Big Five, RIASEC, Enneagram, or Career pSEO.
- Do not bulk submit URLs, bulk outreach, or promise ranking/AI citation outcomes.
- Do not use hidden schema, frontend fallback content, or inferred claims to compensate for missing public evidence.
- Preserve L1 MBTI runtime priority without weakening Big Five or Enneagram contract freezes.

## Decision outputs

Each review window ends in exactly one outcome:

- `REPEAT_OBSERVATION`: evidence is insufficient; keep the cohort unchanged.
- `OPEN_SCOPED_REPAIR`: evidence identifies a bounded content, API, rendering, or feed defect.
- `HOLD_FOR_AUTHORITY`: a write, claim, permission, or data owner is missing.
- `PROPOSE_NEW_COHORT`: evidence supports a separately planned expansion with a new full authority chain.

Monitoring does not itself authorize a CMS write, deployment, indexability change, sitemap/llms mutation, GSC request, or search submission.
