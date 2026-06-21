# SEO Agent Auto Rollback Guard

`seo-agent:auto-rollback-guard` is the L5-A stop-the-line guard for low-risk SEO Agent automation. It reads run or publish evidence, decides whether downstream publish/IndexNow should continue, writes a guard artifact, and can optionally execute at most one ContentPage rollback canary when publish evidence includes complete rollback metadata.

## Command

```bash
php artisan seo-agent:auto-rollback-guard \
  --run-evidence=<seo-agent-evidence.json> \
  --mode=post-publish \
  --artifact-dir=/var/www/fap-api/current/backend/storage/app/seo-agent/auto-rollback-guard \
  --json
```

Optional rollback canary:

```bash
php artisan seo-agent:auto-rollback-guard \
  --run-evidence=<seo-agent-cms-publish-canary-or-auto-canary.json> \
  --mode=post-publish \
  --execute \
  --json
```

## Contract

- `preflight` mode checks upstream evidence and emits `pause_publish` / `pause_indexnow` when risk or boundary violations are detected.
- `post-publish` mode accepts `seo-agent-cms-publish-canary.v1` and `seo-agent-cms-publish-auto-canary.v1`.
- Rollback execution is limited to exactly one eligible ContentPage canary from the same run evidence.
- Rollback requires `rollback_evidence.available=true`, `candidate_revision_id`, and `previous_revision_id`.
- The guard never publishes Article content, never performs bulk publish, and never enqueues or submits search channels.

## Boundaries

The command does not call GSC, Google Indexing, IndexNow, Baidu, external model APIs, queue workers, schedulers, or frontend code. Its only write-capable path is a single bounded ContentPage rollback when explicitly run with `--execute` and complete rollback evidence.
