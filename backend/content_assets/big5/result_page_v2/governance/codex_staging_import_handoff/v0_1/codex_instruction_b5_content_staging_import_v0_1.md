# Codex 指令：B5-CONTENT-HANDOFF-1｜Big Five Content Staging Import

模式：execute
风险级别：L1

## Goal

在干净的 `fap-api` worktree 中，导入并校验 Big Five 当前已完成的 staging 内容资产包。

本任务只做 staging content_assets import、checksum/coverage validation、scan-only reports。不得接 runtime，不得修改 app/runtime/controller/scoring/frontend，不得生成新正文。

## Known context

需要导入的 package 包括：

- B5-CONTENT-0
- B5-A-lite
- B5-B1 O59 canonical core body
- B5-B1 rendered preview QA
- B5-CONTENT-1 ~ B5-CONTENT-7
- 325 selector-ready assets v0.3 P0 full candidate
- Golden Cases + Selection Policy + Conflict Resolution QA policy pack

所有 package 都必须保持 staging-only 或 not_runtime。

## Hard invariants

- 不生成新正文。
- 不重写 body_zh。
- 不改 runtime。
- 不改 routes/controllers/scoring/models/migrations。
- 不改 frontend runtime。
- 不改 backend `content_packs/**`。
- 不把任何 package 标成 production-ready。
- 不把 QA / Policy 包当正文资产。
- 不把 325 selector assets 当 production-ready。
- 不让 frontend fallback 生成解释正文。

## Source of truth priority

1. B5-CONTENT-HANDOFF-1 文件包。
2. B5-CONTENT-7 Master Asset Catalog。
3. B5-A-lite source authority / module mapping。
4. B5-B1 / B5-B1-Preview。
5. B5-CONTENT-0~6。
6. 325 selector-ready assets and QA policy pack as staging-only.

## In scope

- Create staging directories under `backend/content_assets/big5/result_page_v2/**`.
- Copy package files into the target paths listed in `big5_codex_import_path_plan_v0_1.json`.
- Preserve source package README / manifest / SHA256SUMS.
- Add validation scripts or tests if needed, but only for staging validation.
- Generate an import validation report.

## Out of scope

- Runtime wiring.
- Composer integration.
- API response changes.
- Frontend renderer changes.
- CMS import.
- Production import.
- New content writing.

## Must change

Only add staging content asset files and validation/report files necessary for this import.

## Must not touch

- `backend/app/**`
- `backend/routes/**`
- `backend/database/**`
- `backend/content_packs/**`
- `fap-web/**`
- scoring services
- controllers
- models
- migrations

## Acceptance

- All imported package files are present at planned staging paths.
- JSON / JSONL / CSV parse checks pass.
- SHA256SUMS checks pass.
- Runtime flags remain `staging_only` or `not_runtime`.
- `production_use_allowed` remains false everywhere.
- 3125 route matrix keeps 3125 rows, five shards of 625 rows each.
- O59 row `O3_C2_E2_A3_N4` is present and maps to `sensitive_independent_thinker`.
- No new body copy was generated.
- No runtime files changed.

## Output contract

Return:

- changed files
- package import summary
- validation command list
- validation results
- runtime no-touch proof
- blockers / pending items
- next recommended Codex task

## Done when

The staging import exists in `fap-api`, all validation checks pass, and no runtime/frontend/content_pack files are changed.
