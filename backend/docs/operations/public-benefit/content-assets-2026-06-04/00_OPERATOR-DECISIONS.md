# Operator Decisions — Window 7

asset_id: `WINDOW7-OPERATOR-DECISIONS`
date: `2026-06-04`
status: `draft_input_only`
publish_allowed: `false`
cms_draft_created: `false`
requires_operator_review: `true`
content_owner: `GPT-5.5 Pro`
final_authority: `CMS/backend`

> This is a content asset input package. It is not final public copy, not a CMS draft, not a publish approval, not a trust badge approval, and not a social distribution approval.

## Decisions

```yaml
today_donation: true
daily_amount:
  amount: 10
  currency: CNY
  display_amount: true
recipient:
  fixed: true
  name: United Nations Foundation
daily_giving_noindex: true
public_record_allowed: after_record_and_proof_gate
trust_badge_allowed_now: false
social_sync_starts_today: true
```

## Important clarification

`proof public policy: public proof required, withheld allowed` is interpreted as:

- Public proof is required for public amplification and trust use.
- A record may use `proof_status=withheld` only as a privacy/safety exception approved by reviewer.
- Withheld-proof records must not power a trust badge or high-amplification claim.

## Redaction decision

The public proof redaction standard is not "no redaction." The safe rule is:

- no redaction is acceptable only when the proof contains no sensitive fields after inspection;
- otherwise sensitive fields must be redacted before the proof can be public.
