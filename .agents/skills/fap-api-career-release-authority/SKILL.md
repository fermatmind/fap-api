---
name: fap-api-career-release-authority
description: Use for fap-api career release authority changes involving career guides, jobs, recommendations, personality-to-career bridges, publication/revision state, SEO metadata, or public career APIs.
---

## Purpose
Keep fap-api as the authority for career content, release state, and public career API contracts.

## When to use
- Use for career guide, career job, recommendation, personality profile, topic, SEO, FAQ, section, and publication-state behavior.
- Use when Big Five, MBTI, Enneagram, or RIASEC content is proposed as an input to a Career surface.
- Use when frontend behavior must consume backend career authority instead of local fallback content.

## When not to use
- Do not use to add frontend editorial fallback data.
- Do not use for unrelated MBTI, Big Five, or Enneagram scoring changes unless the career contract depends on them.
- Do not use to turn a personality framework into a deterministic matcher, hiring screen, outcome predictor, or occupation ranking authority.

## Hard invariants
- Do not modify unrelated files.
- Do not stage unrelated dirty files.
- Do not process Informational findings unless explicitly requested.
- Do not expose exploit-ready details in public PR titles/bodies.
- Do not merge unless required checks pass and scope is clean.
- Do not close security findings unless source/test evidence proves fixed.
- Stop if active Critical/High/Medium appears during Low/Informational work.
- Do not weaken previously fixed security boundaries.
- Required checks for fap-api are hygiene, verify-mbti-v2, and verify-mbti-legacy.
- Deploy Application must remain green for deploy or runtime-impacting PRs.
- Career content and publication state must remain backend-authoritative.
- A primary record, package PASS, successful import, route 200, or populated `working_revision_id` does not prove public eligibility.
- Career readers may consume only the published/public projection. Never select `working_revision_id`, draft snapshots, generated packages, or local baselines as runtime authority.
- When both pointers exist, preserve `published_revision_id` as public truth; a new working revision is editorial state only.
- RIASEC remains the primary career-interest signal. Big Five and MBTI are supplementary work-style/preference language, not occupational proof.
- Big Five-to-Career output is `explanation_only`/exploration support. It must not claim best career, guaranteed fit, hiring, promotion, income, placement, health, or success outcomes.
- Private answers, score vectors, percentiles, selector traces, attempt/report URLs, order/payment data, and user identifiers must not enter public Career projections.

## Standard workflow
1. Identify the Career surface, backend model/resource, API response, publication-state rule, and owning runtime publish projection.
2. If a personality bridge is involved, classify each input as public published projection, working/draft revision, private result data, or generated candidate. Only the first class may reach a public Career reader.
3. Require locale, authority identity, `published_revision_id`/public projection, visible-evidence permissions, and Career runtime publish eligibility to agree. Fail closed on any mismatch.
4. Preserve slug, SEO, FAQ, section, related-content, claim-permission, and publication metadata contracts.
5. Keep RIASEC/interest evidence, work activities, values, and personality/work-style signals distinct in both storage and reader copy.
6. Avoid local frontend fallback content as a substitute for backend data.
7. Validate routes, migrations, focused publication/revision behavior, and MBTI compatibility checks.
8. Document repository rule impact when authority, revision selection, or publishing behavior changes.

## Big Five × Career release checklist
- Confirm the Big Five source is a backend public API projection of a published revision.
- Confirm no imported Authority V2 working/draft revision is selected implicitly.
- Confirm the occupation is allowed by Career runtime publish projection.
- Confirm Big Five language is supplementary, value-neutral, non-diagnostic, and non-deterministic.
- Confirm output contains reflection signals or environment questions, not a job-fit score or objective ranking.
- Confirm missing/disabled/unpublished inputs produce no fallback editorial copy.
- Confirm no indexability, sitemap, LLMS, schema, media, cache, or search mutation is bundled without its separately authorized gate.

## Required reference for Big Five bridges
- Read `docs/big5-v2-platform-summary/big5_authority_v2_career_integration_retrospective_2026-07-15.md` before changing a Big Five-to-Career adapter, public projection, importer, promotion gate, or release workflow.
- Use `backend/docs/career/contracts/big-five-career-bridge-input.v1.schema.json` and `backend/docs/career/contracts/big-five-career-bridge-output.v1.schema.json` as the machine-readable bridge boundary.
- Apply `App\Domain\Career\Bridge\BigFiveCareerBridgeContract` as the executable fail-closed gate: select exactly the published Big Five revision from the backend public projection, require all visible-evidence permissions, bind both public projection hashes, and lock the output to the exact published Career runtime projection.
- Only `published_projection_ready` with zero blockers may reach a future public reader; every other state resolves to `blocked` with `public_reader_allowed=false`.
- Keep RIASEC primary and Big Five supplementary under `claim_mode=explanation_only`; ranking, hiring/screening, outcome prediction, diagnosis, pSEO, and private assessment/user/order data must remain absent.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Always report changed files, acceptance commands run, PR URL if a PR was created, CI status, Deploy Application or deploy/runtime status when relevant, merge commit if merged, branch cleanup status when cleanup is requested, revalidation status for security-related work, stop reason when blocked, and confirmation that no unrelated files were touched.
- Report authority surface, API contract impact, migration impact, validation, and deferred content operations.
- For personality-to-career work, also report revision source (`published` vs `working/draft`), Career projection eligibility, claim boundary, private-data boundary, and whether discoverability changed.

## Stop conditions
- Stop if active Critical/High/Medium appears during Low/Informational work, required checks fail, Deploy Application or deploy/runtime status regresses where relevant, the worktree is dirty in a way that cannot be isolated, scope drift appears, product/runtime behavior is ambiguous, closure would lack source/test evidence, or production deploy/rollback is requested without explicit manual confirmation.
- Stop if the change moves authority to frontend files, weakens publication gates, lacks migration proof, or breaks MBTI compatibility checks.
- Stop if a Career consumer would read a working/draft personality revision, infer publication from record existence or HTTP 200, rank occupations from Big Five alone, or expose private assessment data.
