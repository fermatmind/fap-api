# BACKEND-DEPLOY-TARGET-ALIYUN-01

Status: completed
Owner: backend deploy
Last updated: 2026-05-21

## Decision

Production backend deploy target is Aliyun ECS `fap-api-prod-ali-01`:

- Public IP: `139.224.130.204`
- Runtime host user: `ubuntu`
- Standard deploy root: `/var/www/fap-api`
- Public API host: `api.fermatmind.com`
- Ops host: `ops.fermatmind.com`

Tencent Node3 `122.152.221.126` is retired as a production deploy target. Deploy automation must fail closed instead of deploying new production releases to Node3.

## Runtime Bootstrap

The emergency migration runtime was mounted behind the standard deploy path so future releases have one stable target:

- `/var/www/fap-api/current -> /opt/fap-api`
- `/var/www/fap-api/releases`
- `/var/www/fap-api/shared/backend/.env`
- `/var/www/fap-api/shared/backend/storage`
- `/var/www/fap-api/shared/content_packages`

Nginx, Supervisor, and cron point to `/var/www/fap-api/current/backend`. The first standard Deployer release may replace the temporary `current` symlink with a managed release symlink.

Node3 queue workers and Laravel scheduler were stopped after `api.fermatmind.com` and `ops.fermatmind.com` resolved to Aliyun. Node3 nginx was left running temporarily only for stale DNS-cache tail traffic.

## Certificate Renewal

`ops.fermatmind.com` uses a Let's Encrypt certificate on Aliyun. The certificate now renews with HTTP-01/webroot instead of manual DNS-01:

- Cert path: `/etc/letsencrypt/live/ops.fermatmind.com/fullchain.pem`
- Renewal authenticator: `webroot`
- Webroot: `/var/www/fap-api/current/backend/public`
- Nginx reload hook: `/etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh`
- Certbot timer: enabled and active

`certbot renew --dry-run --cert-name ops.fermatmind.com --no-random-sleep-on-renew` succeeded on Aliyun.

## Retired Node3 Deploy Webhook

The old GitHub push webhook that targeted `http://122.152.221.126:9000/hooks/deploy-fap-api` has been disabled in GitHub. Node3 `webhook.service` is stopped and disabled, and Node3 has a host firewall reject rule for TCP/9000.

Production deployment should use the GitHub Actions/Deployer SSH path to Aliyun, not the old Node3 webhook.

## Acceptance Checks

```bash
rg -n '122\.152\.221\.126|DEPLOY_HOST_PROD' deploy.php .github/workflows/deploy.yml README_DEPLOY.md
php -l deploy.php
certbot renew --dry-run --cert-name ops.fermatmind.com --no-random-sleep-on-renew
```

Expected result:

- Production defaults resolve to `139.224.130.204`.
- The workflow refuses `DEPLOY_HOST_PROD=122.152.221.126`.
- Production health and auth smoke use `https://api.fermatmind.com`.
- Production Deployer host defaults use `api.fermatmind.com` as the backend healthcheck host.
- `ops.fermatmind.com` certificate renewal succeeds without DNSPod manual TXT records.
- GitHub repository webhook for the old Node3 deploy endpoint is inactive.
