# DailyGiving First Record Review Template

Date: 2026-06-05

PR train item: `DAILY-GIVING-FIRST-RECORD-REVIEW-TEMPLATE-01`

Mode: review template, generated artifact, and contract test only. This PR does not create production records, upload proof, process proof files, mutate CMS, publish, index DailyGiving, create trust badges, submit search URLs, run social distribution, call payment providers, deploy, or read secrets.

## Decision

The first real DailyGiving record must be created only after a separate private-ledger authorization. Until that authorization exists, the correct asset state is a reviewed template and a hard stop before any raw receipt, private proof path, or public record mutation.

## Preconditions Before First Private Ledger Action

- Proof storage gate is deployed and active for every save path.
- Raw receipt storage location is confirmed private at the disk or bucket level.
- Operator has the real receipt/proof in hand.
- Redaction reviewer is assigned.
- DailyGiving remains `noindex`.
- DailyGiving remains absent from sitemap, `llms.txt`, and `llms-full.txt`.
- Foundation page is complete enough to explain plan boundaries without relying on DailyGiving as a trust badge.
- Claim lint is clean for official relationship, endorsement, certification, guaranteed-impact, and unsupported stable-operation implications.

## First Draft Record Inputs

The first record must start as private draft state:

| Field | Required initial value or source | Review rule |
| --- | --- | --- |
| `record_code` | generated format `FM-GIVING-YYYY-MM-NNN` | no private token or order id |
| `donation_date` | receipt date | must match receipt |
| `recipient_name` | receipt recipient | recipient-only; no official relationship implication |
| `recipient_official_url` | public recipient website if verified | public canonical only |
| `amount_minor` | receipt amount in minor units | for planned first donation, CNY 10 means `1000` only after receipt supports it |
| `currency` | receipt currency | ISO code, expected `CNY` only after receipt supports it |
| `donation_status` | `planned` or private draft equivalent before review | may become `completed` only after receipt review |
| `proof_status` | `none` or `redacted_pending` before redaction | `redacted_available` only after public proof review |
| `proof_private_path` | private disk/bucket path only | never a public URL |
| `proof_public_url` | blank until redacted proof is approved | must be reviewed redacted public media if present |
| `proof_redaction_notes` | admin-only reviewer notes | required when proof is withheld |
| `receipt_reference_private` | private reference if needed | never public |
| `receipt_reference_redacted` | masked public reference if safe | reviewer-approved only |
| `public_notes` | blank or claim-linted note | no endorsement or guaranteed-impact implication |
| `internal_notes` | admin-only context | never public |
| `is_public` | `false` before final review | may become true only after review gates pass |
| `is_indexable` | `false` | must remain false for first record |
| `published_at` | blank before final review | set only after completed/verified public review |

## Review Gates Before Public Visibility

- Raw proof is stored privately and not exposed as URL, public media, or tokenized path.
- Public proof is either a reviewed redacted public media URL or withheld with admin-only reviewer reason.
- Public projection does not expose private proof path, redaction notes, private receipt reference, internal notes, or admin user ids.
- Recipient, amount, currency, and date match the receipt.
- `donation_status` is `completed` or `verified`.
- `is_public=true` is approved only after proof and claim review.
- `is_indexable=false` remains set.
- Records API returns at least one public record after activation.
- Months API returns at least one month after activation.
- DailyGiving page remains `noindex`.
- sitemap, `llms.txt`, and `llms-full.txt` remain free of DailyGiving entries.
- Trust badge remains blocked.

## Required Post-Activation Smoke

After the separately authorized first record is reviewed and activated:

- records API total is greater than zero;
- months API count is greater than zero;
- public API returns no private fields;
- DailyGiving page metadata remains `noindex`;
- sitemap and llms surfaces exclude DailyGiving;
- claim lint remains clean;
- public amplification remains blocked unless a later explicit gate allows it.

## Hard Stop

The next step is `DAILY-GIVING-FIRST-RECORD-PRIVATE-LEDGER-01`. It must be separately authorized before any production record, raw proof, private proof path, redacted proof, public record activation, CMS mutation, publish, search submission, social distribution, trust badge, or deploy action.
