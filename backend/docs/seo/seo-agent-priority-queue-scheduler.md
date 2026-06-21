# SEO Agent Priority Queue Scheduler

`SEO-AGENT-PRIORITY-QUEUE-SCHEDULER-01` adds the L5-A low-risk orchestration entrypoint:

```bash
php artisan seo-agent:priority-queue-scheduler \
  --mode=weekly-l5-low-risk \
  --limit=100 \
  --publish-limit=3 \
  --draft-limit=10 \
  --artifact-dir=/var/www/fap-api/current/backend/storage/app/seo-agent/l5-weekly \
  --json
```

The command is intended for external cron with `flock`, not Laravel scheduler:

```bash
cd /var/www/fap-api/current/backend && \
/usr/bin/flock -n /tmp/seo-agent-priority-queue-scheduler.lock \
php artisan seo-agent:priority-queue-scheduler \
  --mode=weekly-l5-low-risk \
  --limit=100 \
  --publish-limit=3 \
  --draft-limit=10 \
  --artifact-dir=/var/www/fap-api/current/backend/storage/app/seo-agent/l5-weekly \
  --json >> /var/log/fermatmind-seo-agent-l5-weekly.log 2>&1
```

## Sequence

1. `seo-agent:weekly-draft-write-auto`
2. `seo-agent:auto-rollback-guard --mode=preflight`
3. `seo-agent:cms-publish-auto-canary --auto-approve-low-risk --execute`
4. `seo-agent:post-publish-indexnow-auto --execute`
5. `seo-agent:auto-rollback-guard --mode=post-publish`
6. final `seo-agent-priority-queue-scheduler.v1` evidence

## Boundaries

- Allows low-risk CMS draft writes, at most 10 per run.
- Allows low-risk ContentPage publish canaries, at most 3 per run.
- Allows IndexNow queue write, approval, and live submit only for published ContentPage canaries.
- Does not publish Articles.
- Does not bulk publish CMS pages.
- Does not call Google Indexing API.
- Does not live-submit Baidu or Google sitemap channels.
- Does not start Laravel scheduler or queue workers.
- Does not mutate frontend code.
- Does not call external model APIs.

