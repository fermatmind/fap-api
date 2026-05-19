# Metabase Read-only Connection Plan

## Purpose

SEO-DASH-PROD-04A defines the non-production plan for a future Metabase read-only connection to the Search Intelligence database.
This PR is a contract and readiness artifact only. It does not deploy Metabase, add credentials, create database users, open network access, edit environment files, or run production operations.

## Current Production Context

The preceding controlled canaries established a small sanitized `seo_intel` base:

- `seo_urls`: 7 rows
- `seo_url_entities`: 7 rows
- `seo_issue_queue`: 5 rows
- other checked collector tables: 0 rows

The current safe sources are backend-approved URL Truth rows and bounded drift issue rows. No dashboard may infer broader SEO truth from frontend fallback, static sitemap files, static `llms.txt`, Node2 local sources, live search adapters, crawler logs, or business raw tables.

## Allowed Connection Boundary

Metabase may connect only to `seo_intel` through a dedicated read-only database user.

The planned read-only user boundary is:

- database scope: `seo_intel`
- privileges: `SELECT` only
- write privileges: none
- DDL privileges: none
- grant privileges: none
- connection purpose: sanitized Search Intelligence reporting

Metabase must not connect to:

- business DB
- CMS write tables
- Node2 local DB
- Node2 local Laravel runtime
- raw order, payment, event, email, report, user, attempt, or provider event tables
- Tencent production business RDS
- crawler log files or raw crawler log tables

## Forbidden Data Exposure

Metabase dashboards and future sanitized views must not expose raw PII or raw operational identifiers, including:

- email or raw email
- order number or raw order number
- attempt ID or raw attempt ID
- payment ID
- provider event ID
- cookie or raw cookie
- raw IP
- raw user agent
- raw payload, payment payload, or provider payload
- token, API key, or secret

Aggregates, counts, dates, booleans, enums, masked labels, hashes, and safe status fields are allowed when they come from `seo_intel` tables and do not include raw evidence.

## Initial Read-only Scope

The first connection should expose only sanitized Search Intelligence reporting surfaces:

- URL Truth counts and distributions
- URL/entity mapping coverage
- source authority distribution
- locale, page entity type, and indexability distributions
- sanitized issue queue counts
- forbidden source authority checks
- private-flow and non-indexable counts
- empty-state panels for collectors that have not passed a write canary

The plan must not create business DB views or point Metabase at raw commerce, CMS, payment, report, user, or email tables.

## Ownership

- Dashboard owner: SEO Intelligence / Data owner
- Database access owner: backend production DBA or infrastructure owner
- Access control owner: operations owner responsible for Metabase users, groups, and permissions
- Safety reviewer: backend owner for `seo_intel` source authority and PII boundaries

Before any production Metabase connection is created, the access owner must confirm the connection uses the read-only user and can access only `seo_intel`.

## Deployment Boundary

This PR does not:

- deploy Metabase
- create a Metabase connection
- add credentials
- edit `.env`
- create or alter database users
- change RDS, DNS, CDN, whitelist, VPC, or firewall rules
- run collector writes
- enable scheduler or queue workers
- connect live GSC, Baidu, IndexNow, 360, Sogou, or Shenma APIs
- submit URLs to search engines
- read production crawler logs
- publish Research content
- generate pSEO pages

## Stop Conditions

Stop before connection setup if any of these are observed:

- Metabase attempts to connect to a business database.
- Metabase can read CMS write tables or raw order/payment/event/email/user/report tables.
- The planned user has write, DDL, grant, or migration privileges.
- A dashboard exposes raw PII, raw evidence, raw payloads, or private-flow URLs.
- Node2 local DB or frontend/static fallback appears as a data source.
- A live search API, production crawler log, scheduler, or URL submission is triggered.

## Next Task

Next task: SEO-DASH-PROD-04B.
