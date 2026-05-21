# OPS-SEO-NATIVE-DASH-04 Permission Audit and No-export Verification

## Purpose

This PR verifies the native `/ops/seo` dashboard access boundary after the read model, UI, and detail panels are in place.

The dashboard remains a read-only Ops observability surface. It does not add runtime permission code, export controls, raw SQL controls, queue mutation controls, Metabase exposure, production operations, or external search calls.

## Access Boundary

- Unauthenticated users redirect to `/ops/login`.
- `admin.owner` can access `/ops/seo`.
- `admin.ops.read` can access `/ops/seo`.
- Unrelated admin permissions, including `admin.content.read`, are denied.
- No public dashboard route is added outside the Filament Ops panel.

## No-export / No-SQL Boundary

- No export controls.
- No default exports.
- No raw SQL controls.
- No unrestricted SQL editor.
- No datasource management control for operators.

## Metabase Boundary

- No Metabase iframe.
- No Metabase reverse proxy.
- No public Metabase URL.
- No anonymous sharing links.
- No public embeds.
- No Metabase token, URL, or embed secret display.

## Operator Action Boundary

- No queue approval, retry, or submit controls.
- No search submission controls.
- No scheduler or collector controls.
- No production crawler log reads.
- No live GSC, Baidu, IndexNow, 360, Sogou, or Shenma calls.

## Audit Boundary

Expected future audit events remain scoped to access and policy decisions:

- `ops_seo_page_access`
- `permission_change`
- `export_approval_decision`
- `emergency_revoke_action`

Audit payloads must not include raw IPs, user agents, cookies, tokens, emails, order IDs, payment IDs, attempt IDs, provider payloads, crawler log details, or Metabase secrets.

Next task: `CRAWLER-LOG-OBSERVABILITY-TRAIN-01`
