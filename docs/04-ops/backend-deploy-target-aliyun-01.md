# BACKEND-DEPLOY-TARGET-ALIYUN-01

Status: completed
Owner: backend deploy
Last updated: 2026-05-21

## Decision

Production backend deploy target is Aliyun ECS `fap-api-prod-ali-01`:

- Public IP: redacted from repository docs; use deploy config / approved ops inventory.
- Runtime host user: redacted from repository docs; use deploy config / approved ops inventory.
- Standard deploy root: redacted from repository docs; use deploy config / approved ops inventory.
- Public API host: `api.fermatmind.com`
- Ops host: `ops.fermatmind.com`

Tencent Node3 is retired as a production deploy target. Deploy automation must fail closed instead of deploying new production releases to Node3.

## Runtime Bootstrap

The emergency migration runtime was mounted behind the standard deploy path so future releases have one stable target:

- current release symlink
- managed release directory
- shared backend env file
- shared backend storage
- shared content packages

Nginx, Supervisor, and cron point to the managed current release backend. The first standard Deployer release may replace the temporary `current` symlink with a managed release symlink.

Node3 queue workers and Laravel scheduler were stopped after `api.fermatmind.com` and `ops.fermatmind.com` resolved to Aliyun. Node3 nginx was left running temporarily only for stale DNS-cache tail traffic.

## Certificate Renewal

`ops.fermatmind.com` uses a Let's Encrypt certificate on Aliyun. The certificate now renews with HTTP-01/webroot instead of manual DNS-01:

- Cert path: `/etc/letsencrypt/live/ops.fermatmind.com/fullchain.pem`
- Renewal authenticator: `webroot`
- Webroot: managed current release public directory
- Nginx reload hook: `/etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh`
- Certbot timer: enabled and active

`certbot renew --dry-run --cert-name ops.fermatmind.com --no-random-sleep-on-renew` succeeded on Aliyun.

## Retired Node3 Deploy Webhook

The old GitHub push webhook for the retired Node3 deploy endpoint has been disabled in GitHub. Node3 `webhook.service` is stopped and disabled, and Node3 has a host firewall reject rule for TCP/9000.

Production deployment should use the GitHub Actions/Deployer SSH path to Aliyun, not the old Node3 webhook.

## Node3 Final Check

`NODE3-RETIREMENT-FINAL-CHECK` completed on 2026-05-21:

- Cloudflare and Google public DNS resolve `api.fermatmind.com` and `ops.fermatmind.com` to the approved Aliyun backend target.
- Aliyun Host-forced smoke checks returned 200 for the public API questions endpoint and Ops login.
- The old GitHub deploy webhook for Node3 TCP/9000 is inactive.
- Node3 `webhook.service` is inactive and disabled.
- Node3 has no TCP/9000 listener, and Aliyun cannot connect to Node3 TCP/9000.
- Node3 queue worker supervisor groups remain stopped.
- Node3 ubuntu and www-data Laravel scheduler cron lines remain commented with `NODE3_RETIRED_20260521180344`.
- Recent Node3 nginx access-log tail showed no API/Ops traffic after the earlier DNS-cutover tail.

Conclusion: Node3 can expire without affecting the current production API/Ops/queue/scheduler/deploy path. Node2 and the still-Tencent DB/Redis path remain separate dependencies until their later migration.

## Acceptance Checks

```bash
rg -n 'DEPLOY_HOST_PROD|retired Tencent Node3' deploy.php .github/workflows/deploy.yml README_DEPLOY.md
php -l deploy.php
certbot renew --dry-run --cert-name ops.fermatmind.com --no-random-sleep-on-renew
```

Expected result:

- Production defaults resolve to the approved Aliyun backend target.
- The workflow refuses the retired Node3 deploy target.
- Production health and auth smoke use `https://api.fermatmind.com`.
- Production Deployer host defaults use `api.fermatmind.com` as the backend healthcheck host.
- `ops.fermatmind.com` certificate renewal succeeds without DNSPod manual TXT records.
- GitHub repository webhook for the old Node3 deploy endpoint is inactive.
