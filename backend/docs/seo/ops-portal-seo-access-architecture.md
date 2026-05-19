# Ops Portal SEO Access Architecture Contract

## Purpose

OPS-PORTAL-SEO-01 defines the architecture contract for `https://ops.fermatmind.com/ops/seo`.
This is a documentation, generated-contract, and focused-test PR only. It does not add runtime routes, deploy, edit environment files, change DNS/CDN/OpenResty/Nginx, open ports, change security groups, change RDS whitelists, operate Metabase, create dashboards, connect data sources, enable scheduler, run collector writes, call search APIs, submit URLs, read production crawler logs, publish Research, or create pSEO.

## Ownership

`ops.fermatmind.com/ops/seo` is an authenticated Ops Portal entry owned by `fap-api`.
The implementation surface is the existing Laravel Filament Ops panel mounted at `/ops`.
In short, fap-api owns `/ops/seo`.

The target route must remain an Ops Portal entry, not raw public Metabase exposure.

## Safe MVP Access Model

The safe MVP model is:

- `/ops/seo` is authenticated through the existing Ops/Admin access layer.
- The page shows SEO Dash MVP status, the dashboard name, owner assignments, and private access instructions.
- Metabase remains private on the approved Aliyun ECS host.
- Metabase listens only on `127.0.0.1:3000`.
- Metabase access uses Workbench, bastion, VPN, or another owner-controlled private channel.
- The page must not iframe Metabase.
- The page must not reverse-proxy Metabase.
- The page must not expose a public Metabase URL.
- The page must not create public sharing links or anonymous links.

## Current SEO Dash State To Display

The page may display the currently verified SEO Dash MVP state as a static documented summary:

- `seo_urls`: 7
- `seo_url_entities`: 7
- `seo_issue_queue`: 5
- datasource: `seo_intel`
- datasource account: `seo_intel_metabase_readonly`
- dashboard: `SEO Intelligence MVP - URL Truth & Issue Queue`
- dashboard cards verified: 10
- read verification: passed
- write-deny verification: passed
- public sharing: disabled
- embedding: disabled where supported
- anonymous links: absent

The page must not perform live Metabase API calls unless a later scoped PR explicitly adds that behavior under a safe contract.
The page must not query production `seo_intel` unless a later scoped PR explicitly adds a safe read-only backend pattern.

## Forbidden Architecture

The architecture contract forbids:

- public Metabase exposure
- binding Metabase to `0.0.0.0`
- opening ECS public port `3000`
- adding public IPv4 or EIP for Metabase
- changing ECS security group rules
- changing RDS whitelist rules
- DNS/CDN/OpenResty/Nginx changes in this PR
- Metabase iframe embedding
- Metabase reverse proxy in this PR
- public sharing links
- anonymous links
- public embeds
- unrestricted SQL for normal operators
- Metabase access to business DB
- Metabase access to Tencent RDS
- Metabase access to Node2 local DB
- Metabase access to CMS write tables or raw operational tables

## Operator Boundary

Normal operators must not receive unrestricted native SQL access; no unrestricted SQL is allowed for normal operators.
Datasource management remains admin-only.
Exports remain owner-controlled and must not include PII, raw evidence, raw payloads, order IDs, payment IDs, attempt IDs, cookies, tokens, raw IPs, or user agents.

## Next Task

Next task: `OPS-PORTAL-SEO-02`
