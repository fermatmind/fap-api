# FOUNDATION-TRUST-PAGE-CONTENT-ASSET-01

asset_id: `FOUNDATION-TRUST-PAGE-CONTENT-ASSET-01`
date: `2026-06-04`
status: `draft_input_only`
publish_allowed: `false`
cms_draft_created: `false`
requires_operator_review: `true`
content_owner: `GPT-5.5 Pro`
final_authority: `CMS/backend`

> This is a content asset input package. It is not final public copy, not a CMS draft, not a publish approval, not a trust badge approval, and not a social distribution approval.

## 1. Purpose

Prepare the Foundation page as a stable public brand trust asset. The page should explain FermatMind's public-benefit direction, governance boundaries, evidence rules, and relationship to future DailyGiving records.

This package does **not** write final page copy.

## 2. Current Strategic Decision

Foundation may be used as a public brand trust surface. DailyGiving must stay separate from Foundation until its record/proof gates pass.

Foundation should answer:

1. What is FermatMind trying to build?
2. Why does a testing/career-decision platform need public-benefit governance?
3. What evidence will be used before any daily giving record is public?
4. What does FermatMind not claim?
5. How are privacy, proof, and review handled?
6. How does this relate to user trust without implying official third-party endorsement?

## 3. Page Module Structure

### Module A — Project identity and purpose

Answers:

- What is FermatMind / 费马测试?
- What kind of public-benefit posture does it intend to maintain?
- Why is the Foundation page part of the product trust system?

Fields:

```yaml
module_key: project_identity
required_fields:
  - plain_language_purpose
  - product_boundary
  - public_benefit_direction
  - non_affiliation_notice
```

### Module B — Independent giving plan

Answers:

- Is there an independent giving plan?
- What is the daily intended amount?
- Who is the intended recipient?
- What must be true before records are public?

Operator policy to encode:

```yaml
daily_amount:
  amount: 10
  currency: CNY
  frequency: daily
recipient: United Nations Foundation
relationship_boundary: recipient_only_no_official_partnership
```

### Module C — Evidence before amplification

Answers:

- What evidence is required before a record appears publicly?
- What is the difference between a private receipt and public proof?
- Why can a record be withheld?

Fields:

```yaml
record_evidence_policy:
  requires_record: true
  requires_review: true
  requires_public_proof_or_withheld_reason: true
  public_proof_must_be_redacted_if_sensitive: true
  raw_receipt_private: true
```

### Module D — Privacy and proof handling

Answers:

- Where are original receipts stored?
- What gets redacted?
- What never appears in public API or public pages?

Fields:

```yaml
proof_storage_summary:
  original_receipt_location: private_disk_or_private_bucket
  public_proof_location: redacted_public_media_or_signed_public_url
  private_admin_access: signed_admin_url_or_private_disk
```

### Module E — What this page does not claim

Answers:

- Does FermatMind claim official partnership?
- Does it claim certification or endorsement?
- Does it guarantee impact?
- Does it claim nonprofit/foundation legal status?

Hard answer: no.

### Module F — Future DailyGiving ledger relationship

Answers:

- Why does DailyGiving remain noindex?
- When can it become a public ledger?
- Why is trust badge blocked for now?

Fields:

```yaml
daily_giving_state:
  current_indexability: noindex
  public_record_allowed_after_gates: true
  trust_badge_allowed_now: false
  social_sync_allowed: operator_manual_safe_language_only
```

## 4. CMS Fields

```yaml
content_type: content_page
slug:
  zh: foundation
  en: foundation
fields:
  title: required_later
  summary: required_later
  body_md: required_later
  faq_items: optional_later
  claim_boundary_version: required
  public_benefit_policy_version: required
  proof_policy_summary: required
  reviewer: required
  legal_review_required: true
  science_review_required: false
  updated_at: system_or_reviewer
  is_public: true
  is_indexable: true
  schema_enabled: conditional
```

## 5. FAQ Question Inventory

Draft questions only, not final answers:

1. What is FermatMind's public-benefit plan?
2. Is FermatMind officially affiliated with United Nations Foundation?
3. What does the daily giving record prove?
4. Why is the DailyGiving ledger not fully public yet?
5. What is the difference between original proof and public proof?
6. Can a record be withheld?
7. What information is removed from public proof?
8. When can a DailyGiving record become public?
9. Does the public-benefit plan change the price or report access?
10. How does FermatMind prevent unsupported claims?

## 6. Claim Boundary

Foundation may say:

- FermatMind maintains an independent public-benefit plan.
- The intended recipient is United Nations Foundation.
- Daily giving records require review before public display.
- Public proof may be redacted or withheld under policy.
- Original proof is handled privately.

Foundation must not say:

- official UN partner
- officially endorsed by the UN
- certified by United Nations Foundation
- authorized fundraiser
- guaranteed impact
- nonprofit/foundation legal status unless separately documented
- every day has public proof before records exist and pass gates

## 7. Publish Prerequisites

- Claim boundary contract exists.
- CMS fields mapped.
- DailyGiving current state is accurately represented.
- Recipient wording reviewed.
- No DailyGiving trust badge.
- No statement that DailyGiving is indexable.
- No proof or record count claim unless API shows it.
- Operator review complete.

## 8. Codex Follow-up

Codex should not write final page copy. Codex may validate:

- route status
- CMS source of truth
- public claim lint
- sitemap/llms state
- FAQ schema consistency if FAQ added
- no accidental official endorsement wording
