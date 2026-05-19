# CMS Article Operating Workflow SOP

## Purpose

CONTENT-OPS-01 defines the safe operating workflow for CMS-managed articles.
This PR is a documentation, generated-artifact, and test PR only. It does not publish content, mutate CMS runtime state, change frontend runtime content, change sitemap or `llms.txt`, submit URLs, run collector writes, enable scheduler, deploy services, or edit environment files.

## Workflow Stages

### 1. Editorial Package

The editor prepares an article package with:

- title
- slug proposal
- locale
- summary
- body outline
- SEO intent
- claim boundary notes
- internal link targets
- media requirements
- publication owner

The package must not be treated as published content.

### 2. CMS Draft

The article is entered into CMS as draft only.

Draft rules:

- draft must not enter sitemap
- draft must not enter `llms.txt`
- draft must not enter Baidu push
- draft must not enter IndexNow
- draft must not enter 360, Sogou, or Shenma submission paths
- draft must not be treated as URL Truth indexable

### 3. Gate Checks

Before publish, run gate checks for:

- required CMS fields
- canonical URL readiness
- no private-flow links
- claim boundary safety
- internal link validity
- media ownership and alt text
- no raw PII or raw operational identifiers
- no unsupported Research or pSEO page type assumptions
- Search Channel Queue eligibility status

### 4. Controlled Publish

Publishing requires explicit owner approval in CMS.
Publishing must not automatically trigger search submissions. Search Channel Queue eligibility is evaluated separately after URL Truth and claim checks.

### 5. Post-publish Observation

After controlled publish, SEO Intelligence may observe sanitized URL Truth and issue queue state.
Observation does not mutate CMS content and does not auto-publish follow-up changes.

## Claim Boundary

Article copy must follow CLAIM-LINT-00 boundaries. It must not claim diagnosis, treatment, cure, exact IQ, guaranteed career outcomes, hiring fit, job competency, full career recommendation, or AI career planning authority.

Allowed safer wording includes non-diagnostic, for-reference-only, online estimate, confidence interval, career direction reference, exploration suggestion, interest signal, and work style tendency framing.

## Internal Link Checks

Internal links must point to canonical, backend-authoritative, public pages.
Links to drafts, private flows, checkout, payment, user reports, share flows, or noindex pages must be removed before publish.

## Stop Conditions

Stop the workflow if:

- a draft enters sitemap, `llms.txt`, Baidu, IndexNow, 360, Sogou, or Shenma
- a claim-unsafe article is approved for search submission
- frontend local content becomes article authority
- CMS runtime state is mutated by this PR
- article publication is performed by this PR
- raw PII or raw operational identifiers appear in content or metadata
- scheduler, collector writes, external APIs, or URL submissions are triggered

## Next Task

Next task: none. This completes the registered 7-PR non-production post-03D train.
