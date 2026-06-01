# CAREER-DIRECTORY-AUTHORITY-DRIFT-GATE-01

## 1. Executive Summary

This gate records the current Career directory/detail/sitemap/llms alignment
contract for the 1046 public career-detail rollout and the next 10k-scale
architecture work.

The backend authority contract is:

- career directory public detail count: `1046`
- public detail indexable count: `1046`
- localized EN/ZH career detail URL count: `2092`
- sitemap career detail URL count: `2092`
- llms career detail URL count expectation: `2092`
- held slugs remain absent from directory, sitemap, llms, and runtime exposure.

No runtime, cohort, sitemap, llms, CMS, DB, Search Channel, URL submission, or
deployment change is made by this PR.

## 2. Drift Gate Rules

The generated gate artifact requires:

1. `directory_public_detail_count == public_detail_indexable_count`
2. `sitemap_career_detail_url_count == directory_public_detail_count * locale_count`
3. `llms_career_detail_url_count_expected == sitemap_career_detail_url_count`
4. held slugs are absent:
   - `software-developers`
   - `digital-forensics-analysts`
   - `computer-occupations-all-other`
5. no Search Channel, URL submission, CMS/DB mutation, deployment, or frontend
   fallback authority is used.

## 3. Risk Coverage

This gate is intended to catch these regressions before a future 10k rollout:

- directory count diverges from public detail indexability truth;
- sitemap continues to expose a stale or independently derived career set;
- llms count no longer matches sitemap career URLs;
- held slugs leak into discoverability surfaces;
- a frontend fallback or runtime fanout path is treated as authority.

## 4. Validation

Required local validation:

- `cd backend && php artisan test --filter=CareerDirectoryAuthorityDriftGate01 --no-ansi`
- `cd backend && php artisan route:list --no-ansi`
- `cd backend && vendor/bin/pint --test`
- `cd backend && vendor/bin/pint --test tests/Feature/SeoIntel/CareerDirectoryAuthorityDriftGate01Test.php`
- `cd backend && composer validate --strict`
- `cd backend && composer audit --locked --no-interaction --ignore-unreachable`
- JSON/YAML parse checks
- `git diff --check`

## 5. Final Decision

`career_directory_authority_drift_gate_completed_ready_for_llms_full_10k_budget_gate`

## 6. Next Task

`CAREER-LLMS-FULL-10K-BUDGET-GATE-01`
