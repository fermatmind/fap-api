# Codex Handoff Notes

Date: 2026-06-04

This package is for Window 7: Public Benefit / Foundation / DailyGiving.

## Attachments for Codex

- `00_OPERATOR-DECISIONS.yaml`
- `01_FOUNDATION-TRUST-PAGE-CONTENT-ASSET-01.yaml`
- `02_PUBLIC-BENEFIT-CLAIM-BOUNDARY-01.yaml`
- `03_DAILY-GIVING-PROOF-REDACTION-SOP-01.yaml`
- `04_DAILY-GIVING-RECORD-REVIEW-TEMPLATE-01.yaml`
- `index.json`

## Recommended Codex PR train after ingesting this zip

First batch, docs/contracts/readiness only:

1. `FOUNDATION-TRUST-PAGE-ASSET-INVENTORY-01`
2. `FOUNDATION-CLAIM-BOUNDARY-CONTRACT-01`
3. `FOUNDATION-CONTENT-REQUEST-CARD-01`
4. `FOUNDATION-CMS-FIELD-MAP-01`
5. `FOUNDATION-FAQ-SCHEMA-GATE-01`
6. `DAILY-GIVING-PROOF-STORAGE-GATE-01`
7. `DAILY-GIVING-PUBLIC-RELEASE-PREREQ-01`
8. `DAILY-GIVING-PUBLIC-API-SMOKE-01`
9. `DAILY-GIVING-INDEXABILITY-GATE-01`

Do not execute production record creation or CMS mutation unless separately authorized.

## Hard NO-GO

- Do not create trust badge.
- Do not make DailyGiving indexable.
- Do not submit DailyGiving to Google/Baidu/IndexNow.
- Do not expose proof_private_path.
- Do not use raw receipt screenshots in public artifacts.
- Do not imply United Nations or United Nations Foundation endorsement, certification, partnership, authorization, or guaranteed impact.
- Do not treat social sync as proof that public ledger is live.
