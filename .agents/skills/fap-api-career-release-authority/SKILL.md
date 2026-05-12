---
name: fap-api-career-release-authority
description: Use for fap-api career release authority changes involving career guides, jobs, recommendations, publication state, SEO metadata, or public career APIs.
---

## Purpose
Keep fap-api as the authority for career content, release state, and public career API contracts.

## When to use
- Use for career guide, career job, recommendation, personality profile, topic, SEO, FAQ, section, and publication-state behavior.
- Use when frontend behavior must consume backend career authority instead of local fallback content.

## When not to use
- Do not use to add frontend editorial fallback data.
- Do not use for unrelated MBTI, Big Five, or Enneagram scoring changes unless the career contract depends on them.

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

## Standard workflow
1. Identify the career surface, backend model/resource, API response, and publication-state rule.
2. Preserve slug, SEO, FAQ, section, related-content, and publication metadata contracts.
3. Avoid local frontend fallback content as a substitute for backend data.
4. Validate routes, migrations, and MBTI compatibility checks.
5. Document repository rule impact when authority or publishing behavior changes.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Report authority surface, API contract impact, migration impact, validation, and deferred content operations.

## Stop conditions
- Stop if the change moves authority to frontend files, weakens publication gates, lacks migration proof, or breaks MBTI compatibility checks.
