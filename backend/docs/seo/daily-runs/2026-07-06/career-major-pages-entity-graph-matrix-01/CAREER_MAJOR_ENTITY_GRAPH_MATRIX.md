# Career/Major Pages Entity Graph Matrix

Date: 2026-07-06
Scope: generated-only matrix for career and major public entity graph readiness

## Family Matrix

| Family | Backend authority evidence | Public graph status | Current blocker |
| --- | --- | --- | --- |
| Career jobs | `CareerJobController`, `CareerJobDetailController`, `CareerJobListController`, career job tables, occupation tables, career detail bundle builder | Partially evidenced; route and indexability behavior still needs dedicated runtime/discoverability proof | Not closed in this PR |
| Career guides | `CareerGuideController`, guide indexability state, career guide mapping tables | Partially evidenced; route inventory and sitemap/llms proof not closed | Not closed in this PR |
| Career recommendations | `CareerRecommendationController`, recommendation snapshots, context snapshots | Support surface evidenced, not standalone public graph completion | Not closed in this PR |
| Occupation authority | `occupations`, `occupation_families`, aliases, crosswalks, truth metrics, skill graphs, trust manifests, transition paths | Backend authority evidenced; public graph projection requires separate proof | Not closed in this PR |
| Major pages | Major-selection modules in topic/personality/recommendation controllers; RIASEC/gaokao cluster planning docs | Standalone major-page owner, route, CMS source, and indexability not proven | Authority ambiguous |

## Career Authority Signals

Career-side evidence is strong enough for a follow-up runtime/discoverability readback, because current repository evidence includes:

- public CMS controllers for career job list/detail and career guides;
- career detail SEO payload with indexability state;
- sitemap source filtering for runtime-published career job detail URLs;
- occupation-family and occupation authority tables;
- RIASEC profile fields on career job payloads.

This does not prove:

- all career pages that should exist are published;
- all indexable career pages are in sitemap/llms;
- no noindex career pages are discoverability-listed;
- internal links form a complete entity graph;
- claim boundary review is complete.

## Major Authority Ambiguity

Current generated evidence only supports major selection as a contextual module. It does not prove standalone major pages.

Before implementation, a separate authority design PR must decide:

- whether major pages exist as standalone public entities;
- whether they belong to backend CMS content pages, topic pages, career content, or a new backend authority model;
- whether they are indexable;
- how they connect to RIASEC, gaokao, career job, and topic pages;
- what claims are allowed for major choice guidance.

## Recommended Next Scope

Use separate PRs:

1. `CAREER-PAGES-RUNTIME-DISCOVERABILITY-READBACK-01`
   - read-only career route inventory;
   - indexability and sitemap/llms parity proof;
   - no content or route mutation.

2. `MAJOR-PAGES-PUBLIC-AUTHORITY-DESIGN-01`
   - generated-only major-page authority design;
   - route and CMS owner proposal;
   - claim boundary and indexability gate proposal.

## Guardrails

Do not use this matrix to:

- publish major pages;
- index career/major pages;
- mutate sitemap or llms;
- create public internal links;
- claim P4 completion.
