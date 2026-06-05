# PUBLIC-BENEFIT-CLAIM-BOUNDARY-01

asset_id: `PUBLIC-BENEFIT-CLAIM-BOUNDARY-01`
date: `2026-06-04`
status: `draft_input_only`
publish_allowed: `false`
cms_draft_created: `false`
requires_operator_review: `true`
content_owner: `GPT-5.5 Pro`
final_authority: `CMS/backend`

> This is a content asset input package. It is not final public copy, not a CMS draft, not a publish approval, not a trust badge approval, and not a social distribution approval.

## 1. Purpose

Define allowed, forbidden, and evidence-required claims for Foundation and DailyGiving. This prevents public-benefit language from outrunning available records, proof, storage gates, or legal review.

## 2. Current Operator Decisions

```yaml
donation: today
daily_amount: CNY 10
recipient: United Nations Foundation
daily_giving_indexability: keep noindex
public_record_allowed: after record and proof gate
trust_badge: not allowed now
social_sync: operator starts manual video/image updates today
```

## 3. Allowed Claims

Allowed only when phrased as an independent project plan or audited record boundary:

```yaml
allowed:
  - "FermatMind maintains an independent public-benefit plan."
  - "The intended recipient for the daily giving plan is United Nations Foundation."
  - "Daily records require internal review before public display."
  - "Original proof is handled privately."
  - "Public proof is redacted when sensitive fields are present."
  - "DailyGiving remains noindex until the release gates pass."
  - "A public record may be withheld if public proof cannot be safely displayed."
```

## 4. Evidence-Required Claims

These require actual evidence before use:

```yaml
evidence_required:
  "donated today":
    evidence:
      - private receipt exists
      - record created or operator ledger exists
      - date verified
      - recipient verified
  "public record exists":
    evidence:
      - completed_or_verified_record
      - is_public=true
      - published_at not future
      - public API returns record
  "proof available":
    evidence:
      - redacted public proof URL
      - proof review passed
      - private proof not public
  "withheld proof":
    evidence:
      - withheld reason exists
      - reviewer approved
      - no trust badge claim
  "daily amount":
    evidence:
      - operator policy says CNY 10
      - record amount matches or variance explained
  "safe public ledger":
    evidence:
      - API smoke passed
      - no private fields exposed
      - claim lint passed
      - noindex/indexability policy recorded
```

## 5. Forbidden Claims

Never use unless separate legal documentation exists:

```yaml
forbidden:
  - "UN official partner"
  - "联合国官方合作"
  - "official endorsement"
  - "背书"
  - "官方认证"
  - "certified by United Nations Foundation"
  - "authorized by the UN"
  - "approved by the UN"
  - "official fundraising partner"
  - "guaranteed impact"
  - "donation impact guaranteed"
  - "registered foundation"
  - "nonprofit organization" # unless legal status is documented
  - "all records are public" # until API and record gate prove it
  - "stable daily giving operation" # until enough records exist and review passes
```

## 6. Before vs After Public Record

### Before a public record exists

Allowed:

- plan language
- governance language
- proof readiness language
- noindex / gated status
- operator-run private ledger language

Forbidden:

- "public daily giving ledger is live"
- "verified daily donation record"
- trust badge
- social proof as if public API is nonzero

### After first public record passes gates

Allowed:

- "a public record is available"
- "record reviewed under the DailyGiving release gate"
- "public proof is redacted" or "proof withheld under approved privacy reason"

Still forbidden:

- official partnership
- guaranteed impact
- trust badge unless separate trust badge readiness passes
- broad claims that all future records will be public

## 7. Social Sync Boundary

Because the Operator will start social video/image updates today, social posts must use **manual factual update framing** only.

Allowed social framing categories:

```yaml
allowed_social:
  - "operator note"
  - "today's independent giving action"
  - "private ledger entry pending review"
  - "redacted proof will be prepared before public ledger use"
```

Forbidden social framing:

```yaml
forbidden_social:
  - "official UN partner"
  - "certified by UN"
  - "verified public ledger is live" unless public API proves it
  - "trust badge"
  - "guaranteed impact"
  - "donation receipt raw screenshot with payment/email/account data"
```

## 8. Trust Badge Boundary

Current decision:

```yaml
trust_badge_allowed: false
```

A trust badge cannot be generated from:

- zero public records
- withheld-only records
- private receipts
- unreviewed proof
- social posts
- operator notes

Future trust badge source must be a backend public-safe aggregate endpoint, not page copy.

## 9. Codex QA

Codex should enforce claim lint for:

- official partnership terms
- endorsement/certification terms
- guaranteed impact terms
- unverified public ledger claims
- trust badge presence before readiness
- raw proof leakage
