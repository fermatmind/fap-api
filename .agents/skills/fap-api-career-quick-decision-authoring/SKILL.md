---
name: fap-api-career-quick-decision-authoring
description: Author or review the source-bound FermatMind Career quick-decision visual group for a new career or an explicitly authorized update; not for generic career copy, Current writes, publication, or personality-based hiring judgments.
---

# Career Quick Decision Authoring

Create a useful answer to “Is this occupation likely to fit me?” from observable work realities. This is a module-specialist Skill inside the single Career Content Agent; it is not another Agent, authority, publisher, or release gate.

## Scope and authority

Use this Skill only for the `quick-decision` visual group, which renders:

- `fermat_decision_card.{title,summary,caveat}`;
- `fit_decision_checklist.{suit,boundary,how}`.

Before authoring, lock the canonical slug, locale, market, jurisdiction, research date, authorized content scope, and existing Current row/shard hashes through `fap-api-career-content-orchestrator`. Read [references/authoring-contract.md](references/authoring-contract.md) for field semantics and ownership. A frontend visual group is not a new Current module: `fit_decision_checklist` is owned by `fit-personality`, while `fermat_decision_card` is a derived projection. Never write either component directly into Current.

## Research and reasoning

Use `fap-api-career-content-research-producer` and its source/evidence contracts. Read [references/source-routing.md](references/source-routing.md) before collecting evidence. Browse current official sources at research time; do not fill inaccessible or missing evidence from model memory.

Build the decision from this chain:

```text
official tasks + work context + responsibilities
    -> recurring demands and tradeoffs
    -> conditional fit signals and caution conditions
    -> one low-risk, occupation-specific work sample
```

Do not reverse the chain by starting from a personality label, an SEO keyword, a salary number, or a reusable prose template. RIASEC, Big Five, and Work Styles may help explain patterns, but they never decide that a person will succeed, fail, be employable, or belong in an occupation.

## Authoring workflow

1. Extract the occupation's recurring tasks, decisions, evidence standards, work setting, collaboration pattern, pace, physical/cognitive demands, and regulated responsibilities. Distinguish the named occupation from adjacent roles and preserve combined-occupation scope.
2. Create an evidence-to-guidance map. Bind factual premises to source keys; label the suitability conclusion and work-sample design as `editorial_synthesis` or `conditional_guidance`.
3. Draft all six fields as one argument. The summary states the core work bargain; `suit` explains observable behaviors and tolerable conditions; `boundary` names realistic tradeoffs without judging human worth; `how` gives a small simulation with an observable result and reflection questions.
4. Apply the SEO/GEO and reader-quality checks in [references/quality-rubric.md](references/quality-rubric.md). Search-query evidence may prioritize wording or questions but is not occupational evidence.
5. Compare the draft with nearby occupations and the active batch. Preserve shared facts where real, but rewrite any interchangeable conclusion, caution, or experiment until it is occupation-specific.
6. Send the source-bound candidate to `fermatmind-career-editorial-qa`. `WARN`, `BLOCKED`, missing evidence, or high-risk manual review stops the execution; never auto-rewrite until PASS.

## Output

Return the locked identity and dimensions, six candidate fields, evidence keys for every factual premise, explicit editorial transformations, unresolved claims, differentiation findings, and QA handoff. State that repository, Current, CMS/database/cache, publisher, deploy, and discoverability writes are zero.

Do not create `agents/openai.yaml` for this Skill. The repository has one controlled Career Content Agent profile, owned by the orchestrator.
