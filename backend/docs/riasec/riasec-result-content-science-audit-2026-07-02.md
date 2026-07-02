# RIASEC Result Content Science Audit - 2026-07-02

## Scope

This audit covers zh-CN backend-owned RIASEC result-page content assets for `riasec_60` and `riasec_140`. The production screenshot folder `/Users/rainie/Desktop/riasec-top3-20x3-live-20260702-043108` is treated as sampling evidence only. The repair authority is the backend content asset/source layer, not fap-web fallback copy and not production screenshot mutation.

The structured audit inventory and rule matrix live in `backend/docs/riasec/riasec-result-content-science-audit-2026-07-02.json`.

## Evidence Standard

The review uses Holland/RIASEC vocational-interest theory, the O*NET Interest Profiler materials, and Holland hexagon consistency literature as the operating boundary:

- RIASEC result copy may describe vocational-interest preferences, activity attraction, work-environment clues, and exploration questions.
- RIASEC result copy must not infer ability, skill, qualification, hiring suitability, diagnosis, personality identity, occupation match, fit score, ranking, success probability, or guaranteed career outcome.
- `riasec_140` may add task/environment/role context. It must not be described as more accurate, overriding `riasec_60`, or proving that a prior result was wrong.
- Low-consistency Holland combinations must be written as bounded tension/scene-separation clues, not as pathology, stable conflict, or capability limitation.

## Audit Findings

The pre-repair issue classes were:

- Scientific overclaim: wording that could make reading emphasis sound like validation, accuracy hierarchy, or stable conflict.
- Ability/competence boundary drift: wording that could let interest attraction be read as ability, skill, qualification, or performance evidence.
- Personality labeling: persona-like labels and identity phrasing that overfit Holland interests into "who you are".
- Career recommendation determinism: occupation examples that could be misread as recommendations, matches, rankings, or qualification guidance.
- Holland hexagon consistency gap: low-consistency pairs and mixed triads needing explicit bounded reading.
- Ordered-code runtime gap: canonical unordered Top3 assets could make different ordered codes share the same primary/secondary/tertiary emphasis.

## Repair Policy

The repair keeps one backend authority path:

- Runtime zh-CN copy is repaired in `backend/content_assets/riasec/*` and service fallback strings under `backend/app/Services/Riasec/*`.
- Test fixture mirrors under `backend/tests/Fixtures/Riasec/*` are kept aligned where those fixtures are the preflight authority.
- `backend/content_assets/riasec/result_page_v2/**` is classified separately. Release snapshots, governance files, QA reports, and agent-run artifacts are historical/governance evidence and are not rewritten as runtime copy in this PR.
- fap-web is not modified and no frontend editorial fallback is introduced.

## Ordered Top3 Requirement

The 20 unordered Top3 combinations still share one authored content asset per combination, but result-page projection now passes the measured ordered Holland code to the backend registry. The registry derives ordered primary, secondary, and tertiary reading lines from the same backend dimension vocabulary.

This means examples such as `RIA`, `RAI`, and `IRA` keep the same unordered R/I/A deep content boundary while exposing different reader-facing emphasis:

- `RIA`: R primary, I secondary, A tertiary.
- `RAI`: R primary, A secondary, I tertiary.
- `IRA`: I primary, R secondary, A tertiary.

## Review Status

Current status is content-boundary and consistency repaired, not a claim of full external scientific validation. Passing tests mean the backend assets obey the declared professional boundary rules, cover the 20x3 ordered matrix, parse cleanly, and avoid known overclaiming patterns.

Deferred items:

- A future release-snapshot PR is required if immutable `result_page_v2/releases/**` packages must be regenerated.
- Production deployment and production data mutation are out of scope.
- Human editorial sign-off can still refine tone, but must preserve the same boundary matrix.
