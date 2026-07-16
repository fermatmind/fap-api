---
name: fap-api-big-five-public-authority
description: Use for fap-api Big Five public content authority work involving Authority V2 assets, published revisions, media, visible evidence, Topic, structured data, discoverability, review cohorts, promotion gates, or Career bridge handoff.
---

## Purpose
Keep Big Five public editorial content, publication state, media, visible evidence, discoverability, and promotion decisions backend/CMS authoritative and fail-closed.

## Use this skill for
- Personality hub/domain/range/facet assets, Big Five Articles, Topics, test landing, methodology, or technical-trust surfaces.
- Authority V2 working/published revision selection, public API projection, media intake, visible dates/provenance, hreflang/LLMS, structured data, review cohorts, or promotion preflight.
- A Big Five public projection proposed as input to Career.

## Do not use this skill for
- Private Big Five result/report selector, scoring, route matrix, norm engine, attempt, or report payload changes unless a public-content contract explicitly depends on them.
- Frontend editorial fallback content, local publishable images, or inferred authority.
- Production publish, promotion, deploy, Media Library upload, cache/search mutation, or exact authorization unless the user separately and explicitly requests that controlled action.

## Authority split
- Private result/report runtime: Git-backed release snapshot plus import/runtime/rollout gates.
- Public Big Five editorial surfaces: fap-api CMS/public API current published projection.
- Mutable editorial media: fap-api Media Library record and approved variant.
- Career: Career runtime publish projection; Big Five is supplementary explanation only.
- `working_revision_id`, generated packages, local baselines, HTTP 200, package PASS, and record existence never prove public authority.

## Merged baseline and current holds
- PR39/40 merged withheld-route and Topic consumer repairs. A merge is not production deployment or runtime-closeout evidence.
- PR41 has an executable media intake/preflight, but approved entries remain 0 and all 693 page slots remain `missing_pending`.
- PR42/43 project visible dates and author/reviewer/source provenance fail-closed. They never synthesize missing authority evidence.
- PR44/45 gate discoverability and structured data from published, visible evidence. They do not authorize indexability or production enumeration.
- PR46 supplies EN/ZH Topic working-revision candidates and a read-only preflight. Author/reviewer/dates/media remain absent and all public gates remain false.
- PR47 supplies a read-only 231-identity cohort preflight. Its checked-in manifest is unreviewed, exact production fields are pending, and promotion-eligible assets remain 0.
- PR48 locks the operator-approved ZH6 Hub-plus-five-domain public snapshot at exact cohort/package/file hashes; the package remains non-runtime and authorizes no controlled write.
- PR49 binds that snapshot to exact `admin_user:1` solo-operator review evidence, 18 source permissions and six rollback baselines. Production has zero authority-complete `big5:model_hub:zh-CN:hero-og` candidates, so readiness is HOLD and working-revision preparation must not begin.
- PR12/13 supply the Big Five → Career schema, executable contract, and read-only auditor. They add no public reader, matcher, ranking, pSEO surface, or write path.

## Standard workflow
1. Identify the exact authority surface, canonical asset id, locale, primary record, `working_revision_id`, `published_revision_id`, and public projection.
2. Classify the requested action as read-only audit, draft preparation, authority-data intake, manual review, exact cohort authorization, promotion, deployment, or runtime closeout. Do not collapse stages.
3. Resolve public content only from the current published/public projection. Never fall back to a working revision, generated package, local baseline, or frontend copy.
4. Apply the exact gate required by the surface:
   - media: public Media Library asset/variant, locale alt, rights, license, provenance, content identity, and exact operator approval; ZH6 Hub readiness requires one and only one asset carrying verified hero and OG variants;
   - dates: canonical published/reviewed/editorial-update evidence only;
   - provenance: verified author, completed human reviewer evidence, and classified source authority;
   - discoverability/schema: public, indexable, effective, canonical, explicit CMS gates, and visible evidence;
   - Topic: canonical backend scale entry, working-revision isolation, and later published-revision selection;
   - promotion: exact identity, source/package hashes, runtime baseline, rollback target, cohort hash/count, deployed SHA, live preflight fingerprint, and exact authorization.
5. Fail closed on missing or drifting evidence. Preserve `null`, `false`, `missing_pending`, or `blocked`; do not invent authority.
6. Keep production writes and external operations out of audit/draft/preflight work. A preflight command must not acquire an implicit write mode.
7. Record repository rule impact whenever ownership, public projection, media, publish, or discoverability behavior changes.

## Career handoff
- Read `.agents/skills/fap-api-career-release-authority/SKILL.md` and the PR12/13 bridge docs.
- Accept only a published/public Big Five projection and an eligible Career runtime publish projection with matching hashes, locale, identity, and visible-evidence permissions.
- Keep `claim_mode=explanation_only`, `big_five_role=supplementary_work_style_explanation`, and RIASEC as the primary career-interest signal.
- Reject private scores, percentiles, answers, selector traces, attempt/report links, user/order/payment data, occupation ranking, hiring/screening, outcome prediction, diagnosis, and discoverability expansion.

## Required references
- `docs/big5-v2-platform-summary/big5_authority_v2_career_integration_retrospective_2026-07-15.md`
- `backend/docs/seo/personality/big-five-authority-v2/big5-authority-v2-media-authority-41/README.md`
- `backend/docs/seo/personality/big-five-authority-v2/big5-authority-v2-visible-date-42/README.md`
- `backend/docs/seo/personality/big-five-authority-v2/big5-authority-v2-visible-provenance-43/README.md`
- `backend/docs/seo/personality/big-five-authority-v2/big5-authority-v2-discoverability-parity-44/README.md`
- `backend/docs/seo/personality/big-five-authority-v2/big5-authority-v2-structured-data-45/README.md`
- `backend/docs/seo/personality/big-five-authority-v2/big5-authority-v2-topic-authority-46/README.md`
- `backend/docs/seo/personality/big-five-authority-v2/big5-authority-v2-review-promotion-gate-47/README.md`
- `backend/docs/career/big-five-career-bridge-schema-01.md`
- `backend/docs/career/big-five-career-bridge-auditor-02.md`

## Validation
- For documentation/skill-only changes: validate JSON, run the skill validator, verify referenced paths, and run `git diff --check`.
- For runtime changes: run the exact focused PHPUnit/package validators named by the affected PR contract, then the repository-required checks.
- Before any heavy full suite, apply the repository process-concurrency guard.

## Output contract
- Report the authority surface, revision source, approved/pending/blocked counts, production-write impact, discoverability impact, validation, and deferred controlled operations.
- Distinguish code/contract merged, deployed, authority data approved, promoted, and runtime-closeout verified as separate states.
- For Career handoff, also report Career projection eligibility, `claim_mode`, RIASEC priority, private-data boundary, and whether a public reader exists.

## Stop conditions
- Stop on scope drift, unisolatable user changes, failed required checks, ambiguous authority identity, working-revision leakage, fabricated visible evidence, missing exact cohort authorization, or a requested controlled production write without the required explicit authority.
- Do not claim completion when Media Library upload, human review, promotion, deployment, or runtime readback remains pending.
