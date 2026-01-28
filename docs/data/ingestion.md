# Zero-Input 数据管线（Mock 版）

## 目标
本 PR 提供“零输入”数据通道的最小可用链路：Mock OAuth + Ingest + Webhook + 幂等回放。真实 provider 细节（OAuth/签名/字段）留待后续 PR。

## 支持数据域
- sleep（睡眠）
- health（通用健康：steps / heart_rate / mood 等）
- screen_time（屏幕时间）

## 数据字段口径
统一 sample 协议：
```
{
  "domain": "sleep|steps|heart_rate|mood|screen_time",
  "recorded_at": "2026-01-10T00:00:00Z",
  "value": { ... },
  "external_id": "provider_event_id_optional",
  "source": "provider|seed|ingestion",
  "confidence": 1.0
}
```
- `domain`：sleep/screen_time 写入独立表；其他 domain 写入 health_samples。
- `value`：原样存入 value_json。
- `external_id`：可选，用于幂等键生成。

## 授权/撤回
Mock OAuth：
- `GET /api/v0.2/integrations/{provider}/oauth/start`
- `GET /api/v0.2/integrations/{provider}/oauth/callback`

撤回：
- `POST /api/v0.2/integrations/{provider}/revoke`

## Ingest & Replay
- `POST /api/v0.2/integrations/{provider}/ingest`
- `POST /api/v0.2/integrations/{provider}/replay/{batch_id}`

Ingest 会写入 `ingest_batches` 并分发到 domain 表，Replay 重放依赖 `idempotency_keys` 保证不重复。

### Ingest 示例
```
POST /api/v0.2/integrations/mock/ingest
{
  "user_id": 1,
  "range_start": "2026-01-01T00:00:00Z",
  "range_end": "2026-01-02T00:00:00Z",
  "samples": [
    {"domain":"sleep","recorded_at":"2026-01-01T00:00:00Z","value":{"duration_minutes":420},"external_id":"sleep_1"},
    {"domain":"steps","recorded_at":"2026-01-01T12:00:00Z","value":{"steps":8000},"external_id":"steps_1"}
  ]
}
```

## Webhook（可选）
- `POST /api/v0.2/webhooks/{provider}`
- body: `{event_id, external_user_id, recorded_at, samples:[] }`
- 若配置 `services.integrations.{provider}.webhook_secret`，则必须发送 `X-Webhook-Signature`（HMAC-SHA256）。

### Webhook 示例
```
POST /api/v0.2/webhooks/mock
{
  "event_id": "evt_001",
  "external_user_id": "ext_001",
  "recorded_at": "2026-01-01T00:00:00Z",
  "samples": [
    {"domain":"sleep","recorded_at":"2026-01-01T00:00:00Z","value":{"duration_minutes":410},"external_id":"sleep_3"}
  ]
}
```

## 导出/删除流程（最小可执行）
当前 PR 不实现真实导出/删除，仅提供流程占位：
1) 通过 integrations 记录 consent_version 与 scopes。
2) 若用户撤回，标记 revoked_at，停止写入。
3) 后续 PR 将接入导出 job + 删除 job（需审计日志）。
