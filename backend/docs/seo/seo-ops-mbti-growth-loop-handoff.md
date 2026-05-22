# MBTI Growth Loop Handoff

Task: SEO-OPS-SOP-01E

Type: docs/generated/test only.

This contract defines the transition from SEO infrastructure mode to SEO-GROWTH-MBTI-00. It does not add runtime services, mutate CMS content, modify fap-web, mutate Search Channel Queue, send Digital PR, create pSEO, deploy, or scale beyond MBTI.

## Next Phase

Next phase: `SEO-GROWTH-MBTI-00｜Baseline Snapshot and Telemetry Contract`.

The first governed growth loop is MBTI only.

Do not scale to Big Five, RIASEC, or Career until the MBTI loop has completed baseline, telemetry, claim lint gate, Search Channel canary, Digital PR observation, human-only funnel review, and 7/14/28-day review.

## Required Handoff Artifacts

SEO-GROWTH-MBTI-00 must define:

- baseline snapshot requirements.
- telemetry contract requirements.
- entity map requirements.
- URL Truth review.
- Content/Internal Link Wave 1.
- Claim Lint gate.
- Search Channel canary wave.
- Digital PR wave.
- human-only funnel review.
- 7/14/28-day review.
- scale decision.

## MBTI Growth Loop Core Path

Search -> Content -> Test -> Result -> Report -> Revenue -> Observation -> Repair -> Next Action.

## Telemetry Constraints

- frontend observation events are not backend truth.
- backend payment, order, and report access events are truth.
- bot and crawler traffic must be excluded from the product conversion funnel.
- entity_key must be independent from URL slug.
- brand lift proxy may track unlinked mentions and branded query lift when data exists.
- Digital PR mention is not backlink proof.
- crawler and search data are observation only.

## MBTI First Experiment Scope

The first experiment must include:

- MBTI test page.
- MBTI topic/hub if available.
- MBTI research page.
- 16-type entity pages where governed.
- MBTI result/report/paywall path.
- Digital PR HRZone/HREC state.
- Search Channel canary state.
- /ops/seo review cadence.

## Scale Guards

- do not scale to Big Five, RIASEC, or Career until MBTI loop is reviewed.
- do not overclaim Big Five, RIASEC, or Career recommender depth.
- do not generate pSEO.
- do not bulk submit URLs.
- do not bulk outreach.
- do not use RIASEC, Big Five, or Career Graph as precise career recommender authority.

## Review Windows

- 7-day review: check crawl/index/referral/issue safety and no P0 regressions.
- 14-day review: check MBTI cluster content/internal link/Search Channel/Digital PR signals.
- 28-day review: make scale, repeat, repair, or hold decision.

Next task after this PR: `SEO-OPS-SOP-01F`.
