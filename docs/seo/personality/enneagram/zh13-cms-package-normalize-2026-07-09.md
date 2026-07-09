# ENNEAGRAM-ZH13-CMS-PACKAGE-NORMALIZE-01 — Normalization Report

**Date**: 2026-07-09
**Repo**: fap-api
**Status**: **CONDITIONAL** (static checks pass; local dry-run deferred to dedicated PR)

---

## Package Summary

| Metric | Value |
|--------|-------|
| Pages | 13 |
| Hub | 1 |
| Core Types | 9 |
| Centers | 3 |
| Total sections | 101 |
| Total FAQ | 56 |
| Total body chars | ~38,956 |
| JSON valid | ✅ |
| Backend contract compatible | ✅ |
| Forbidden routes | 0 |
| Forbidden claims | 0 |

## Source Inputs

| Source | Location | Used For |
|--------|----------|----------|
| QA artifact | `fap-web/docs/.../zh13-content-qa-2026-07-09.json` | QA PASS confirmation |
| Hub+Types package | `fap-web/output/.../cms/PACKAGE.json` | Hub + 9 Type pages (already contract-compatible) |
| Center JSONs | `fap-web/docs/.../zh-centers-v1/{key}-center.zh.json` | 3 Center pages (converted body[] → body_md) |

## Backend Contract Mapping

**Target command**: `personality:enneagram-cms-draft`

**EnneagramCmsDraftWriter expected shape**:
```json
{
  "recommendations": [
    {
      "framework": "enneagram",
      "target_url": "/zh/personality/enneagram/type-1",
      "recommendations": {
        "title": "SEO title",
        "h1": "H1 heading",
        "description": "Meta description",
        "quick_answer": "Summary",
        "sections": [{"key": "...", "title": "...", "body_md": "..."}],
        "faq": [{"q": "...", "a": "..."}],
        "internal_links": [{"label": "...", "url": "..."}]
      }
    }
  ]
}
```

**Compatibility**: ✅ Hub+Types already use this format. Centers converted from `body[]` to `body_md` strings. `normalizeSections()` in the writer accepts both `body_md` and `body_html`.

**`--update-existing` flag**: Added in PR #2821. This package is designed for `--write --update-existing` to backfill content on existing `content_ready` assets. State fields (launch_state, is_public, robots) are NOT included in the package — the writer preserves them on update.

## 13-Row Table

| # | Path | Entity | Sections | FAQ |
|---|------|--------|:---:|:---:|
| 1 | `/zh/personality/enneagram` | hub | 11 | 7 |
| 2 | `/zh/personality/enneagram/type-1` | core_type | 7 | 4 |
| 3 | `/zh/personality/enneagram/type-2` | core_type | 7 | 3 |
| 4 | `/zh/personality/enneagram/type-3` | core_type | 7 | 4 |
| 5 | `/zh/personality/enneagram/type-4` | core_type | 7 | 3 |
| 6 | `/zh/personality/enneagram/type-5` | core_type | 7 | 4 |
| 7 | `/zh/personality/enneagram/type-6` | core_type | 7 | 3 |
| 8 | `/zh/personality/enneagram/type-7` | core_type | 7 | 3 |
| 9 | `/zh/personality/enneagram/type-8` | core_type | 7 | 3 |
| 10 | `/zh/personality/enneagram/type-9` | core_type | 7 | 3 |
| 11 | `/zh/personality/enneagram/centers/gut` | center | 9 | 7 |
| 12 | `/zh/personality/enneagram/centers/heart` | center | 9 | 6 |
| 13 | `/zh/personality/enneagram/centers/head` | center | 9 | 6 |

## Route Safety

✅ All internal links use only safe public canonical routes:
- `/zh/personality/enneagram` and sub-paths
- `/zh/tests/enneagram-personality-test-nine-types`
- `/zh/articles/enneagram-personality-test-explained`

❌ No forbidden routes found (`/result`, `/orders`, `/share`, `/pay`, `/private`, query tokens).

## Claim Boundary

✅ All 13 rows include appropriate claim boundary / method boundary language.
✅ No positive deterministic, clinical, hiring, or official claims detected.

## Known Holds

- Not imported into CMS (no `--write` executed)
- Not written to production DB
- Not published
- Not indexable
- Not in sitemap / llms / llms-full
- Not search submitted

## Status: CONDITIONAL

Local dry-run could not be executed (SQLite DB not configured for CMS commands). Static contract compatibility is confirmed via source code inspection of `EnneagramCmsDraftWriter::normalizeSections()`, `assetPayload()`, and `recommendations()`.

**Recommendation**: Proceed to dedicated dry-run PR (`ENNEAGRAM-ZH13-CMS-IMPORT-DRY-RUN-01`) with proper database access.

## Exact Next Task

```bash
# In fap-api with proper DB access:
php artisan personality:enneagram-cms-draft \
  --package docs/seo/personality/enneagram/content-packages/zh13-cms-v1/enneagram-zh13-cms-package-v1.json \
  --qa docs/seo/personality/enneagram/content-packages/zh13-cms-v1/enneagram-zh13-cms-qa-v1.json \
  --dry-run --update-existing --json
```

**No CMS write / import / publish / search release occurred in this PR.**
