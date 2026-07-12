# zh-CN Enneagram 13-Page CMS Package v1

**Package**: ENNEAGRAM-ZH13-CMS-PACKAGE-NORMALIZE-01
**Target Command**: `personality:enneagram-cms-draft --write --update-existing`

## Files

- `enneagram-zh13-cms-package-v1.json` — Normalized 13-row CMS import package
- `enneagram-zh13-cms-qa-v1.json` — Companion QA artifact for import command

## Scope

This package contains **all 13 zh-CN Enneagram content assets** normalized for fap-api CMS draft/import:

| Group | Count | Paths |
|-------|:---:|-------|
| Hub | 1 | `/zh/personality/enneagram` |
| Core Types | 9 | `/zh/personality/enneagram/type-1` through `type-9` |
| Centers | 3 | `/zh/personality/enneagram/centers/gut`, `heart`, `head` |

## Content Summary

| Metric | Value |
|--------|-------|
| Total sections | 101 |
| Total FAQ items | 56 |
| Total body chars | ~38,956 |

## Source

- Hub + Types: `fap-web/output/.../cms/PACKAGE.json` (existing CMS import format)
- Centers: `fap-web/docs/.../zh-centers-v1/{key}-center.zh.json` (converted body[] → body_md)
- QA validated: `fap-web/docs/.../zh13-content-qa-2026-07-09.json` (PASS)

## Status

- **Not imported** into CMS
- **Not published**
- **Not indexable** (noindex until publish gate)
- **Not in sitemap** / llms / llms-full
- **Not search submitted**
- **Draft for backend import dry-run only**

## Evidence contract v1

13 页均包含可见的 `evidence_and_limitations` 章节，并附带两个可追踪的
source ID。`hook-2021` 只支持审慎的研究边界，不能验证页面或个人类型；
内部 claim-boundary 来源只记录禁止用途，不属于科学效度证据。中英文使用
相同稳定 source ID，但主张和限制说明分别完成本地化表达。

## Intended Import Flags

```
--dry-run before write
--write only after explicit operator approval
--update-existing
--draft-only
--no-publish --no-index --no-sitemap --no-llms --no-search-release
--operator-approved=ENNEAGRAM-CMS-DRAFT-WRITER-CONTRACT-01
```

## Next Task

**ENNEAGRAM-ZH13-CMS-IMPORT-DRY-RUN-01** — Run dry-run validation against production CMS.
