# PR8 Observability & Alerts

## Healthz Endpoint
- Canonical probe path: `GET /api/healthz`
- There is no supported top-level `/healthz` production probe path.
- Access policy:
  - production healthz is allowlist-only
  - requests from non-allowlisted public IPs may return `404` by design
  - do not treat arbitrary public `curl` returning `404` as a deploy failure by itself
- Verification modes:
  - allowlisted external probe to `/api/healthz`
  - internal/local probe from the app host
  - `php artisan ops:healthz-snapshot` for controlled runtime verification
- 期望：
  - `.ok == true`
  - `.deps.db.ok == true`
  - `.deps.redis.ok == true`
  - `.deps.queue.ok == true`
  - `.deps.cache_dirs.ok == true`
  - `.deps.content_source.ok == true`

### Dep Error Codes
- DB_UNAVAILABLE
- REDIS_UNAVAILABLE
- QUEUE_TABLE_MISSING / QUEUE_UNAVAILABLE
- CACHE_DIR_NOT_WRITABLE
- CONTENT_SOURCE_NOT_READY

## Metrics & Alerts (建议阈值)
### Availability
- Healthz `.ok==false` 连续 1 分钟：P1
- Healthz `.deps.redis.ok==false`：P1
- Healthz `.deps.db.ok==false`：P1
- Healthz `.deps.cache_dirs.ok==false`：P2

### Latency (API)
- `/api/healthz` p95 > 300ms 持续 5 分钟：P2
- `/api/v0.3/scales/MBTI/questions` p95 > 800ms 持续 5 分钟：P2

### Errors
- 5xx rate > 1% 持续 5 分钟：P1
- 5xx rate > 0.2% 持续 10 分钟：P2

### Queue
- database queue：failed_jobs 增长速率异常：P2
- redis queue：连接失败：P1

## Sentry Release
- 部署写入 `SENTRY_RELEASE={{release_name}}`
- 事件聚合按 release 追踪回归与发布影响范围
