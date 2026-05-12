---
name: fap-api-ops-tenant-boundary
description: Use for fap-api operational and tenant-boundary work involving admin APIs, tenant isolation, internal tools, audit logs, or privileged data access.
---

## Purpose
Preserve tenant isolation and privileged access controls across fap-api operational surfaces.

## When to use
- Use for admin, ops, internal, audit, tenant, organization, or privileged read/write behavior.
- Use when a change could expose data across tenants or roles.

## When not to use
- Do not use for public content rendering that has no tenant or privileged boundary.
- Do not use to add bypasses for support convenience.

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

## Standard workflow
1. Map the actor, tenant, role, data owner, and allowed operation.
2. Verify route middleware, policies, query scoping, serializers, logs, and jobs preserve that boundary.
3. Avoid fallback queries that remove tenant or role filters.
4. Keep audit behavior intact for privileged changes.
5. Run common acceptance commands and focused boundary tests when available.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Report actor model, tenant boundary, changed files, checks, audit impact, and unresolved risks.

## Stop conditions
- Stop if tenant ownership is ambiguous, privileged access expands, audit evidence is missing, or scope crosses into unrelated ops flows.
