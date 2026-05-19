# Ops Portal SEO Private Metabase Access Bridge Contract

## Purpose

OPS-PORTAL-SEO-04 defines future private Metabase access bridge options without implementing any network, proxy, or Metabase change.
This PR does not deploy, edit environment files, change DNS/CDN/OpenResty/Nginx, open ports, change ECS security groups, change RDS whitelists, operate Metabase, add data sources, create dashboards, enable scheduler, run collector writes, call live search APIs, submit URLs, read production crawler logs, publish Research, or create pSEO.

## Approved MVP Access

The approved MVP access model remains owner-controlled private access:

- Workbench access
- SSH tunnel through an approved bastion
- VPN private access after separate approval
- equivalent owner-controlled private channel

These options keep Metabase private and localhost-bound.

## Explicitly Not MVP

The following are not MVP access paths:

- raw public Metabase
- public DNS to Metabase
- public CDN path to Metabase
- iframe embedding
- reverse proxy through Ops Portal
- public sharing links
- anonymous links
- public embeds
- normal-operator unrestricted SQL

Reverse proxy behind Ops auth remains a future candidate only after a separate production preflight and explicit human approval.

## Bridge Option Policy

Workbench is the safest current access path because it does not require DNS, public ports, security group changes, or RDS whitelist changes.

SSH tunnel or bastion access may be allowed only when:

- bastion ownership is assigned
- access is authenticated and auditable
- tunnel scope is localhost/private only
- no public Metabase port is opened
- no broad CIDR or wildcard access is added

VPN access may be allowed only when:

- VPN owner is assigned
- user membership is auditable and revocable
- Metabase remains private
- no public sharing or anonymous link is enabled

Reverse proxy behind Ops auth is not authorized by this PR. It would require:

- production operation preflight
- auth/session/CSRF/cookie review
- audit logging plan
- rate limiting and revoke plan
- DNS/OpenResty/Nginx review if routing changes
- explicit no-public-sharing verification
- explicit no-normal-operator-SQL verification

## Production Gates

Any bridge that changes production network state must stop until separately approved.
Required gates include:

- no Metabase bind to `0.0.0.0`
- no public ECS port
- no ECS public IPv4 or EIP
- no security group broadening
- no RDS whitelist broadening
- no `%`, `0.0.0.0/0`, or broad CIDR
- no public RDS endpoint
- no business DB, Tencent RDS, or Node2 access
- no DNS/CDN/OpenResty/Nginx change without production approval

## Next Task

Next task: `OPS-PORTAL-SEO-05`
