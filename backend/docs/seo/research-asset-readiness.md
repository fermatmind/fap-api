# Research Asset Readiness Scan and Contract

## Purpose

PR-RESEARCH-00 defines the readiness contract for a future FermatMind Research Asset surface after URL Truth, issue queue, and dashboard boundaries have become observable.
This PR does not implement a Research runtime route, CMS model, sitemap entry, `llms.txt` entry, search submission, content publication, or pSEO generation.

## Route Recommendation

Use `/research` as the future public Research Asset hub and report path family.

Reserve `/reports` for user/product report flows and paid or private result/report experiences. Research pages must not be mixed into private report IA because Research Assets are public editorial/methodology assets, while product reports may contain user-specific or commerce-adjacent flows.

Proposed future public paths:

- `/research`
- `/research/{research_slug}`
- `/research/{research_slug}/methodology`

No route is added in this PR.

## Proposed URL Truth Page Type

Future Research pages should use:

- `page_entity_type`: `research_report`
- source authority: backend/CMS authority only
- indexability: indexable only after publication, claim review, and URL Truth support

`research_report` must not enter sitemap, `llms.txt`, search channel queues, or dashboard URL Truth counts until the backend/CMS source, fap-web runtime, and SEO contract are implemented and observed by URL Truth.

## Backend/CMS Field Requirements

A later backend/CMS MVP should define Research Assets with fields similar to:

- title
- slug
- locale
- summary
- methodology summary
- evidence grade
- publication state
- publish date
- review owner
- claim boundary status
- canonical URL
- page entity type
- indexability state
- related scale or content references
- source citations or citation metadata

Research content must be CMS/backend-authoritative. Frontend local files, hardcoded editorial copy, static fallback content, and ad hoc JSON must not become the source of truth.

## fap-web Runtime Requirements

A later fap-web MVP may render Research pages only after backend/CMS contracts exist.

Runtime requirements:

- fetch public Research Asset data from backend/CMS APIs
- render empty or unavailable states when the API does not provide a published asset
- avoid local editorial fallback content
- avoid local sitemap or `llms.txt` enumeration
- keep `/reports` private/product report semantics separate from `/research`
- preserve noindex for drafts and unreviewed assets

No fap-web runtime file is changed in this PR.

## SEO / GEO / Search Channel Eligibility

A Research Asset becomes eligible for sitemap, `llms.txt`, and Search Channel Queue only when all gates pass:

- backend/CMS source exists
- published state is explicit
- URL Truth supports `research_report`
- canonical URL is present
- indexability is explicit and indexable
- claim boundary review is passed
- no private-flow or user-specific data exists
- no raw PII or raw evidence is exposed
- Search Channel Queue contract accepts the page type

Drafts, claim-unsafe assets, noindex assets, private-flow assets, and unobserved page types must be excluded.

## Claim Boundary

Research copy must not expand FermatMind claim boundaries. It must not claim diagnosis, treatment, cure, full career recommendation, hiring fit, job competency, exact IQ, guaranteed career outcome, or AI career planning authority.

Allowed safer framing includes:

- self-assessment
- non-diagnostic
- for reference only
- online estimate
- confidence interval
- career direction reference
- exploration suggestion
- interest signal
- work style tendency
- snapshot-based support

RIASEC, Big Five, and Career Decision semantics remain shallow/partial assets unless a separate approved runtime and claims review expands them.

## Stop Conditions

Stop before any Research implementation if:

- `research_report` enters sitemap or `llms.txt` before URL Truth support
- a Research page is published without CMS/backend authority
- local frontend content becomes editorial authority
- Research content includes raw PII, raw evidence, private-flow URLs, or user-specific report data
- claim copy expands into diagnosis, treatment, career recommendation, hiring, or job-fit guarantees
- Search Channel Queue submits or queues draft/noindex/claim-unsafe Research URLs
- pSEO generation is introduced

## Next Task

Next task: SEARCH-CHANNEL-QUEUE-00.
