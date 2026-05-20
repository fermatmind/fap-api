# Search Channel Live Readiness Split Scan

## Purpose

SEARCH-CHANNEL-LIVE-00 confirms the current Search Channel live readiness boundary and splits the next readiness contracts before any live submission work.

This scan is read-only and local-contract based. It does not submit URLs, call Google Search Console, call Baidu Resource Platform, call IndexNow, connect domestic search APIs, read production crawler logs, enable scheduler jobs, run collector writes, deploy services, edit environment files, change DNS/CDN/OpenResty/Nginx, or change sitemap/llms behavior.

## Current Readiness Findings

- `seo_urls` and `seo_url_entities` are expected to include Research URL Truth rows from the verified SEO Dash MVP state. The accepted current operational count is `seo_urls = 9`, `seo_url_entities = 9`, and `research_report rows = 2`; this scan does not re-query production databases.
- Local backend URL Truth contracts include `research_report` candidates through `BackendAuthorityUrlTruthSource` and require `backend_cms` authority, published state, indexability, and claim safety.
- Research URL Truth excludes draft, private, noindex, unapproved, unsupported-route, and claim-unsafe Research records.
- The Search Channel Queue contract exists as `SEARCH-CHANNEL-QUEUE-00` and is documented in `docs/seo/search-channel-queue-contract.md` with generated artifact `docs/seo/generated/search-channel-queue-contract.v1.json`.
- Current sitemap and `llms.txt` behavior remains gated by existing backend/runtime contracts and is not changed by this train.
- No live Search Channel operation is introduced by this scan. Existing contracts continue to record no URL submission, no live GSC activation, no Baidu push, no IndexNow submission, no scheduler enablement, no collector write, and no production crawler log read.

## Exclusion Rules Confirmed

The readiness train must exclude any URL with one or more of these states:

- draft
- private
- noindex
- unapproved
- unsupported page type
- missing canonical
- non-backend-authoritative
- claim-unsafe
- private-flow path
- query-string-only candidate

Frontend fallback, static sitemap fallback, static `llms.txt` fallback, production crawler logs, live search engine responses, Node2 local DB, and raw business DB tables must not become Search Channel URL authority.

## Split Follow-Up Tasks

1. `GSC-LIVE-00` defines Google Search Console readiness as read-only/status-check preparation only.
2. `BAIDU-LIVE-00` defines Baidu Resource Platform readiness without URL push or token exposure.
3. `INDEXNOW-LIVE-00` defines IndexNow readiness without key leakage or submission.
4. `SEARCH-CHANNEL-LIVE-01-PREFLIGHT` performs local/report-only URL eligibility preflight and no-submit channel readiness assessment.

These tasks are readiness contracts, not live submission tasks.

## What Was Not Done

- No URL submitted to Google, Baidu, IndexNow, Bing, 360, Sogou, Shenma, or any search engine.
- No indexing request made.
- No live external Search Channel API called.
- No production crawler log read.
- No scheduler enabled.
- No collector write run.
- No production env edited.
- No sitemap or `llms.txt` behavior changed.
- No Research content published.
- No pSEO created.
- No token, API key, cookie, session, email, order ID, attempt ID, payment ID, raw IP, or secret exposed.

## Final Decision

ready_for_search_channel_readiness_pr_train
