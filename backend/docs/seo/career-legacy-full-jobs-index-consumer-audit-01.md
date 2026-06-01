# CAREER-LEGACY-FULL-JOBS-INDEX-CONSUMER-AUDIT-01

## 1. Executive Summary

This report audits remaining consumers of the legacy public full jobs index endpoint:

`GET /api/v0.5/career/jobs`

The endpoint is still valid as a legacy compatibility surface, but it returns the full public career job collection and should not be used for 10k-scale directory, sitemap, llms, or frontend entry-page rendering. The preferred 10k path is the backend career directory authority endpoint and authority artifacts.

## 2. Backend Consumer Findings

- Public route still exists: `backend/routes/api.php` maps `/career/jobs` to `CareerJobListController@index`.
- The controller path is `backend/app/Http/Controllers/API/V0_5/Career/CareerJobListController.php`.
- Release and validation commands still use public job counts as compatibility checks, not as the long-term directory authority.
- Some import/validation console commands contain production public jobs API constants for validation/import workflows.

## 3. Frontend Reference Findings

Reference-only scan of `fap-web` found that the main `/career/jobs` page has already moved away from `fetchCareerJobIndex`, but full-index consumers remain:

- `lib/career/api/fetchCareerJobIndex.ts`
- `app/(localized)/[locale]/career/industries/page.tsx`
- `app/(localized)/[locale]/career/industries/[slug]/page.tsx`
- several contracts that mock or assert the legacy path for compatibility.

These are not changed in this backend PR.

## 4. Risk Classification

- P1: industry pages still consume the full index and can become a 10k-scale SSR/render cost.
- P1: the legacy endpoint contract can be accidentally reused by future frontend work.
- P2: backend validation scripts still mention the legacy public jobs API, but current use is operational verification rather than public page rendering.

No P0 exposure was found because sitemap/llms career URL exposure already uses authority-oriented paths and held slugs remain excluded.

## 5. Migration Plan

1. Keep `/api/v0.5/career/jobs` stable as legacy compatibility until all known consumers are migrated.
2. Move industry pages to the directory authority endpoint or a bounded family/facet authority endpoint.
3. Add a drift gate proving sitemap/llms/directory/detail counts agree and held slugs remain absent.
4. After consumers are migrated, consider response budget hardening or deprecation documentation for the full jobs index.

## 6. Safety Boundaries

No backend runtime behavior, DB state, CMS state, career cohort, sitemap, llms, Search Channel, URL submission, or frontend code was changed.

## 7. Validation

Required local validation:

- `cd backend && php artisan test --filter=CareerLegacyFullJobsIndexConsumerAudit01 --no-ansi`
- `cd backend && php artisan route:list --no-ansi`
- `cd backend && vendor/bin/pint --test`
- `cd backend && composer validate --strict`
- `cd backend && composer audit --locked --no-interaction --ignore-unreachable`
- JSON/YAML parse checks
- `git diff --check`

## 8. Final Decision

`career_legacy_full_jobs_index_consumer_audit_completed_ready_for_directory_authority_drift_gate`

## 9. Next Task

`CAREER-DIRECTORY-AUTHORITY-DRIFT-GATE-01`
