# SEO-DASH-06 CMS Issue Queue Summary

## Purpose
This document defines the SEO Intelligence issue queue summary foundation for CMS and Ops. The queue turns Search Intelligence observations into sanitized governance issues that humans can review. It does not publish content, edit CMS records, generate pSEO pages, or deploy runtime automation.

## Scope
This PR adds the logical `seo_issue_queue` schema, issue type/severity/lifecycle contracts, fixture-driven producer and summary services, a disabled dry-run collector, a generated artifact, and contract tests. It does not create a production `seo_intel` database, run production migrations, enable scheduler jobs, create queue workers, deploy Metabase, connect external APIs, auto-edit CMS records, or touch Node2/Node3.

No read-only HTTP route is added in this PR. The routing convention for CMS/Ops exposure remains a future activation decision; this foundation keeps summaries service-level and command-level only.

## CMS Boundary
CMS is the content authority. SEO Intelligence observes, attributes, detects drift, and records issues. CMS may display issue summaries only.

The issue queue must not:
- auto-publish CMS content
- auto-edit CMS content
- generate pSEO pages
- make draft/private/noindex URLs eligible
- treat search channels or crawler hits as SEO truth
- use crawler/search observations for purchase attribution

## Issue Lifecycle
Allowed lifecycle/status values are:
- `open`
- `acknowledged`
- `resolved`
- `ignored`

Allowed severity values are:
- `info`
- `warning`
- `high`
- `critical`

## Issue Sources
Expected sources include URL truth drift, metadata drift, sitemap/llms parity, crawler warnings, GSC changes, Baidu/IndexNow/domestic adapter statuses, landing/revenue drops, claim-boundary warnings, PII policy warnings, and internal/QA filter warnings.

## PII Boundary
Issue details must not store raw email, cookies, raw IP, raw user agent, raw order number, raw attempt id, payment id, provider event id, raw payload, payment payload, tokens, API keys, or secrets. Evidence is represented by hashes and safe aggregate metadata only.

## Metabase And CMS Read Model
Metabase remains limited to sanitized `seo_intel` aggregates. CMS/Ops can use sanitized issue summaries when a future route or panel is explicitly approved. Neither Metabase nor CMS issue summaries may query Node2 local DB, raw business DB tables, raw CMS write tables, raw orders/payments/email/event detail, or payment provider payloads.

## Next Task
If this foundation passes review, the Search Intelligence MVP foundation is complete. Next task: `SEO-DASH-MVP-COMPLETE` or explicit production activation planning.
