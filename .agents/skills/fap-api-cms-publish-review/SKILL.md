---
name: fap-api-cms-publish-review
description: Use for fap-api CMS publishing review involving articles, landing surfaces, page blocks, content pages, media references, SEO fields, or editorial publication workflows.
---

## Purpose
Protect CMS publishing authority, editorial review gates, and public content API contracts in fap-api.

## When to use
- Use for articles, article SEO, covers, categories, tags, landing surfaces, page blocks, content pages, and media metadata.
- Use when a change affects how CMS content becomes public.

## When not to use
- Do not use to create runtime frontend editorial content.
- Do not use for product-only interactive assets that are explicitly not CMS-governed.

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
- CMS and Media Library metadata remain the source of truth for publishable content.

## Standard workflow
1. Identify the CMS resource, publication state, media reference, SEO fields, and public API projection.
2. Preserve draft/review/published gates and do not expose unpublished content.
3. Require Media Library references for mutable editorial and SEO images.
4. Validate routes, migrations, and relevant CMS resource tests when available.
5. Include repository rule impact for ownership or publishing workflow changes.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Always report changed files, acceptance commands run, PR URL if a PR was created, CI status, Deploy Application or deploy/runtime status when relevant, merge commit if merged, branch cleanup status when cleanup is requested, revalidation status for security-related work, stop reason when blocked, and confirmation that no unrelated files were touched.
- Report CMS resource, publication gate, media handling, public API impact, checks, and deferred editorial tasks.

## Stop conditions
- Stop if active Critical/High/Medium appears during Low/Informational work, required checks fail, Deploy Application or deploy/runtime status regresses where relevant, the worktree is dirty in a way that cannot be isolated, scope drift appears, product/runtime behavior is ambiguous, closure would lack source/test evidence, or production deploy/rollback is requested without explicit manual confirmation.
- Stop if unpublished content can leak, frontend fallback content is introduced, media authority is bypassed, or migrations fail.
