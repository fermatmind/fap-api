---
name: fap-api-cms-publish-review
description: Use for fap-api CMS publishing review involving articles, landing surfaces, page blocks, content pages, personality profiles or comparisons, media references, SEO fields, editorial publication, and discoverability gates.
---

## Purpose

Protect CMS publishing authority, editorial review gates, public content API contracts, and the separation between content publication and search discoverability in fap-api.

## When to use

- Use for articles, article SEO, covers, categories, tags, landing surfaces, page blocks, content pages, and media metadata.
- Use for public personality profiles and comparisons when content, FAQ, SEO, JSON-LD, indexability, sitemap eligibility, llms eligibility, or public read models are involved.
- Use when a change affects how reviewed CMS content becomes draft, public, indexable, or enumerable.

## When not to use

- Do not use to create runtime frontend editorial content.
- Do not use for product-only interactive assets that are explicitly not CMS-governed.
- Do not treat this skill as authorization for production writes, deployment, GSC submission, URL Inspection, or search submission.

## Hard invariants

- Do not modify unrelated files or stage unrelated dirty files.
- Do not process Informational findings unless explicitly requested.
- Do not expose exploit-ready details in public PR titles or bodies.
- Do not merge unless required checks pass and scope is clean.
- Do not close security findings unless source and test evidence prove they are fixed.
- Stop if active Critical, High, or Medium findings appear during Low or Informational work.
- Do not weaken previously fixed security boundaries.
- Required checks for fap-api are hygiene, verify-mbti-v2, and verify-mbti-legacy.
- Deploy Application must remain green for deploy or runtime-impacting PRs.
- CMS and Media Library metadata remain the source of truth for publishable content.
- Frontend output, sitemap presence, llms presence, or JSON-LD presence never substitutes for CMS/backend authority.
- Private result, report, attempt, recovery, history, share-token, order, checkout, and payment URLs must never enter public feeds or public content evidence.

## Authority matrix

| Concern | Authority | Required review |
| --- | --- | --- |
| Editorial body, answer blocks, FAQ, sections | CMS/backend content records | content boundary, duplication, claim safety, visible completeness |
| SEO title, description, canonical, robots | backend SEO/public read model | canonical and robots coherence; no local consumer invention |
| JSON-LD | backend structured-data projection | visible FAQ parity, canonical parity, supported schema only |
| Public profile/comparison API | fap-api read models | publication, locale, effective indexability, payload bounds |
| Mutable images | Media Library/public media metadata | public URL, publication state, no private path leakage |
| Sitemap and llms eligibility | backend indexability/feed authority | separately released discoverability state |
| GSC and URL Inspection | explicitly authorized search operation | separate task after release gate passes |

## Standard workflow

1. Identify the CMS resource, locale, publication state, media references, SEO fields, structured data, public API projection, and discoverability state.
2. Establish the exact inventory and classify each record as repair, verify-only, or excluded. Do not manufacture changes for verify-only records.
3. Validate content and claims before preparing an import package. For personality assets, include semantic quality, duplicate risk, FAQ parity, internal links, and framework boundaries.
4. Run the resource-specific dry-run planner. A dry-run must be deterministic, write-free, and fail closed on schema, slug, locale, record count, or hash mismatch.
5. Build an exact approval artifact. Production options must come from that artifact and explicit operator authorization; never infer them from a filename, branch, latest `main`, a prior task, or chat history alone.
6. Execute draft import, public content promotion, and discoverability promotion as separate controlled stages. A completed stage does not authorize the next stage.
7. Read back CMS state and public APIs. Validate fields and parity, not only HTTP status.
8. Warm bounded public read models when the published surface requires it, then repeat readback.
9. Validate canonical, robots, JSON-LD, FAQ parity, sitemap, llms, llms-full, and private URL exclusions when discoverability is in scope.
10. Keep GSC submission, URL Inspection, indexing requests, and search submission in a separate explicitly authorized task.
11. Include a Repository rule impact note when ownership, publishing, public API, media, SEO, or feed behavior changes.

## MBTI personality publication gates

MBTI publication uses three independent write gates.

### Gate 1: draft import

`personality:mbti-full-cms-import` stages the exact reviewed repair records as draft revisions.

- Dry-run is the default operating mode.
- Write mode requires exact source and authorization hashes, exact scope, exact record count, and explicit production import authorization.
- Write mode must keep indexability, sitemap, llms, and search release unchanged.

### Gate 2: public content promotion

`personality:mbti-full-cms-promote` makes the exact reviewed drafts public.

- It requires a fresh dry-run promotion package and authorization payload.
- It may publish content only.
- It must not change indexability, sitemap, llms, or search state.

### Gate 3: discoverability promotion

`personality:mbti-full-indexability-promote` releases the exact approved records to indexability, sitemap, and llms.

- It requires the exact approved pre-state, package hashes, scope, record count, and explicit production promotion authorization.
- It must explicitly retain `no-gsc`, `no-url-inspection`, and `no-search-submission` boundaries.
- GSC is never implied by a successful discoverability promotion.

For the completed Chinese 52-URL MBTI cohort and its historical batch evidence, read `backend/docs/seo/mbti-full-personality-authority-closeout-2026-07-15.md`. Do not copy historical hashes from that document into a new production command.

## Exact authorization rules

- A production write must bind to one reviewed package, one expected source hash, one authorization payload hash, one scope mode, and one record count.
- Public and discoverability promotion additionally bind to their current dry-run promotion package hash.
- Run dry-run against the deployed command revision and current production pre-state before requesting write authorization.
- If any hash, slug, locale, section key, record count, or pre-state differs, reject the whole batch.
- Do not partially apply a fail-closed batch unless the command and approval artifact explicitly define partial behavior.
- A prior approval is not reusable after package, command revision, data pre-state, or scope changes.
- A deployment approval is not a CMS/database write approval, and a CMS write approval is not a deployment approval.

## MBTI readback and warmup

After profile publication or read-model changes:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan personality:warm-public-read-models --types=<COMMA_SEPARATED_TYPES> --locales=zh-CN
```

Then validate the public detail and SEO APIs for every affected profile. For comparisons, validate comparison detail/index read models. Required evidence includes:

- expected locale, slug, and entity type.
- answer block, FAQ, sections, and internal links where required by the package.
- effective indexability and final robots state.
- canonical and SEO field coherence.
- backend JSON-LD and visible FAQ parity.
- bounded payload and stable warm reads.
- sitemap/llms state only when the discoverability gate is explicitly in scope.

The completed full MBTI cohort contains 32 profiles and 20 comparisons. A repair task may validate a smaller exact cohort, but it must not infer or release additional URLs.

## Acceptance tiers

### Documentation or reusable runbook only

```bash
cd /Users/rainie/Desktop/GitHub/fap-api
git diff --check
```

Also validate referenced paths, command names, and changed-file scope. Do not run database or production commands merely to validate documentation.

### CMS planner, importer, promotion, or public read-model change

Run focused tests for the changed command/service/controller first. Then run the repository-required MBTI verification:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list --path=api --except-vendor --no-ansi

cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/ci_verify_mbti.sh
git diff --check
```

Run migrations only when migration/schema behavior is in scope, using an isolated test database. Do not create a migration requirement for docs-only or read-model-only work.

Before starting a heavy full test, obey the repository concurrency guard and confirm no other FermatMind PHPUnit, Composer, or verify suite is already running.

## Output contract

Always report:

- added and modified files.
- CMS resource and authority owner.
- exact stage: dry-run, draft import, public promotion, discoverability promotion, readback, or monitoring.
- package scope and record counts without exposing secrets.
- acceptance commands and results.
- public API, media, SEO/schema, and feed impact.
- whether production write, deploy, or GSC operations were executed; default is no.
- PR number, CI status, merge commit, and branch cleanup when applicable.
- deferred editorial or operational tasks.
- confirmation that no unrelated files were touched.

## Stop conditions

Stop if:

- active Critical, High, or Medium findings appear during lower-severity work.
- required checks fail or runtime/deploy status regresses where relevant.
- the worktree cannot isolate the requested scope.
- package authority, locale, slug mapping, pre-state, or production authorization is ambiguous.
- unpublished content can leak, frontend fallback content is introduced, media authority is bypassed, or migrations fail.
- the requested action would combine draft import, public promotion, discoverability promotion, deployment, or GSC mutation without their separate controls.
- a production/CMS/database write, deployment, or search mutation is requested without its exact explicit authorization.
