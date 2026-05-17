# Metabase Deployment Runbook

## Status

No Metabase deployment happens in SEO-DASH-05.

Production Metabase deployment requires explicit human approval after this PR is reviewed.

## Required Preconditions

- Production `seo_intel` DB exists.
- Production migrations or view creation have separate human approval.
- Read-only DB user exists for `seo_intel` only.
- No business DB connection is configured.
- No CMS write DB connection is configured.
- No Node2 local DB connection is configured.
- Dashboard owner is assigned.
- Access control owner is assigned.
- PII redaction review is complete.
- Backup and restore plan is documented.

## Connection Policy

Metabase must connect only to sanitized `seo_intel` aggregate views or tables.

Forbidden connections:

- business DB
- CMS write tables
- Node2 local DB
- raw orders
- raw payments
- raw email
- raw event detail
- raw crawler logs

## Access Control

- Use a read-only database user.
- Disable write permissions.
- Restrict admin access to named owners.
- Review dashboard sharing before production use.
- Review exported CSV permissions before enabling exports.

## PII Review

Before deployment, confirm dashboards do not expose raw email, raw order numbers, raw attempt IDs, provider event IDs, payment IDs, raw IPs, raw cookies, raw user agents, raw payloads, or payment payloads.

## Stop Conditions

Stop deployment if:

- Metabase requires business DB access.
- Metabase requires CMS write table access.
- Metabase requires Node2 local DB access.
- Any dashboard exposes raw PII or raw identifiers.
- Read-only DB user is not available.
- Production migration or view creation approval is missing.
- Dashboard owner is not assigned.
