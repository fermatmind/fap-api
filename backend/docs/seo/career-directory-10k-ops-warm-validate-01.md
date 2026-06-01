# CAREER-DIRECTORY-10K-OPS-WARM-VALIDATE-01

## Executive Summary

This PR adds an operations gate for the career directory 10k-scale architecture. The runtime contract remains unchanged: career detail authority stays backend-owned, sitemap exposure continues to use backend directory authority, and no production cohort or CMS state is mutated.

## Implementation

- Warm public Career authority job-index caches for both `en` and `zh-CN`.
- Add `career:validate-directory-10k-scale-readiness` as a read-only validation command.
- Validate public directory count parity, sitemap career detail URL count parity, held slug exclusion, first-page payload budget, and synthetic 10k bilingual URL budget.

## Safety Boundaries

- No DB mutation.
- No CMS mutation.
- No runtime promotion.
- No sitemap, llms, footer, or Search Channel exposure changes.
- Held slugs remain excluded:
  - `software-developers`
  - `digital-forensics-analysts`
  - `computer-occupations-all-other`

## Validation Plan

- `php artisan test --filter=CareerDirectory10kOpsWarmValidateCommandTest --no-ansi`
- `php artisan test --filter=CareerWarmPublicAuthorityCacheCommandTest --no-ansi`
- `php artisan route:list --no-ansi`
- `vendor/bin/pint --test`
- `composer validate --strict`
- `composer audit --locked --no-interaction --ignore-unreachable`

## Final Decision

`career_directory_10k_ops_warm_validate_ready_for_pr`
