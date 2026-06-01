# CAREER-DIRECTORY-AUTHORITY-ARTIFACT-API-01 Report

## Executive Summary

This PR adds a backend-authoritative, lightweight career directory API for the 10k-scale career roadmap.

The new endpoint is:

- `GET /api/v0.5/career/directory`

It is intentionally separate from the existing full career jobs index:

- Existing contract preserved: `GET /api/v0.5/career/jobs`
- New contract added for paginated directory shells and search/filter UI

## Scope

Implemented only PR1 in the approved five-PR train:

1. `CAREER-DIRECTORY-AUTHORITY-ARTIFACT-API-01`

No sitemap, llms, frontend pagination shell, ops warm, Search Channel, deployment, CMS mutation, or runtime promotion changes were made.

## Implementation

Added `CareerDirectoryAuthorityService`, which consumes backend public career authority through `PublicCareerAuthorityResponseCache::jobIndexPayload()`, then emits a smaller directory contract:

- `authority_version`
- `bundle_kind`
- `public_truth`
- `pagination`
- `filters`
- `facets.families`
- `items`

Each item contains only directory-card fields:

- slug
- localized title
- EN/ZH titles
- family slug/title
- canonical path
- indexability state
- robots policy
- `indexable`
- `detail_ready`
- `updated_at`

## Authority Boundary

The new directory API does not create career content and does not use frontend fallback content.

It filters from backend runtime/public authority and excludes non-public or noindex records. The following held/conflict slugs remain excluded:

- `software-developers`
- `digital-forensics-analysts`
- `computer-occupations-all-other`

## Safety Boundaries

No production write was performed.

No deploy was performed.

No Search Channel action was performed.

No URL submission or external search API call was performed.

No career cohort, runtime projection, sitemap, llms, or footer exposure was changed.

## Validation

Required validation for this PR:

- `php artisan test --filter=CareerDirectoryAuthorityApiTest --no-ansi`
- `php artisan route:list --no-ansi`
- `vendor/bin/pint --test`
- `composer validate --strict`
- `composer audit --locked --no-interaction --ignore-unreachable`
- JSON/YAML parse checks
- `git diff --check`
- `git diff --cached --check`

## Final Decision

`career_directory_authority_artifact_api_completed_ready_for_sitemap_authority_alignment`

## Next Task

`CAREER-SITEMAP-EXPOSURE-DIRECTORY-AUTHORITY-01`
