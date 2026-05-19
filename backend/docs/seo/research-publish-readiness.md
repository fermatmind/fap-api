# Research Publish Readiness Checklist

## Purpose

RESEARCH-PUBLISH-READINESS-00 defines the first Research publish readiness contract. It does not publish Research content, add sitemap entries, add `llms.txt` entries, insert Search Channel Queue records, submit URLs, run collector writes, enable scheduler, create pSEO, or start Digital PR.

## First Candidate

- candidate: MBTI Salary & Turnover Report
- status: Research Candidate Frozen Draft
- page entity type: `research_report`
- intended authority: backend/CMS Research Asset
- intended public route family: `/research/{slug}`

The candidate remains a frozen draft. It is not public, indexable, queued, submitted, or promoted by this PR.

## Publish Prerequisites

The first Research publish operation is blocked until all prerequisites pass:

- Research backend/CMS MVP is merged and available.
- fap-web Research runtime MVP is merged and renders backend payloads only.
- Research SEO/GEO/Search Channel contract is merged.
- URL Truth observation supports `research_report`.
- Metabase dashboard is visible to the owner through private access.
- Claim linter passes for the candidate.
- Methodology is present.
- Sample disclaimer is present.
- References are present.
- Author and reviewer are present.
- `last_reviewed_at` is present.
- Downloadable asset decision is explicit.
- Dataset schema decision is explicit and remains blocked unless a versioned public downloadable asset is approved.
- Sitemap eligibility gate passes in a later PR.
- `llms.txt` eligibility gate passes in a later PR.
- Search Channel Queue eligibility gate passes in a later PR.

## Candidate Content Requirements

The candidate must include:

- executive summary
- methodology
- sample disclaimer
- claim boundary statement
- author
- reviewer
- references
- last reviewed date
- downloadable asset placeholder or versioned downloadable asset decision

The candidate must not include raw PII, raw orders, raw payments, raw events, raw emails, raw crawler logs, cookies, raw IPs, provider payloads, payment payloads, or user-specific private report evidence.

## Claim Boundary

The candidate must not claim diagnosis, treatment, cure, hiring fit, job competency, exact IQ, guaranteed salary, guaranteed turnover prediction, guaranteed career outcome, or AI career planning authority.

RIASEC, Big Five, and career decision copy must remain bounded to research context, self-assessment, methodology, and reference-oriented wording. This readiness contract does not expand those claim boundaries.

## Publication Gate

A later Research publish operation must verify:

- CMS record is approved.
- CMS record is explicitly published.
- CMS record is public.
- CMS record is indexable.
- canonical path is present.
- locale is explicit.
- fap-web route returns backend payload only.
- URL Truth records the Research URL.
- SEO/GEO/Search Channel gates remain satisfied.
- no private/noindex/draft state enters sitemap, `llms.txt`, Search Channel Queue, or URL submission.

## What Was Not Done

- No Research content published.
- No Research content imported.
- No sitemap behavior changed.
- No `llms.txt` behavior changed.
- No Search Channel Queue insertion performed.
- No URL submitted to search engines.
- No live GSC, Baidu, IndexNow, 360, Sogou, or Shenma API connected.
- No Digital PR started.
- No scheduler or collector write enabled.
- No production crawler logs read.
- No Metabase operation performed.
- No production env, deploy, RDS, DB user, whitelist, DNS, CDN, or OpenResty change performed.
- No pSEO created.

## Next Task

Next task: SEO-DASH-MVP-ONLINE-SUMMARY closeout report / next Research publish operation planning.
