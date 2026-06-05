# DAILY-GIVING-RECORD-REVIEW-TEMPLATE-01

asset_id: `DAILY-GIVING-RECORD-REVIEW-TEMPLATE-01`
date: `2026-06-04`
status: `draft_input_only`
publish_allowed: `false`
cms_draft_created: `false`
requires_operator_review: `true`
content_owner: `GPT-5.5 Pro`
final_authority: `CMS/backend`

> This is a content asset input package. It is not final public copy, not a CMS draft, not a publish approval, not a trust badge approval, and not a social distribution approval.

## 1. Purpose

A checklist for the first real donation record before it can become public.

## 2. Record Input Fields

```yaml
recipient_name: United Nations Foundation
donation_amount: 10
currency: CNY
donation_date: YYYY-MM-DD
operator: required
raw_receipt_private_path: required
redacted_public_proof_url: required_or_withheld_reason
proof_status: pending_redaction | redacted | withheld
is_public: false_until_approved
is_indexable: false
published_at: null_until_approved
```

## 3. Review Steps

1. Confirm donation occurred.
2. Confirm recipient is United Nations Foundation.
3. Confirm amount is CNY 10 or record variance.
4. Confirm raw proof is private.
5. Confirm public proof is redacted or withheld with reason.
6. Confirm public note has no official partnership/endorsement claim.
7. Confirm API output would not include private fields.
8. Confirm page remains noindex.
9. Confirm sitemap/llms remain absent.
10. Confirm social sync uses safe manual factual framing only.

## 4. Decision States

```yaml
private_only:
  use_when: record exists but proof/review not ready

public_noindex:
  use_when: record and proof pass public API gate but indexability not approved

indexability_candidate:
  use_when: multiple records and proof/storage/claim gates have passed

trust_badge_candidate:
  use_when: separate trust badge readiness passes
```

## 5. Explicit Non-Approval

This template does not approve:

- trust badge
- indexability
- search submission
- social amplification claims
- official relationship claims
