# Fermat SEO Ops

Status: draft internal operator skill.

This document adapts upstream `seo-audit`, `ai-seo`, `schema`, `site-architecture`, `analytics`, `cro`, and related marketing skill patterns into FermatMind SEO Ops guardrails. It is not an installed skill, not runtime code, and not approval to change CMS, publish content, enqueue Search Channel records, submit URLs, generate pSEO, read raw logs, or deploy.

## Authority Rules

- Backend/CMS/URL Truth is authority.
- Public runtime is observation only.
- `fap-web` fallback is not authority.
- Sitemap and `llms.txt` are discoverability outputs, not truth.
- Search Channel requires exact human approval.
- Claim safety is a gate, not a suggestion.

## Daily SEO Ops Checklist

1. Open the SEO Ops dashboard or generated runbook artifacts.
2. Check URL Truth freshness, `seo_urls`, `seo_url_entities`, and source authority counts.
3. Review P0/P1 issue queue items only for same-day action.
4. Check sitemap and `llms.txt` parity against backend/CMS eligibility.
5. Check canonical and hreflang drift on core public assets.
6. Check Search Channel Queue for approval, eligibility, and exclusion states.
7. Confirm live gates are closed unless an exact scoped approval phrase exists.
8. Check staging containment and hard-404 exposure sidecars.
9. Check claim lint blocked and needs-review counts.
10. Check internal link readiness and orphan/entity gaps.
11. Check crawler aggregate observations only; do not read raw crawler logs.
12. Check MBTI first 7-day growth loop status when active.
13. Record action owners and tomorrow's top three actions.

## URL Truth Checks

Confirm each reviewed URL has:

- backend/CMS authority.
- canonical URL.
- entity type.
- locale.
- indexability state.
- claim safety state.
- supported page family.
- no private-flow path.
- no query-only variant.

Do not use public HTML, sitemap, `llms.txt`, search engine response, crawler log, Digital PR mention, or frontend route existence as URL Truth.

## Sitemap and llms Checks

For sitemap and `llms.txt`:

- include only backend-authoritative, indexable, canonical, claim-safe public URLs.
- exclude draft/private/noindex/unsupported/hard-404 URLs.
- exclude career jobs or guides that are not approved by backend release gates.
- require truthful lastmod where present.
- treat hard-404 exposure as a P0 discoverability issue.

Do not fix sitemap/llms by creating placeholder pages.

## Canonical and Hreflang Checks

Check that canonical and hreflang:

- come from backend/CMS URL Truth or an approved deterministic contract.
- do not invent unpublished locale alternates.
- do not use slug parity as proof of locale counterpart.
- do not point to draft, private, noindex, unsupported, or claim-unsafe pages.

## Search Channel Queue Checks

Search Channel eligibility requires:

- backend-authoritative canonical URL.
- published and indexable state.
- claim-safe state.
- supported page family.
- no private-flow path.
- no frontend/static fallback authority.
- exact scoped human approval before enqueue or live submit.

Exact rule: Do not submit Search Channel URLs unless exact approval phrase is present.

Forbidden without approval:

- GSC/Google action.
- Baidu push.
- IndexNow.
- Bing.
- 360.
- Sogou.
- Shenma.
- any live external search API call.

## Live Gate Checks

Confirm gates are closed after any canary:

- Search Channel live gate.
- crawler log production canary gate.
- scheduler gate.
- collector write gate.
- Digital PR send gate.
- CMS publish gate.

Open gates after a scoped task are P0 unless explicitly documented and still in the approved operation window.

## Staging Containment Checks

For Baidu stale staging sidecars:

- classify as sidecar unless current task introduced it.
- do not create placeholder pages.
- do not submit new URLs.
- verify staging host is excluded from canonical Search Channel eligibility.
- record owner and required cleanup path.

For hard-404 exposure:

- treat as discoverability drift.
- verify URL Truth state, sitemap state, llms state, and runtime response.
- remove exposure through authority/discoverability rules, not content stubs.

## Claim Lint Checks

Review blocked and needs-review counts for:

- clinical/diagnostic claims.
- career/hiring/salary/success claims.
- RIASEC, Big Five, MBTI overclaim.
- Research/salary/turnover overclaim.
- result/report/paywall pressure copy.

Do not auto-rewrite. Create a review queue item or docs-only finding unless a scoped implementation PR is authorized.

## Issue Queue Checks

Escalate by severity:

- P0: public/indexable claim-unsafe page, private-flow leak, Search Channel unsafe URL, Metabase exposure, raw log persistence, frontend fallback as authority.
- P1: core page metadata/canonical/hreflang drift, missing locale pair on core assets, Search Channel approved item stuck.
- P2: content rehearsal blocker, internal link gap, non-core metadata or lastmod issue.
- P3: trend observation, minor metadata drift, dormant URL, Digital PR no-response.

## Internal Link Readiness Checks

Internal links should be grounded in backend entity graph or approved CMS relationships:

- article -> test/topic/report preview/related article.
- topic -> test/article/career/personality where safe.
- test -> related article/result/report.
- result -> report.
- research asset -> methodology/topic/test where safe.

Do not auto-create links from public runtime scraping or frontend fallback.

## Crawler Observation Boundaries

- Crawler aggregates are observation only.
- Raw production logs require exact scoped approval.
- Crawler logs do not establish URL Truth.
- Do not store raw IPs, cookies, user agents, order ids, emails, or private report ids in SEO artifacts.

## First 7-day MBTI Ops Cadence

Day 0:

- Confirm URL Truth, sitemap/llms, canonical, hreflang, JSON-LD/FAQ, claim safety, and Search Channel eligibility.

Days 1-2:

- Observe indexability and Search Channel status.
- Review article/test/result funnel attribution.
- Do not react to incomplete GSC data.

Days 3-4:

- Review early search/landing signals, CTA click, test start, submit, result view, unlock click, and purchase.
- Add issue queue items for gaps.

Days 5-7:

- Review content/internal-link opportunities.
- Prepare human-reviewed Digital PR or Research follow-up if approved.
- Do not scale pSEO or mass content.

## Research Claim-sensitive Pages

Research pages must have:

- methodology.
- sample/disclaimer boundary.
- references.
- author/reviewer/editorial policy where available.
- aggregate-level framing.
- no individual prediction claims.
- no salary/turnover causality overclaim.
- Search Channel eligibility only after claim review.

## Explicit No-go Rules

- Do not submit Search Channel URLs unless exact approval phrase is present.
- Do not use sitemap/llms as truth.
- Do not treat frontend fallback as authority.
- Do not fix SEO by creating placeholder pages.
- Do not publish draft/import packages.
- Do not create pSEO while P0 discoverability is dirty.
- Do not mutate CMS from an SEO Ops review.
- Do not deploy from an SEO Ops checklist.
