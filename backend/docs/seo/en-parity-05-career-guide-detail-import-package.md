# EN-PARITY-05 Career Guide English Detail Import Package

## Executive Summary

EN-PARITY-05 lands a repository-backed career guide detail inventory and import-readiness gate. It does not publish career guides, mutate production CMS, submit URLs, deploy, or use fap-web fallback content as authority.

The current career guide baseline contains 36 zh-CN guide rows and 36 en guide rows. The backend authority key is `guide_code`, and the repo baseline has complete EN/ZH guide-code parity. EN-PARITY-00 observed production/runtime discovery gaps for English career guide details, so the remaining work is controlled import/exposure verification rather than mass-generating English guide prose.

## Scope

- Add a generated import-readiness JSON artifact for career guide detail parity.
- Add a focused test proving EN/ZH career guide baseline detail rows have matching `guide_code` authority keys.
- Record production/content safety controls for this PR.

## Current Baseline

- English career guide rows: 36
- Chinese career guide rows: 36
- Missing English guide-code counterparts in repo baseline: 0
- Missing Chinese guide-code counterparts in repo baseline: 0

## Authority Boundary

- Backend `career_guides.guide_code` is the counterpart key.
- Frontend fallback cannot satisfy a missing career guide counterpart.
- Draft/import candidates must not enter sitemap, llms, hreflang, URL Truth, or public runtime unless backend/CMS authority is published and indexable.

## Controls

- No fap-web files changed.
- No frontend fallback used as career guide authority.
- No production CMS mutation performed.
- No production migration performed.
- No deploy performed.
- No Search Channel action or URL submission performed.
- No mass English career guide generation performed.
- No auto-publish performed.

## Deferred Work

Production/runtime may still need a controlled operator import, exposure verification, and EN/ZH URL surface validation. That must happen through backend/CMS authority and existing release gates; this PR does not mutate production.

## Validation

- `php artisan test --filter=EnParity05 --no-ansi`
- `php artisan route:list --no-ansi`
- `vendor/bin/pint --test`
- `composer validate --strict`
- `composer audit --locked --no-interaction --ignore-unreachable`
- `python3 -m json.tool backend/docs/seo/generated/en-parity-05-career-guide-detail-import-package.v1.json >/dev/null`

## Next Task

EN-PARITY-06 should build media asset / alt / og image / locale variant parity inventory and sidecar any human design work. It must not upload or mutate production media.
