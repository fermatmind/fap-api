# CHANGED_FILES

Modified:
- `backend/database/seeders/ScaleRegistrySeeder.php`
  - Expanded MBTI zh `content_i18n_json.zh.faq` from 4 to 8 approved claim-safe items.
- `backend/tests/Feature/V0_3/ScalesLookupSeoMetadataTest.php`
  - Updated focused lookup FAQ test to assert the exact 8-item question list, answer evidence, and forbidden-claim exclusions.
- `docs/codex/pr-train.yaml`
  - Clarified the PR-train scope for the approved 8-item FAQ expansion.
- `docs/codex/pr-train-state.json`
  - Recorded dependency reconciliation, implementation progress, latest local checks, and validation evidence for this run.

Added:
- `generated/seo-ops-mbti-main-free-faq-content-01-20260705-00/EXECUTIVE_DECISION.md`
- `generated/seo-ops-mbti-main-free-faq-content-01-20260705-00/FAQ_CONTENT_CHANGE_REPORT.md`
- `generated/seo-ops-mbti-main-free-faq-content-01-20260705-00/FAQ_SCHEMA_PARITY_CONFIRMATION.md`
- `generated/seo-ops-mbti-main-free-faq-content-01-20260705-00/CLAIM_PRIVATE_URL_RECHECK.md`
- `generated/seo-ops-mbti-main-free-faq-content-01-20260705-00/LOCAL_LOOKUP_READBACK.md`
- `generated/seo-ops-mbti-main-free-faq-content-01-20260705-00/TEST_RESULTS.md`
- `generated/seo-ops-mbti-main-free-faq-content-01-20260705-00/CHANGED_FILES.md`
- `generated/seo-ops-mbti-main-free-faq-content-01-20260705-00/NEXT_EXACT_AUTHORIZATION_PROMPTS.md`
- `generated/seo-ops-mbti-main-free-faq-content-01-20260705-00/scan_manifest.json`

Scope exclusions confirmed:
- No `page_blocks`.
- No fap-web.
- No sitemap or llms.
- No schema policy.
- No search submission.
- No deploy or production CMS write.

