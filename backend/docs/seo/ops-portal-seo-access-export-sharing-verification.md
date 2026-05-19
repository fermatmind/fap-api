# Ops Portal SEO Access Export Sharing Verification Contract

## Purpose

OPS-PORTAL-SEO-05 defines the verification checklist for `/ops/seo` access and Metabase policy before any production access operation.
This PR does not deploy, edit environment files, change DNS/CDN/OpenResty/Nginx, open ports, change ECS security groups, change RDS whitelists, operate Metabase APIs, add data sources, create dashboards, enable scheduler, run collector writes, call live search APIs, submit URLs, read production crawler logs, publish Research, or create pSEO.

## Required Verification

The future production access preflight must verify:

- `/ops/seo` requires existing Ops/Admin authentication
- `/ops/seo` does not expose a public Metabase URL
- `/ops/seo` does not iframe Metabase
- `/ops/seo` does not reverse-proxy Metabase
- Metabase remains private and localhost-bound
- public sharing is disabled
- anonymous links are absent
- public embeds are disabled
- datasource count is exactly 1
- datasource name is `seo_intel`
- datasource account is `seo_intel_metabase_readonly`
- no Sample Database exists
- no business DB, Tencent RDS, Node2 local DB, CMS write tables, or raw operational sources exist
- dashboard cards use only `seo_urls`, `seo_url_entities`, and `seo_issue_queue`
- exports are owner-approved only
- no PII export is allowed
- normal operators have no unrestricted SQL
- audit, owner, and emergency revoke paths are assigned

## Safe Tables And Cards

The only approved dashboard source tables are:

- `seo_urls`
- `seo_url_entities`
- `seo_issue_queue`

The dashboard must remain the verified MVP dashboard:

`SEO Intelligence MVP - URL Truth & Issue Queue`

## Export Policy

Exports are blocked for normal operators by default.
Owner-approved exports may include only sanitized URL Truth aggregates, sanitized issue queue summaries, and safe screenshots without raw PII.

Exports must not include passwords, tokens, cookies, emails, order IDs, payment IDs, attempt IDs, raw IPs, user agents, raw payloads, provider payloads, payment payloads, raw evidence, raw crawler logs, or business DB rows.

## Next Task

Next task: `OPS-PORTAL-SEO-PROD-01`
