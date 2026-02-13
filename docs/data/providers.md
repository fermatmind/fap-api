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
  - `X-Webhook-Timestamp`（Unix 秒级时间戳；若配置 secret 则必填）
  - `X-Webhook-Signature`（HMAC-SHA256；签名串为 `"{timestamp}.{raw_body}"`）

### Ingest
- `POST /api/v0.2/integrations/{provider}/ingest`
- Headers:
  - `X-Ingest-Key`（明文 key，服务端按 `sha256` 比对 `ingest_key_hash`）
  - `X-Ingest-Event-Id`（事件唯一 ID，重复请求会被拒绝）
- Body:
```
{
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
