# Metabase Ops Access Runbook

## Purpose

METABASE-OPS-ACCESS-RUNBOOK-00 defines the operational access policy for the private SEO Dash Metabase instance.
This is a documentation, generated-contract, and focused-test PR only. It does not operate Metabase, deploy, edit environment files, open public access, change DNS/CDN/OpenResty, modify RDS whitelist, connect databases, run collector writes, enable scheduler, or publish Research content.

## Access Model

Metabase remains a private operations tool:

- host: approved Aliyun private ECS
- public IPv4: none
- Metabase bind address: `127.0.0.1`
- Metabase port: `3000`
- boot enablement: disabled
- public sharing: disabled
- anonymous links: disabled
- public embedding: disabled
- datasource boundary: `seo_intel` only
- datasource account: `seo_intel_metabase_readonly`

Owners must access Metabase through a safe private path such as an approved console session, Workbench-local forwarding, bastion, VPN, or another explicitly approved private ops channel. The runbook does not authorize public security-group ingress, public DNS exposure, CDN exposure, or anonymous/public share links.

## Owner Access Procedure

Before access:

1. Confirm the target host is the approved Aliyun private Metabase ECS.
2. Confirm the ECS has no public IPv4 or EIP.
3. Confirm security group inbound rules have not been opened for public Metabase access.
4. Confirm Metabase is still bound only to `127.0.0.1:3000`.
5. Confirm the datasource count is exactly one and the datasource is `seo_intel`.

Access must use a private owner-controlled path to `http://127.0.0.1:3000`.
Do not create public dashboard links, public card links, anonymous links, public embeds, or a public reverse proxy as part of routine access.

## Service Operations

Allowed service inspection commands on the approved ECS:

```bash
systemctl status metabase --no-pager
systemctl is-active metabase
systemctl is-enabled metabase
ss -lntp | grep ':3000' || true
curl -sS http://127.0.0.1:3000/api/health
```

Allowed controlled service commands:

```bash
systemctl start metabase
systemctl stop metabase
systemctl restart metabase
```

Boot policy:

```bash
systemctl disable metabase
```

Metabase should remain boot-disabled until a separate production operations approval explicitly changes that policy.

## Emergency Revoke

Use emergency revoke if any of the following occurs:

- Metabase binds to `0.0.0.0`
- ECS public IPv4 or EIP appears
- security group opens public inbound access
- public sharing or embedding is enabled
- an unexpected datasource appears
- a normal operator user is added without a permissions plan
- any business DB, Tencent RDS, Node2 local DB, CMS write table, or raw operational datasource appears

Emergency revoke sequence:

1. Stop Metabase: `systemctl stop metabase`
2. Keep boot disabled: `systemctl disable metabase`
3. Confirm `ss -lntp | grep ':3000' || true` shows no public listener.
4. Remove any public/anonymous dashboard links or embeds through localhost admin access if Metabase remains safe to access.
5. Rotate affected Metabase admin and database credentials if exposure is suspected.
6. Reconfirm no RDS public endpoint or broad whitelist was introduced.

Do not broaden whitelists or open public ports during revoke.

## Password Rotation

Password rotation must remain owner-controlled and must not expose secrets in docs, logs, PRs, screenshots, or command output.

Rotation policy:

- rotate Metabase admin credentials through localhost-only admin access
- rotate `metabase_app_user` only through the approved PostgreSQL RDS control plane
- rotate `seo_intel_metabase_readonly` only through the approved MySQL RDS control plane
- update local secret storage on the private ECS without printing secret values
- restart Metabase only after verifying it remains bound to `127.0.0.1:3000`

Rotation must not use writer, migrator, business DB, Tencent RDS, Node2 local DB, or CMS write credentials.

## Export Policy

Exports are owner-controlled only.
No exports may contain PII, raw evidence, raw payloads, order identifiers, payment identifiers, emails, cookies, raw IPs, user agents, tokens, provider payloads, or payment payloads.

Allowed export scope is limited to:

- sanitized aggregate URL Truth rows
- sanitized Issue Queue summaries
- safe dashboard screenshots with no raw PII

Normal operator export remains blocked until a separate permissions and audit plan exists.

## Operator Onboarding

Operator onboarding is blocked until a separate permissions plan defines:

- user groups
- collection access
- datasource permissions
- native SQL policy
- export policy
- emergency revoke owner
- review cadence

Until then:

- only the admin user should exist
- no normal operators should be created
- no unrestricted native SQL should be granted to normal users
- no public sharing or anonymous access should be enabled

## Datasource Boundary

Metabase may read only:

- `seo_intel`
- `seo_urls`
- `seo_url_entities`
- `seo_issue_queue`
- future sanitized `seo_intel` aggregate tables or views after explicit approval

Metabase must not connect to:

- business DB
- Tencent `rds-fap-prod`
- Node2 local DB
- CMS write tables
- raw orders
- raw payments
- raw events detail
- raw email
- raw reports
- raw crawler logs
- provider payloads
- payment payloads

Next task: `PR-RESEARCH-01`
