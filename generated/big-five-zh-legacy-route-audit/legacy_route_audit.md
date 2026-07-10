# Chinese Big Five Legacy-to-V2 Route Audit

## Result

`PASS_FOR_ZH_ONLY_REDIRECT_PR`

Exactly ten Chinese Legacy routes are frozen to ten V2 targets. This PR is evidence-only: it does not alter runtime routes, CMS records, canonical output, robots, sitemap, llms, schema, search submission, or deployment.

## Frozen mappings

| Legacy slug | V2 slug | Live Legacy | Live V2 | Recommendation |
| --- | --- | --- | --- | --- |
| `high-openness` | `openness-high` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |
| `low-openness` | `openness-low` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |
| `high-conscientiousness` | `conscientiousness-high` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |
| `low-conscientiousness` | `conscientiousness-low` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |
| `high-extraversion` | `extraversion-high` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |
| `low-extraversion` | `extraversion-low` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |
| `high-agreeableness` | `agreeableness-high` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |
| `low-agreeableness` | `agreeableness-low` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |
| `high-neuroticism` | `neuroticism-high` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |
| `emotional-stability` | `neuroticism-low` | 200, noindex, self-canonical | 200, index, self-canonical | 301 Legacy → V2 |

## Evidence

- fap-web commit `25f8ec3ace9e3cb99ab2a38ba262ed0815a9d3a3`: `lib/personality/bigFivePublicRoutes.ts` accepts both Legacy and V2 slugs; the dynamic Big Five page resolves either route through the CMS-backed public content API.
- fap-api commit `659c9e5596d03cc81288c2a8e301ce3f0b16812f`: `backend/content_assets/personality_public/big_five_v1_seed.json` retains the ten Legacy zh-CN identities; `generated/big-five-cms-import-dryrun/dryrun_report.json` records the V2 trait-range identities.
- Production read-only scan at `2026-07-10T08:25:00Z`: all 20 checked URLs returned HTTP 200. Every Legacy URL emitted `noindex` and a self canonical; every V2 target emitted `index, follow` and a self canonical.

## Risk assessment

The Legacy `noindex` posture reduces direct index duplication, but it does not consolidate navigation, backlinks, analytics, crawl paths, or maintenance. The route contract still exposes two stable 200 URLs for the same trait pole concept. Risk is therefore `medium`, and the next PR should use a Chinese-only permanent redirect.

This audit does not assert byte-identical page bodies. The recommendation is based on duplicate route intent, parallel CMS identities, current canonical behavior, and the locked migration decision.

## Dependent PR contract

`BIG5-ZH-LEGACY-ROUTE-DEPRECATION-02` may implement only these ten mappings for locale `zh`. It must:

- return a permanent redirect to the exact V2 path before fetching Legacy CMS content;
- leave English Legacy routes unchanged;
- keep V2 targets as the sole canonical content pages;
- avoid new Legacy body, FAQ, media, schema, sitemap, or llms authority.

## Explicitly deferred

- Runtime redirect implementation belongs to the dependent fap-web PR.
- English Legacy routing is outside this train item.
- CMS deletion or mutation is not authorized.
- Indexability, sitemap, llms, JSON-LD, search submission, and deployment are unchanged.
