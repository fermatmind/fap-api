# Big Five W2 English Result Content Package

This directory contains 16 backend-authoritative English content asset groups for the frozen W2 Big Five result/report/share/PDF/history inventory.

The package is a producer draft for manual review. It is not a runtime registry, selector input, importer payload, CMS write authorization, W9 QA report, production release, or deployment artifact.

## Frozen source

- Inventory PR: `EN-PARITY-W2-BIG5-INVENTORY-01` / PR 1866
- Inventory merge: `897490e4baa31fe197ee50c89f0c3fae6bac408d`
- Inventory package SHA-256: `0f50f4108af14656442ef7d57d410b2e74f8dffced6ed3db372bf848ea051292`
- Inventory source-ledger SHA-256: `facbf57a362a430cdc8b5f6545db4a227e1268d285e2c27beed3c935ea9cf6e2`
- `inventory_row_reconciliation.json` preserves all 118 frozen row IDs: 52 completed public controls, 50 reconciled historical revisions, and 16 result-content rows mapped to exact draft asset IDs and required content/item keys.

## Safety and authority

- All assets are `pending_manual_review`.
- `runtime_use` is `draft_review_only`.
- Runtime, production, CMS, database, release, indexability, search, and deploy permissions remain false.
- Share copy contains no scores, percentiles, attempt IDs, tokens, or private links.
- PDF and history copy remain private-reader surfaces.
- Analytics reader labels contain no internal metric IDs or personal result data.
- The existing zh-CN registry, runtime selector, importer, schema, and public APIs are unchanged.

## Validation

Run:

```bash
php backend/content_packs/BIG5_OCEAN/v2/packages/en_parity/w2_result_content_v1/validate_package.php
cd backend && php artisan test tests/Feature/Content/BigFiveEnglishResultAssetPackageTest.php --no-ansi
```

Both checks fail closed on 118-row inventory drift, result-row or required-key mismatch, unit drift, duplicate coverage, malformed JSON/JSONL, CJK leakage, private-field leakage, forbidden claims, permission drift, or SHA mismatch.
