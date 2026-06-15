# Preflight And Scope

## Scope

- Repair Big Five V1 backend-authoritative content assets.
- Remove internal wording risk reported by UX review 02.
- Reduce duplicate/template risk across domain and polarity pages.
- Preserve noindex, sitemap, and llms exclusion.
- Add focused regression coverage for duplicate risk.

## Non-Goals

- No frontend runtime changes.
- No MBTI, Enneagram, result-page, scoring, PDF, or private report changes.
- No production import or CMS write.
- No publish/indexability change.
- No sitemap/llms changes.

## Input Evidence

- fap-web UX Review 02 reported 34/34 live pages and API assets healthy but found 9 internal wording hits and 110 duplicate-risk pairs.
- Current fap-api seed already had no direct internal wording hits, so this repair treats UX Review 02 as production/live evidence and current seed as backend authority to strengthen before the next import.
