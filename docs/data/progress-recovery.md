# Progress Recovery (PR21)

Date: 2026-01-30

## 1) Overview
v0.3 attempts support draft progress storage with resume tokens.
- Drafts are stored in Redis cache (hot) and `attempt_drafts` (cold backup).
- A resume token is generated at start and returned once.
- The token is stored as `sha256(token + "|" + app.key)`.

## 2) Start
`POST /api/v0.3/attempts/start`
- Response includes:
  - `resume_token` (plain text, only returned on start)
  - `resume_expires_at` (timestamp)
- A draft row is created immediately with `last_seq=0` and empty answers.

## 3) Progress API
### PUT /api/v0.3/attempts/{attempt_id}/progress
- Header: `X-Resume-Token` **required for anonymous**.
- Authenticated users may omit token.
- Body:
  - `seq` (integer, monotonic)
  - `cursor` (string, optional)
  - `duration_ms`
  - `answers[]`

Rules:
- `seq` must be `>= last_seq`.
- For the same `question_id`, newer answers override older ones.
- Answers are merged and persisted to cache + DB.

### GET /api/v0.3/attempts/{attempt_id}/progress
- Same auth rules as PUT.
- Returns `cursor`, `duration_ms`, `answered_count`, `answers`, `updated_at`.

## 4) Error Codes
- `ATTEMPT_NOT_FOUND` (404): attempt not found in current org.
- `RESUME_TOKEN_REQUIRED` (401): missing token when no auth identity.
- `RESUME_TOKEN_INVALID` (401): token hash mismatch.
- `SEQ_OUT_OF_ORDER` (409): seq regressed (`seq < last_seq`).
- `RESUME_EXPIRED` (410): token expired.

## 5) Auth + Org isolation
- v0.3 routes are protected by `ResolveOrgContext`.
- Cross-org access returns 404.
- Progress does **not** emit analytics events.

## 6) Draft TTL
- Controlled by `DRAFT_TTL_DAYS` (default 14 days).
- Drafts expire at `resume_expires_at` and are cleared on successful submit.
