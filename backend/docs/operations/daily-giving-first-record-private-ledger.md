# DailyGiving First Record Private Ledger

Date: 2026-06-05

PR train item: `DAILY-GIVING-FIRST-RECORD-PRIVATE-LEDGER-01`

Mode: private-ledger execution record, generated redacted artifact, and contract test. The raw proofs and full private ledger were created outside the repository. This PR does not create a production database record, publish DailyGiving, index DailyGiving, create a trust badge, submit search URLs, run social distribution, deploy, mutate CMS, or expose raw proof.

## Decision

The first DailyGiving private draft record is ready for backend private storage review only. The real proof shows a completed CNY transaction to UNICEF, so this gate supersedes the earlier planned CNY 10 / United Nations Foundation operator intent from the review template.

## Private Draft Record

| Field | Value |
| --- | --- |
| `record_code` | `FM-GIVING-2026-06-001` |
| `donation_date` | `2026-06-05` |
| `donation_time_local` | `18:52:53` |
| `recipient_name` | `联合国儿童基金会（UNICEF）` |
| `recipient_official_url` | `https://www.unicef.cn/` |
| `amount_minor` | `7500` |
| `currency` | `CNY` |
| `donation_status` | `completed` |
| `proof_status` | `redacted_pending` |
| `is_public` | `false` |
| `is_indexable` | `false` |
| `published_at` | `null` |

## Private Proof Handling

- Raw transaction proof was copied to a local private operator storage location outside this repository.
- Raw UNICEF monthly donation receipt proof was copied to a local private operator storage location outside this repository.
- A local private ledger was created outside this repository.
- Raw proofs are not committed.
- The private ledger is not committed.
- Transaction serial, masked account details, balance, and local device UI metadata are not committed.
- The receipt donor-facing ID is not committed.
- `proof_public_url` remains empty.
- Redacted public proof has not been created.

## Claim Boundary

- This record may state recipient-only support for UNICEF after public review.
- It must not imply UNICEF endorsement, official partnership, certification, guaranteed impact, or any official relationship with FermatMind.
- It must not be used as a trust badge.
- It must not be used for paid-page trust claims or public amplification.

## Required Next Gate

Before any public visibility:

- Mirror or upload raw proof to a backend-confirmed private disk or bucket.
- Create a separate redacted public proof artifact, likely from the receipt proof rather than the transaction screenshot.
- Review the redacted artifact for receipt ID, transaction serial, account, balance, private URL, token, and local device metadata leakage.
- Create or update the production DailyGiving record only through an authorized private backend path.
- Keep `is_public=false` until proof and claim review pass.
- Keep `is_indexable=false`.
- Keep DailyGiving out of sitemap, `llms.txt`, and `llms-full.txt`.
- Run public API smoke after any later public activation.

## Hard Stop

Stop before public proof creation, production public activation, publish, index, trust badge, search submission, social distribution, deploy, or CMS mutation unless explicitly authorized by the operator.
