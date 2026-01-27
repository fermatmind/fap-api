# Runbook: Triage (PR9)

## Healthz red -> triage flow

1) Check `/api/v0.2/healthz` response and identify which deps are red.
2) Validate infra/service connectivity (db/redis/queue/cache/content_source).
3) Check deploy history (`v_deploy_events`) for recent changes.
4) If needed, rollback or hotfix. Re-run healthz snapshot to confirm.

## Redis red: common causes

- Missing PHP extension: phpredis not installed
  - Check: `php -m | grep -i redis`
  - Install (Ubuntu): `sudo apt-get install -y php8.4-redis && sudo systemctl restart php8.4-fpm`
- Redis service unavailable
  - Check: `systemctl status redis` or `redis-cli ping`
- Wrong connection string
  - Check env: `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`

## Notes

- Health snapshots are stored in `ops_healthz_snapshots`.
- Deps red rate view: `v_healthz_deps_daily`.
