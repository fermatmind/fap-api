# FAP API Spec (v0.3 / v0.3 / v0.4)

Status: Active (Code-Truth Spec)
Source of truth:
- `/Users/rainie/Desktop/GitHub/fap-api/backend/routes/api.php`
- `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --path=api --except-vendor --json`

This file is a contract mirror of current runtime routes. It does not define future behavior by itself.

## Route Snapshot
- Total API routes: `120`
- Base: `2`
- v0.3: `82`
- v0.3: `31`
- v0.4: `5`

## Auth Labels
- `Auth: Public` no token required.
- `Auth: Sanctum` requires Laravel Sanctum session/token.
- `Auth: AdminAuth` requires admin guard or `X-FAP-Admin-Token` path.
- `Auth: FmTokenAuth` requires FM token middleware.
- `Auth: FmTokenOptional` accepts FM token but endpoint is readable without hard auth.
- `Auth: RequireOrgRole(owner|admin)` requires FM token + org role gate.
- `Feature: fap_feature:*` feature flag middleware gate.

## Complete Route Inventory (Code Truth)

### Base (`2`)
| Method | Path | Auth | Feature |
|---|---|---|---|
| `GET|HEAD` | `/api/healthz` | `Public` | `-` |
| `GET|HEAD` | `/api/user` | `Sanctum` | `-` |

### v0.3 (`82`)
| Method | Path | Auth | Feature |
|---|---|---|---|
| `DELETE` | `/api/v0.3/memory/{id}` | `FmTokenAuth` | `insights` |
| `GET|HEAD` | `/api/v0.3/admin/audit-logs` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/admin/content-releases` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/admin/events` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/admin/global-search` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/admin/go-live-gate` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/admin/healthz/snapshot` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/admin/migrations/observability` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/admin/migrations/rollback-preview` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/admin/organizations` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/admin/queue/dlq/metrics` | `AdminAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/attempts/{attemptId}/report` | `FmTokenOptional` | `-` |
| `GET|HEAD` | `/api/v0.3/attempts/{attemptId}/result` | `FmTokenOptional` | `-` |
| `GET|HEAD` | `/api/v0.3/attempts/{id}/quality` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/attempts/{id}/share` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/attempts/{id}/stats` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/claim/report` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/content-packs` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/content-packs/{pack_id}/{dir_version}/manifest` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/content-packs/{pack_id}/{dir_version}/questions` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/health` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/healthz` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/insights/{id}` | `FmTokenOptional` | `insights` |
| `GET|HEAD` | `/api/v0.3/integrations/{provider}/oauth/callback` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/integrations/{provider}/oauth/start` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/lookup/ticket/{code}` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/me/agent/messages` | `FmTokenAuth` | `agent` |
| `GET|HEAD` | `/api/v0.3/me/agent/settings` | `FmTokenAuth` | `agent` |
| `GET|HEAD` | `/api/v0.3/me/attempts` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/me/data/mood` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/me/data/screen-time` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/me/data/sleep` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/me/identities` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/me/profile` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/memory/export` | `FmTokenAuth` | `insights` |
| `GET|HEAD` | `/api/v0.3/memory/search` | `FmTokenAuth` | `insights` |
| `GET|HEAD` | `/api/v0.3/norms/percentile` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/questions` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/scale_meta` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/scales/MBTI` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/scales/MBTI/questions` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/scales/{scale}/norms` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/share/{id}` | `FmTokenOptional` | `-` |
| `POST` | `/api/v0.3/admin/agent/disable-trigger` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/agent/replay/{user_id}` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/cache/invalidate` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/content-releases/publish` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/content-releases/rollback` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/content-releases/upload` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/content-releases/{id}/probe` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/go-live-gate/run` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/organizations` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/organizations/import-sync` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/admin/queue/dlq/replay/{failed_job_id}` | `AdminAuth` | `-` |
| `POST` | `/api/v0.3/attempts` | `Public` | `-` |
| `POST` | `/api/v0.3/attempts/start` | `Public` | `-` |
| `POST` | `/api/v0.3/attempts/{attempt_id}/feedback` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/attempts/{id}/result` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/attempts/{id}/start` | `Public` | `-` |
| `POST` | `/api/v0.3/auth/phone/send_code` | `Public` | `-` |
| `POST` | `/api/v0.3/auth/phone/verify` | `Public` | `-` |
| `POST` | `/api/v0.3/auth/provider` | `Public` | `-` |
| `POST` | `/api/v0.3/auth/wx_phone` | `Public` | `-` |
| `POST` | `/api/v0.3/events` | `Public` | `analytics` |
| `POST` | `/api/v0.3/insights/generate` | `FmTokenAuth` | `insights` |
| `POST` | `/api/v0.3/insights/{id}/feedback` | `FmTokenAuth` | `insights` |
| `POST` | `/api/v0.3/integrations/{provider}/ingest` | `Public` | `-` |
| `POST` | `/api/v0.3/integrations/{provider}/replay/{batch_id}` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/integrations/{provider}/revoke` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/lookup/device` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/lookup/order` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/me/agent/messages/{id}/ack` | `FmTokenAuth` | `agent` |
| `POST` | `/api/v0.3/me/agent/messages/{id}/feedback` | `FmTokenAuth` | `agent` |
| `POST` | `/api/v0.3/me/agent/settings` | `FmTokenAuth` | `agent` |
| `POST` | `/api/v0.3/me/bind-email` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/me/email/bind` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/me/email/verify-binding` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/me/identities/bind` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/memory/propose` | `FmTokenAuth` | `insights` |
| `POST` | `/api/v0.3/memory/{id}/confirm` | `FmTokenAuth` | `insights` |
| `POST` | `/api/v0.3/shares/{shareId}/click` | `FmTokenOptional` | `-` |
| `POST` | `/api/v0.3/webhooks/{provider}` | `Public` | `-` |

### v0.3 (`31`)
| Method | Path | Auth | Feature |
|---|---|---|---|
| `GET|HEAD` | `/api/v0.3/attempts/{attempt_id}/progress` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/attempts/{id}` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/attempts/{id}/report` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/attempts/{id}/result` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/boot` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/experiments` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/flags` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/orders/{order_no}` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/orgs/me` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/orgs/{org_id}/wallets` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/orgs/{org_id}/wallets/{benefit_code}/ledger` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.3/scales` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/scales/lookup` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/scales/sitemap-source` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/scales/{scale_code}` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/scales/{scale_code}/questions` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/shares/{id}` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.3/skus` | `Public` | `-` |
| `POST` | `/api/v0.3/attempts/start` | `Public` | `-` |
| `POST` | `/api/v0.3/attempts/submit` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/orders` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/orders/checkout` | `Public` | `-` |
| `POST` | `/api/v0.3/orders/lookup` | `Public` | `-` |
| `POST` | `/api/v0.3/orders/stub` | `Public` | `-` |
| `POST` | `/api/v0.3/orders/{order_no}/resend` | `Public` | `-` |
| `POST` | `/api/v0.3/orders/{provider}` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/orgs` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/orgs/invites/accept` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/orgs/{org_id}/invites` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.3/webhooks/payment/{provider}` | `Public` | `-` |
| `PUT` | `/api/v0.3/attempts/{attempt_id}/progress` | `Public` | `-` |

### v0.4 (`5`)
| Method | Path | Auth | Feature |
|---|---|---|---|
| `GET|HEAD` | `/api/v0.4/boot` | `Public` | `-` |
| `GET|HEAD` | `/api/v0.4/orgs/{org_id}/assessments/{id}/progress` | `FmTokenAuth` | `-` |
| `GET|HEAD` | `/api/v0.4/orgs/{org_id}/assessments/{id}/summary` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.4/orgs/{org_id}/assessments` | `FmTokenAuth` | `-` |
| `POST` | `/api/v0.4/orgs/{org_id}/assessments/{id}/invite` | `FmTokenAuth` | `-` |

## Core Endpoint Details

### 1) POST `/api/v0.3/attempts/start`
- Auth: `Public` (`ResolveAnonId` + `ResolveOrgContext` + throttle)
- Request validator: `StartAttemptRequest`
- Request body:
  - `scale_code` required string max:64
  - `region` optional string max:32
  - `locale` optional string max:16
  - `anon_id` optional string max:64
  - `client_platform` optional string max:32
  - `client_version` optional string max:32
  - `channel` optional string max:32
  - `referrer` optional string max:255
  - `meta` optional object
- Success response (`AttemptStartService`):
  - `ok`, `attempt_id`, `scale_code`, `pack_id`, `dir_version`, `region`, `locale`, `question_count`, `resume_token`, `resume_expires_at`

### 2) POST `/api/v0.3/attempts/submit`
- Auth: `FmTokenAuth` (inside v0.3 group; submit is token-gated)
- Request validator: `SubmitAttemptRequest`
- Request body:
  - `attempt_id` required string max:64
  - `answers` optional array
  - `answers[].question_id` required_with answers, string max:128
  - `answers[].code` optional
  - `answers[].question_type` optional string max:32
  - `answers[].question_index` optional integer min:0
  - `duration_ms` required integer min:0
  - `invite_token` optional string max:64
- Success response (`AttemptSubmitService::buildSubmitPayload`):
  - `ok`, `attempt_id`, `type_code`, `scores`, `scores_pct`, `result`
  - `meta.scale_code`, `meta.pack_id`, `meta.dir_version`, `meta.content_package_version`, `meta.scoring_spec_version`, `meta.report_engine_version`
  - `idempotent`

### 3) GET `/api/v0.3/attempts/{id}/report`
- Auth: `Public` at transport layer (`ResolveAnonId` + `ResolveOrgContext`), ownership enforced in controller/query scope
- Middleware: `uuid:id`
- Runtime gate: `ReportGatekeeper::resolve` decides `locked/free/full`, paywall metadata, module-level access, snapshot fallback
- Success response shape:
  - includes gate payload: `ok`, `locked`, `variant`, `report_access`, `report`, `paywall`, `offers` (if any)
  - merged `meta`: `scale_code`, `pack_id`, `dir_version`, `content_package_version`, `scoring_spec_version`, `report_engine_version`

### 4) Commerce core (v0.3)

#### POST `/api/v0.3/orders`
- Auth: `FmTokenAuth`
- Request validation in controller:
  - `sku` required string max:64
  - `quantity` optional integer min:1 max:1000
  - `target_attempt_id` optional string max:64
  - `provider` optional string max:32
  - `idempotency_key` optional string max:128
  - `org_id/user_id/anon_id` prohibited in body
- Success: `{ ok, order_no }`

#### POST `/api/v0.3/orders/checkout`
- Auth: `Public`
- Request body: `attempt_id?`, `sku?`, `order_no?`, `provider?`, `idempotency_key?`
- Behavior: reuse existing order when `order_no` valid; otherwise creates new pending order.

#### POST `/api/v0.3/orders/lookup`
- Auth: `Public`
- Request body:
  - `order_no` required string max:64
  - `email` required string max:320
- Success: `{ ok, order_no, status }` or `ORDER_NOT_FOUND`

#### POST `/api/v0.3/webhooks/payment/{provider}`
- Auth: `Public webhook`
- Middleware: `LimitWebhookPayloadSize`, `throttle:api_webhook`
- Controller verifies signature and delegates to `PaymentWebhookProcessor`.

### 5) GET `/api/v0.4/boot`
- Auth: `Public`
- Response fields (`BootController`):
  - `ok`, `region`, `locale`, `currency`
  - `cdn.assets_base_url`
  - `payment_methods[]`
  - `compliance`, `experiments`, `feature_flags_version`, `policy_versions`
- HTTP cache: `Cache-Control`, `Vary`, `ETag`, supports `If-None-Match` -> `304`.

### 6) Assessments core (v0.4)

#### POST `/api/v0.4/orgs/{org_id}/assessments`
- Auth: `RequireOrgRole(owner|admin)` + `FmTokenAuth`
- Request body:
  - `scale_code` required string max:64
  - `title` required string max:255
  - `due_at` optional date
- Success: `{ ok, assessment }`

#### POST `/api/v0.4/orgs/{org_id}/assessments/{id}/invite`
- Auth: `RequireOrgRole(owner|admin)` + `FmTokenAuth`
- Request body:
  - `subjects` required array min:1
  - `subjects[].subject_type` required in `user|email`
  - `subjects[].subject_value` required string max:255

#### GET `/api/v0.4/orgs/{org_id}/assessments/{id}/progress`
- Auth: `RequireOrgRole(owner|admin)` + `FmTokenAuth`
- Response: `{ ok, assessment_id, ...progress }`

#### GET `/api/v0.4/orgs/{org_id}/assessments/{id}/summary`
- Auth: `RequireOrgRole(owner|admin)` + `FmTokenAuth`
- Response: `{ ok, assessment_id, summary }`

### 7) Phone auth core (v0.3)

#### POST `/api/v0.3/auth/phone/send_code`
- Auth: `Public` (`throttle:api_auth`)
- Request body (`SendPhoneCodeRequest` + controller validation merge):
  - `phone` required, normalized E.164 form
  - `scene` optional enum: `login|bind|lookup`
  - `consent` required accepted (PIPL)
  - `anon_id?`, `device_key?`
- Success: `{ ok, phone, scene, ttl_seconds, dev_code? }`

#### POST `/api/v0.3/auth/phone/verify`
- Auth: `Public` (`throttle:api_auth`)
- Request body (`VerifyPhoneCodeRequest` + controller validation merge):
  - `phone`, `code`, `scene?`, `consent` (accepted), `anon_id?`, `device_key?`
- Success:
  - `{ ok, token, expires_at, user }`

### 8) POST `/api/v0.3/me/identities/bind`
- Auth: `FmTokenAuth`
- Request validator: `BindIdentityRequest`
- Request body:
  - `provider` required enum: `wechat|douyin|baidu|web|app`
  - `provider_uid` required string max:128
  - `consent` required accepted
  - `meta` optional object
- Success: `{ ok, identity }`

### 9) Lookup core (v0.3)

#### GET `/api/v0.3/lookup/ticket/{code}`
- Auth: `Public`
- Path rule: `FMT-[A-Z0-9]{8}` (controller regex)
- Success: `{ ok, attempt_id, ticket_code, result_api, report_api }`

#### POST `/api/v0.3/lookup/device`
- Auth: `FmTokenAuth`
- Request body:
  - `attempt_ids` required array, max slice: 20, each UUID
- Success: `{ ok, items[] }`

#### POST `/api/v0.3/lookup/order`
- Auth: `FmTokenAuth`
- Request body: `order_no` (or query fallback)
- Feature gate: `LOOKUP_ORDER` runtime flag
- Success: `{ ok, order_no, attempt_id?, result_api?, report_api? }`

## Notes
- This spec intentionally mirrors runtime middleware. Product design or future APIs must be marked as Draft in separate docs until routes exist.
- For per-field compatibility and legacy aliases, see corresponding controller/request classes under `backend/app/Http`.
