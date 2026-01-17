# Specification: Identity Fields & Compliance (Phone SSOT + Multi-Provider Identities)
> Doc: docs/content/identity-field-spec.md  
> Version: 1.0  
> Scope: Phone / Ticket / Email + WeChat/Douyin/Baidu identities + Compliance + Anti-abuse

---

## 1. 核心定义（必须写死）

### 1.1 Phone Number = SSOT（跨端共享唯一主键）
- Role: Primary Identity Key (跨端唯一主键)
- Any platform that wants cross-device/cross-app access must ultimately bind to Phone SSOT.
- Third-party identities (wechat/douyin/baidu) are *login methods*, not the SSOT key.

### 1.2 Ticket Code = Anonymous Credential（匿名强兜底）
- Role: Anonymous access credential for report lookup
- Users can retrieve report across devices without providing PII.

### 1.3 Email = Recovery Channel（可选通道，不作为主键）
- Role: Recovery & support notification channel
- Not the primary identity key for cross-platform sharing.

---

## 2. 字段规范（Field Spec）

### 2.1 Phone (SSOT)
- Storage format: **E.164** (e.g. `+8613800000000`)
- Uniqueness: phone_e164 must be unique across users
- Verification methods:
  1) Web/H5/App: SMS OTP (6 digits, TTL 5 minutes)
  2) Mini Programs: Platform one-tap phone retrieval
     - WeChat: getPhoneNumber
     - Douyin: getPhoneNumber
     - Baidu: platform-provided phone capability (if available)

- Hashing:
  - phone_hash = SHA-256(normalized_e164) for fast lookup
  - Store raw phone in protected storage if required; keep least-privilege access

### 2.2 Ticket Code
- Format: `FMT-{RANDOM_8_CHARS_UPPERCASE}` or `FMT-XXX-XXX` (readable)
- Properties:
  - Generated server-side using CSPRNG
  - Unique index
  - Displayed on Result Page; users can copy/screenshot
- Lookup:
  - Rate limited per IP
  - Audited (lookup_events)

### 2.3 Email (Optional)
- Validation: standard email format
- Storage:
  - email_hash = SHA-256(lowercase(trim(email)))
  - raw email optional, recommended encrypted at rest
- Usage:
  - Lookup + support notification only
  - Must not be used for marketing unless explicit opt-in

### 2.4 Third-party identities (WeChat/Douyin/Baidu)
- identities table fields (logical):
  - provider: `wechat` | `douyin` | `baidu` | `phone` | `email`
  - provider_uid: openid/unionid/platform_uid (string)
  - user_id: points to SSOT user
  - linked_at, verified_at
- Uniqueness:
  - (provider, provider_uid) must be unique -> cannot bind to multiple users

- Recommended preference:
  - WeChat: use unionid if available; else openid
  - Douyin/Baidu: use platform unique uid/openid

---

## 3. 合规与告知（Compliance & Consent）

### 3.1 PIPL (China) Explicit Consent — Hard Rule
For any action that triggers:
- “One-tap get phone number” OR
- “Send SMS code”

User must:
- Manually check consent checkboxes **before** the action is allowed.

Red lines:
- No default checked box
- No auto-checking upon button click
- If not checked: block action and show clear prompt (“请先阅读并同意...”)

### 3.2 Micro-copy（必须在 UI 输入框下方展示）
**CN**
> “您的手机号/邮箱仅用于账号登录、同步测试记录与找回报告/售后通知。我们不会用于营销打扰（除非您另行勾选同意）。”

**EN**
> “Your phone/email is only used for login, syncing your test records, and report retrieval/support. No marketing unless you explicitly opt in.”

### 3.3 Data Retention（保留期限）
- Recommended retention for identity channels (phone/email): 12 months configurable
- User “Right to Delete”: allow account deletion request
  - On deletion: user record soft-deleted; attempts/reports can be anonymized per policy

### 3.4 Right to Access / Right to Forget
- Right to Access: user can view reports associated with their phone SSOT
- Right to Forget: user can request unlinking identities; system should:
  - remove identity link
  - anonymize attempts if required by policy
  - keep aggregated stats without personal identifiers

---

## 4. 风控（Anti-Abuse & Security）

### 4.1 Rate limits (Recommended)
SMS:
- send_code: per phone 1/min, 5/hour; per IP 10/hour
- verify fail: 5 tries -> lock 30 minutes

Lookup:
- ticket lookup: per IP 10/min
- email lookup: per email 5/hour; per IP 10/min
- order lookup: stricter, e.g. per IP 3/min

### 4.2 Blind response strategy
To prevent enumeration:
- For phone/email lookup endpoints, recommended response:
  - “If the account exists, we have sent instructions.”
MVP can relax for UX (e.g., show masked list), but must enforce:
- strong rate limits
- audit logs
- optional CAPTCHA later

### 4.3 Audit log requirement
All identity and lookup actions must be logged:
- method, success/failure, ip, ua, timestamp, risk flags

---

## 5. 资产归集规则（MVP 简化）

### 5.1 One-way import (Anonymous -> Phone SSOT)
When user successfully binds phone:
- import local anonymous attempts (device/ticket/latest) into current user_id (APPEND)
- do not implement complex account merge in MVP

### 5.2 Conflict handling (MVP)
- If a third-party identity is already linked to another user:
  - do not auto-rebind
  - require support/SOP resolution
- If an anonymous attempt is already owned by another phone SSOT:
  - do not auto-merge
  - show safe message + suggest ticket lookup / support

---

## 6. Evidence & Documentation Requirements (for Task Package 5)
- This spec must be referenced by PRD and SOP
- PRD must explicitly state:
  - Phone is SSOT
  - Third-party identities are login methods
  - PIPL consent checkbox required before phone actions
  - MVP conflict strategy is one-way append

---