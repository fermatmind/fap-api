# Providers 接入契约（Mock v0.1）

## 通用字段
### OAuth
1) Start：
   - `GET /api/v0.2/integrations/{provider}/oauth/start`
2) Callback：
   - `GET /api/v0.2/integrations/{provider}/oauth/callback?state=...&code=...`

### Webhook
- `POST /api/v0.2/webhooks/{provider}`
- Headers:
  - `X-Webhook-Signature`（可选；若配置 services.integrations.{provider}.webhook_secret 即校验）

### Ingest
- `POST /api/v0.2/integrations/{provider}/ingest`
- Body:
```
{
  "user_id": 1,
  "range_start": "2026-01-01T00:00:00Z",
  "range_end": "2026-01-02T00:00:00Z",
  "samples": [ ... ]
}
```

## 具体 Provider（占位）
### Apple Health
- data domains: sleep, steps, heart_rate
- webhook: optional
- note: 本 PR 仅 mock，不含真实 OAuth。

### Google Fit
- data domains: sleep, steps, heart_rate
- webhook: optional
- note: 本 PR 仅 mock，不含真实 OAuth。

### Calendar
- data domains: sleep (calendar inferred)
- webhook: optional
- note: 本 PR 仅 mock，不含真实 OAuth。

### ScreenTime
- data domains: screen_time
- webhook: optional
- note: 本 PR 仅 mock，不含真实 OAuth。
