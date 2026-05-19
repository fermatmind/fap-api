# Ops Portal SEO Auth Audit Permission Contract

## Purpose

OPS-PORTAL-SEO-02 defines the auth, permission, owner, audit, revoke, and operator onboarding contract for `/ops/seo`.
This is a documentation, generated-contract, and focused-test PR only. It does not add route shell code, runtime permission enforcement code, deploy, edit environment files, change DNS/CDN/OpenResty/Nginx, open ports, change security groups, change RDS whitelists, operate Metabase, add data sources, create dashboards, enable scheduler, run collector writes, call live search APIs, submit URLs, read production crawler logs, publish Research, or create pSEO.

## Required Auth Stack

`/ops/seo` must be served only through the existing fap-api Filament Ops panel.

Required access controls:

- admin guard
- Filament Ops authentication
- session cookies
- CSRF protection
- TOTP where configured
- org context where the panel requires it
- Ops access control middleware
- host allowlist checks where configured
- IP allowlist checks where configured
- fail-closed access behavior

The page must not create a public route outside the Ops panel.

## Permission Decision

Initial MVP access may reuse existing owner or ops read access:

- `admin.owner`
- `admin.ops.read`

A future narrower permission is reserved:

- `admin.seo_intel.read`

The route shell should start with owner or ops-read visibility unless a later scoped PR adds the narrower permission to the RBAC system.
Datasource management remains admin-only and must not be granted to normal operators through `/ops/seo`.

## Owner Assignments

The Ops Portal SEO access layer must record the operational owners:

- Metabase admin owner
- dashboard owner
- DB access owner
- export policy owner
- emergency revoke owner

Owner changes must be auditable and revocable.

## Audit Expectations

The following events must be logged or explicitly covered by a future implementation plan:

- `/ops/seo` page access
- permission changes
- owner assignment changes
- export approval decisions
- emergency revoke actions
- future bridge or proxy access events if a bridge is ever approved

The audit trail must not store secrets, raw DB passwords, raw tokens, cookies, raw IP payloads, order IDs, payment IDs, attempt IDs, raw emails, or raw provider payloads.

## Operator Onboarding Boundary

Normal operators are blocked until a separate permissions plan exists.

Normal operators must not receive:

- unrestricted SQL
- native query access by default
- datasource management
- export access by default
- public sharing access
- public embedding access
- anonymous link creation

Only owner-approved sanitized aggregate exports may be allowed in a later scoped task.

## Emergency Revoke Contract

Emergency revoke is required if any of the following appears:

- Metabase binds to `0.0.0.0`
- Metabase is exposed through public IPv4 or EIP
- ECS security group is opened publicly
- public sharing is enabled
- anonymous links are created
- public embedding is enabled
- unexpected data source appears
- business DB, Tencent RDS, Node2 local DB, CMS write tables, or raw operational tables are connected
- normal operators receive unrestricted SQL

Revoke steps must include stopping public access, disabling unsafe settings, confirming localhost-only Metabase, reviewing datasource inventory, and recording owner action.

## Next Task

Next task: `OPS-PORTAL-SEO-03`
