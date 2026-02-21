# Report Lookup + Identity PRD (Canonical, Code-Truth)

Status: Active (Canonical)
Version: 2026-02-18
Scope: v0.2 lookup/auth/identity runtime contract

## 0. Canonical Rule
- Canonical document: `backend/docs/product/report-lookup-prd.md`
- Deprecated mirror: `docs/product/report-lookup-prd.md`
- Route/code source of truth:
  - `backend/routes/api.php`
  - `cd backend && php artisan route:list --path=api --except-vendor --json`
  - Controllers:
    - `backend/app/Http/Controllers/LookupController.php`
    - `backend/app/Http/Controllers/API/V0_2/AuthPhoneController.php`
    - `backend/app/Http/Controllers/API/V0_2/AuthProviderController.php`
    - `backend/app/Http/Controllers/API/V0_2/IdentityController.php`

This PRD documents only landed behavior. Any endpoint not in routes is Draft/Planned.

## 1. Phase A Scope (Implemented)

### 1.1 Route Matrix
| Status | Method | Path | Auth | Middleware/Notes |
|---|---|---|---|---|
| Implemented | `GET` | `/api/v0.3/lookup/ticket/{code}` | `Auth: Public` | `throttle:api_public` + LookupController IP limiter |
| Implemented | `POST` | `/api/v0.3/lookup/device` | `Auth: FmTokenAuth` | `throttle:api_public`, user/anon ownership constrained |
| Implemented | `POST` | `/api/v0.3/lookup/order` | `Auth: FmTokenAuth` | runtime switch `LOOKUP_ORDER` must be enabled |
| Implemented | `POST` | `/api/v0.3/auth/phone/send_code` | `Auth: Public` | `throttle:api_auth`, PIPL consent required |
| Implemented | `POST` | `/api/v0.3/auth/phone/verify` | `Auth: Public` | `throttle:api_auth`, PIPL consent required |
| Implemented | `POST` | `/api/v0.3/auth/provider` | `Auth: Public` | `throttle:api_auth`, provider enum validated |
| Implemented | `POST` | `/api/v0.3/me/identities/bind` | `Auth: FmTokenAuth` | provider binding with conflict checks |
| Implemented | `GET` | `/api/v0.3/me/identities` | `Auth: FmTokenAuth` | list identities |

## 2. Implemented Contracts (Detail)

### 2.1 Ticket Lookup
Endpoint: `GET /api/v0.3/lookup/ticket/{code}`

Auth: `Auth: Public`

Request:
- Path `code`: normalized to uppercase.
- Must match regex `^FMT-[A-Z0-9]{8}$`.

Success `200`:
```json
{
  "ok": true,
  "attempt_id": "<uuid>",
  "ticket_code": "FMT-ABCDEFGH",
  "result_api": "/api/v0.3/attempts/<uuid>/result",
  "report_api": "/api/v0.3/attempts/<uuid>/report",
  "result_page": null,
  "report_page": null
}
```

Error shape:
- `429 RATE_LIMITED`
- `422 INVALID_FORMAT`
- `404 NOT_FOUND`

Ownership/Scope:
- Org-scoped (`resolveOrgId`) lookup.

### 2.2 Device Lookup
Endpoint: `POST /api/v0.3/lookup/device`

Auth: `Auth: FmTokenAuth`

Request:
- `attempt_ids` required array.
- Empty array allowed, returns empty `items`.
- Max 20 ids (excess truncated).
- Each id must be UUID string.

Success `200`:
```json
{
  "ok": true,
  "items": [
    {
      "attempt_id": "<uuid>",
      "ticket_code": "FMT-ABCDEFGH",
      "result_api": "/api/v0.3/attempts/<uuid>/result",
      "report_api": "/api/v0.3/attempts/<uuid>/report"
    }
  ]
}
```

Error shape:
- `429 RATE_LIMITED`
- `422 INVALID_PAYLOAD`
- `422 INVALID_ID`
- `404 NOT_FOUND`

Ownership/Scope:
- Org + ownership constrained by `fm_user_id` / `fm_anon_id`.

### 2.3 Order Lookup
Endpoint: `POST /api/v0.3/lookup/order`

Auth: `Auth: FmTokenAuth`

Request:
- `order_no` required (body first, query fallback).

Runtime gates:
- Feature toggle-like runtime switch `LOOKUP_ORDER` (via `RuntimeConfig`).
- Requires supported order table/column (`orders|payments`, `order_no|order_id|order_number|order_sn`).

Success `200`:
```json
{
  "ok": true,
  "order_no": "ORD-123",
  "attempt_id": "<uuid>",
  "result_api": "/api/v0.3/attempts/<uuid>/result",
  "report_api": "/api/v0.3/attempts/<uuid>/report"
}
```

Error shape:
- `429 RATE_LIMITED`
- `422 INVALID_ORDER`
- `200 ok=false NOT_ENABLED`
- `200 ok=false NOT_SUPPORTED`
- `404 NOT_FOUND`

### 2.4 Phone OTP Send
Endpoint: `POST /api/v0.3/auth/phone/send_code`

Auth: `Auth: Public`

Request (controller runtime validation):
- `phone` required string max 32
- `scene` optional string max 32, default `login`
- `anon_id` optional string max 128
- `device_key` optional string max 256
- `consent` accepted required (`consent` or legacy `agree`)

Normalization/Policy:
- Phone normalization supports `+...` and CN 11-digit -> `+86...`.
- PIPL consent is enforced.
- IP + phone scoped rate limits.

Success `200`:
```json
{
  "ok": true,
  "phone": "+8613812345678",
  "scene": "login",
  "ttl_seconds": 300,
  "dev_code": "123456"
}
```
`dev_code` only appears in dev-like environments.

### 2.5 Phone OTP Verify
Endpoint: `POST /api/v0.3/auth/phone/verify`

Auth: `Auth: Public`

Request (controller runtime validation):
- `phone` required string max 32
- `code` required string max 16
- `scene` optional string max 32, default `login`
- `anon_id` optional string max 128
- `device_key` optional string max 256
- `consent` accepted required

Behavior:
- Verifies OTP.
- Finds/creates user by phone.
- Performs MVP asset append (`anon_id -> user`) when available.
- Issues FM token.

Success `200`:
```json
{
  "ok": true,
  "token": "<fm_token>",
  "expires_at": "<iso8601>",
  "user": {
    "id": "<user_id>",
    "phone": "+8613812345678"
  }
}
```

### 2.6 Provider Login
Endpoint: `POST /api/v0.3/auth/provider`

Auth: `Auth: Public`

Request (`AuthProviderRequest`):
- `provider` required enum: `wechat|douyin|baidu|web|app`
- `provider_code` required string max 128
- `anon_id` optional string max 128

Behavior:
- If identity not bound: return `ok=true, bound=false`.
- If bound: issue FM token and return `bound=true`.
- `provider_code=dev` is local/testing only.

### 2.7 Bind Identity
Endpoint: `POST /api/v0.3/me/identities/bind`

Auth: `Auth: FmTokenAuth`

Request (`BindIdentityRequest`):
- `provider` required enum: `wechat|douyin|baidu|web|app`
- `provider_uid` required string max 128
- `consent` required accepted
- `meta` optional object

Success `200`:
```json
{
  "ok": true,
  "identity": {
    "provider": "wechat",
    "provider_uid": "..."
  }
}
```

Error shape:
- `401 UNAUTHORIZED`
- `429 RATE_LIMITED`
- business errors from `IdentityService` (e.g. conflict) with mapped status.

## 3. Draft / Planned (Not Landed)

The following are not present in `backend/routes/api.php` and must not be treated as released:

| Status | Planned Path | Note |
|---|---|---|
| Draft/Planned | `POST /api/v0.3/lookup/phone` | not in route table |
| Draft/Planned | `POST /api/v0.3/lookup/email` | not in route table |
| Draft/Planned | `POST /api/v0.3/me/import/device` | not in route table |
| Draft/Planned | `POST /api/v0.3/me/import/ticket` | not in route table |
| Draft/Planned | `POST /api/v0.3/me/import/order` | not in route table |

## 4. Compliance + Security Baseline (Landed)
- PIPL consent is mandatory on phone OTP send/verify (`consent` accepted).
- Lookup flows are auditable (`LookupEventLogger`).
- Rate limits exist at route middleware and controller service levels.
- `lookup/device` and `lookup/order` are identity-gated (`FmTokenAuth`).

## 5. Open Gaps (Documented, not hidden)
- FormRequest classes exist for phone send/verify with stricter E.164 + scene enum, but current routes execute controller-level validation.
- `lookup/order` currently returns some failures as `200 + ok=false` (legacy compatibility), not strictly 4xx.

## 6. Change Control
When routes or controller validation changes, this document must be updated in the same PR.
