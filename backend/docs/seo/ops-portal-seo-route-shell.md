# Ops Portal SEO Route Shell

## Purpose

OPS-PORTAL-SEO-03 adds the authenticated `/ops/seo` route shell inside the existing fap-api Filament Ops panel.
The shell is status and runbook only. It does not deploy, edit environment files, change DNS/CDN/OpenResty/Nginx, open ports, change security groups, change RDS whitelists, operate Metabase, add data sources, create dashboards, enable scheduler, run collector writes, call live search APIs, submit URLs, read production crawler logs, publish Research, create pSEO, change sitemap, or change `llms.txt`.

## Route

The route shell is:

- path: `/ops/seo`
- owner: fap-api
- surface: Filament Ops panel
- auth: existing Ops/Admin auth
- access: `admin.owner` or `admin.ops.read`
- content mode: static status and private access runbook

## Displayed Status

The page displays the verified SEO Dash MVP state:

- `seo_urls`: 7
- `seo_url_entities`: 7
- `seo_issue_queue`: 5
- dashboard: `SEO Intelligence MVP - URL Truth & Issue Queue`
- verified cards: 10
- datasource: `seo_intel`
- datasource account: `seo_intel_metabase_readonly`

This status is static documentation. The page does not query production `seo_intel` and does not call the Metabase API.

## Private Metabase Boundary

Metabase remains private:

- localhost-bound on the approved private ECS host
- no iframe
- no reverse proxy
- no public URL
- no public sharing
- no anonymous links
- no public embeds
- no public port
- no DNS/CDN/OpenResty/Nginx change

Access remains through Workbench, bastion, VPN, or another approved owner-controlled private channel.

## Next Task

Next task: `OPS-PORTAL-SEO-04`
