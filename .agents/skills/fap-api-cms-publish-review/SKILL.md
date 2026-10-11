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
- Deliver ordinary changes through current AGENTS.md, isolated worktrees, path-limited commits and direct main push; bind applicable exact-SHA CI and automatic deployment evidence.
- Do not close security findings unless source and test evidence prove they are fixed.
- Stop if active Critical, High, or Medium findings appear during Low or Informational work.
- Do not weaken previously fixed security boundaries.
- Use the current path classifier and four workflows; retired required-check names, PR gates and Deploy Application are historical evidence only.
- Resolve field ownership from current AGENTS.md and the resource contract: CMS, manifest-bound Current files and their projections are distinct; Media Library owns publishable media metadata.
- Frontend output, sitemap presence, llms presence, or JSON-LD presence never substitutes for CMS/backend authority.
- Private result, report, attempt, recovery, history, share-token, order, checkout, and payment URLs must never enter public feeds or public content evidence.

## Authority matrix

| Concern | Authority | Required review |
| --- | --- | --- |
| Editorial body, answer blocks, FAQ, sections | Resource-specific current CMS or manifest-bound file authority | content boundary, duplication, claim safety, visible completeness |
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
5. Bind the reviewed exact package to source hash, current source/group/revision/version, scope and count. Read staging and production pre-state separately, including missing SEO, published and working pointers, and manual holds. A translation-provider gap does not prevent review of an existing translation; missing publication capability belongs to the engineering owner.
6. Follow the resource-specific classifier-selected automatic exact-package lane under the current user authorization. Keep draft, public content and discoverability controls distinct; ordinary safe delivery does not add PRs, dispatches or chat approvals. A generic deploy is not authority to publish an unselected package or broaden discoverability.
7. Read back CMS state and public APIs. Validate fields and parity, not only HTTP status.
8. Let the existing automatic lane perform its selected bounded cache/reader work, then verify actual consumers. Before a new publication path is delivered, cover conflict rejection, transactional rollback and restore→new-owner publication without overwriting immutable history or working drafts.
9. Validate canonical, robots, JSON-LD, FAQ parity, sitemap, llms, llms-full, and private URL exclusions when discoverability is in scope.
10. Keep GSC submission, URL Inspection, indexing requests, and search submission in a separate explicitly authorized task.
11. Include a Repository rule impact note when ownership, publishing, public API, media, SEO, or feed behavior changes.

## Historical MBTI command boundaries

The following records describe existing command-specific controls; they are not the ordinary manual delivery path. Use them only when the explicit task targets that command/cohort and current authority confirms it. Do not reuse historical hashes or add per-stage chat approvals to an already authorized automatic lane.

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
- Bind dry-run to the applicable command revision and current environment pre-state; existing continuous authorization covers its selected automatic lane, while an unselected package or scope needs clarification.
- If any hash, slug, locale, section key, record count, or pre-state differs, reject the whole batch.
- Do not partially apply a fail-closed batch unless the command and approval artifact explicitly define partial behavior.
- A prior approval is not reusable after package, command revision, data pre-state, or scope changes.
- Software activation and content publication are separate authorities. The existing exact-package lane may perform both only when its classifier, package and current user scope bind the operation; neither implies search mutation.

## MBTI readback and warmup

For a task explicitly targeting the historical MBTI command, use its existing bounded warm operation only within that authorization. Ordinary releases use the classifier-selected workflow operation; do not run a manual warm merely to validate this Skill.

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

Run focused success, conflict, failure and restore/republication tests for the touched command/service/controller; use production-relevant database engine/query/JSON semantics when that boundary changes. Confirm failure injection reaches the intended business transaction and preserves public state and drafts. Object-key reordering may be equivalent; scalar types, values, list order, FAQ answers and deletion are not.

Run route:list only for changed route wiring, isolated migrations only for schema work, and the full MBTI chain only for an affected high-risk boundary or explicit request; routine heavy regression belongs to Nightly. Run the relevant formatting/static checks and git diff --check. Documentation/Skill-only delivery requires CI and deploy-skip, with no database commands or application deployment.

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
- exact commit SHA, CI and deploy-skip or applicable staging/production evidence, plus task worktree cleanup. Report a PR only when explicitly requested.
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
- the selected package or mutation is outside the current authorization. Safe ordinary automatic delivery remains continuously authorized; search, destructive or permission changes retain their separate boundaries.
