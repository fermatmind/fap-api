# Search Channel Queue Design Contract

## Purpose

SEARCH-CHANNEL-QUEUE-00 defines the non-production contract for a future Search Channel Queue.
This PR does not connect live GSC, Baidu, IndexNow, 360, Sogou, or Shenma APIs. It does not submit URLs, add credentials, edit environment files, enable scheduler, run collector writes, deploy services, or change sitemap/llms behavior.

## Channel Scope

The queue may later coordinate readiness and submission workflow for:

- Google / GSC readiness
- Baidu push readiness
- IndexNow readiness
- 360 readiness
- Sogou readiness
- Shenma readiness

Every live channel action requires a later approval gate. This contract only defines eligibility and exclusion rules.

## Required Source Authority

Queue eligibility must start from backend-authoritative URL Truth and CMS/public backend state.

Allowed authority inputs:

- `seo_urls`
- `seo_url_entities`
- future sanitized Search Channel Queue records
- backend/CMS publication state
- backend URL Truth indexability state
- backend claim boundary status

Forbidden authority inputs:

- frontend fallback
- static sitemap fallback
- static `llms.txt` fallback
- Node2 local DB
- Node2 local Laravel
- live search engine responses as page truth
- production crawler logs
- raw business DB tables

## Eligibility Rules

A URL may be queued only when all required gates pass:

- source authority is backend-approved
- canonical URL is present
- publication state is published
- indexability state is indexable
- page type is supported by URL Truth
- no private-flow path is present
- no query-string-only URL is submitted
- claim boundary review is safe
- channel-specific credential and quota readiness are approved in a later operation

Draft, private, noindex, claim-unsafe, unsupported page type, missing canonical, and non-backend-authoritative URLs must be excluded.

## Channel Rules

### Google / GSC

GSC remains read/verification readiness only until a later explicit approval. It is not purchase truth and must not become URL Truth.

### Baidu

Baidu push must use a queue record and must exclude draft, private, noindex, claim-unsafe, and non-backend-authoritative URLs. Token readiness is a sidecar and must not block docs work.

### IndexNow

IndexNow submissions must be queued only for indexable backend-authoritative URLs. IndexNow key readiness is a sidecar and is not introduced in this PR.

### 360 / Sogou / Shenma

Domestic adapters must not create alternate pages for domestic engines. They may only submit eligible canonical URLs after a later approval gate.

## Required Queue Record Shape

A future queue record should store only sanitized workflow fields:

- canonical URL hash or masked display path
- locale
- page entity type
- source authority
- indexability state
- channel
- eligibility status
- exclusion reason
- dry-run status
- submission status
- last checked date

Queue records must not store raw credentials, raw payloads, cookies, raw IPs, raw user agents, raw order/payment identifiers, or private report identifiers.

## Stop Conditions

Stop immediately if:

- a live external API call is attempted
- a URL submission is attempted
- scheduler or queue workers are enabled
- credentials or env files are edited
- a draft, private, noindex, or claim-unsafe URL becomes eligible
- frontend/static fallback becomes URL authority
- Node2 local DB or production crawler logs are used
- Search Channel Queue creates alternate domestic pages

## Next Task

Next task: CRAWLER-LOG-00.
