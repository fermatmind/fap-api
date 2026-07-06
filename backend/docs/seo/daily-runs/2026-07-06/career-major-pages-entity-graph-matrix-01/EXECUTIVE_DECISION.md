# CAREER-MAJOR-PAGES-ENTITY-GRAPH-MATRIX-01

Date: 2026-07-06
Repository: fermatmind/fap-api
Scope: generated docs only

## Decision

Final verdict: BLOCKED_BY_AUTHORITY_AMBIGUITY

Career job/detail authority is present in backend APIs and CMS services, but the combined Career/Major public entity graph cannot be declared complete because standalone major-page authority is not proven in current evidence.

## Evidence Summary

- Career job APIs and CMS controllers exist for career job list/detail, guide, recommendation, and detail bundle surfaces.
- Career job detail SEO contracts include indexability state and `noindex,follow` gating when not eligible.
- Career jobs connect to occupation authority tables and RIASEC profile fields.
- Major-selection text appears as support modules in personality, topic, and career recommendation surfaces.
- Current evidence does not prove canonical standalone major pages, major route owners, major CMS authority, or indexability policy.

## Boundary

This PR is a read-only matrix and blocker report. It does not create or expose career/major pages, change CMS state, change route lists, change sitemap/llms, or decide indexability.

## Deferred Items

- No career or major route creation or modification.
- No CMS write/import/publish.
- No sitemap, llms, schema, canonical, noindex, Search, GSC/GA, fap-web, or deploy changes.
- No claim that P4 career/major graph is complete.
