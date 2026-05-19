# SEO Dash MVP Online Summary

## Purpose

SEO-DASH-MVP-ONLINE-SUMMARY freezes the first minimally online SEO Dash state after the Metabase localhost-only verification sequence.
This is a documentation, generated-contract, and focused-test PR only. It does not deploy, edit environment files, operate Metabase, run collector writes, enable scheduler, call external search APIs, submit URLs, read production crawler logs, publish Research, or create pSEO.

## Current Production SEO Dash State

The current `seo_intel` production observation state is:

- `seo_urls`: 7 rows
- `seo_url_entities`: 7 rows
- `seo_issue_queue`: 5 rows
- all other checked collector tables: 0 rows

Metabase is minimally online as a private observation layer:

- hosted on the approved Aliyun private ECS host
- listens only on `127.0.0.1:3000`
- has no public IPv4 exposure
- has boot enablement disabled
- uses PostgreSQL `metabase_app` as the Metabase application database
- has exactly one datasource: `seo_intel`
- uses `seo_intel_metabase_readonly` for the datasource
- has read verification passed for `seo_urls`, `seo_url_entities`, and `seo_issue_queue`
- has write-deny verification passed for the readonly datasource user

## Dashboard State

The dashboard exists:

`SEO Intelligence MVP — URL Truth & Issue Queue`

The dashboard has 10 verified cards:

- URL Truth total count
- URL entity mapping total count
- Issue Queue total count
- URL Truth by `page_entity_type`
- URL Truth by `locale`
- URL Truth by `source_authority`
- URL Truth by `indexability_state`
- Issue Queue by `issue_type`
- Private-flow / forbidden authority safety count
- Recent issue rows, sanitized

The dashboard cards may use only:

- `seo_urls`
- `seo_url_entities`
- `seo_issue_queue`

They must not use business DB tables, Tencent RDS, Node2 local DB, CMS write tables, raw orders, raw payments, raw events, raw email, raw crawler logs, provider payloads, payment payloads, raw IPs, cookies, user agents, or tokens.

## Access, Export, and Sharing State

SEO-DASH-PROD-04E verified the access/export/sharing policy:

- public sharing is disabled
- embedding is disabled where supported
- no anonymous links exist
- no public dashboard or card tokens exist
- only the admin user exists
- API key count is 0
- no exports were performed
- normal-user export and raw SQL policy is not materially active yet because no normal operator users exist
- future operator onboarding requires a separate permissions plan

## Not Yet Enabled

The following remain intentionally not enabled:

- scheduler
- collector writes
- live GSC integration
- live Baidu integration
- live IndexNow integration
- URL submission
- production crawler log reads
- Research publish
- pSEO generation
- public Metabase access
- business DB access from Metabase

## Next Phase

The next phase is:

- Research MVP
- Search Channel live readiness
- crawler log readiness

Research assets remain blocked from publish, sitemap, `llms.txt`, and Search Channel Queue until explicit backend/CMS, fap-web runtime, URL Truth, claim boundary, and Search Channel gates pass.

Next task: `METABASE-OPS-ACCESS-RUNBOOK-00`
