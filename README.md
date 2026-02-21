> Status: Active
> Last Updated: 2026-02-18
> Scope: Laravel API runtime + content packages + docs aligned to code truth

# FAP API (Laravel) + Content Packages

本仓库已是运行中系统，不再是“设计骨架阶段”。

## Current Runtime Snapshot
- API route total: `120` (`/api/*`, exclude vendor)
- Route groups:
  - `v0.3`: `82` routes (legacy MBTI flows, auth/lookup, admin, content packs, insights)
  - `v0.3`: `31` routes (attempt lifecycle, commerce, org/collaboration)
  - `v0.4`: `5` routes (boot + org assessments)
  - base: `2` routes (`/api/healthz`, `/api/user`)

## Code Truth Entry
- Route source: `backend/routes/api.php`
- Verify route truth:
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list --path=api --except-vendor
php artisan route:list --path=api --except-vendor --json
```

## Auth Model (Runtime)
- `Auth: Public`
- `Auth: Sanctum`
- `Auth: AdminAuth`
- `Auth: FmTokenAuth`
- `Auth: FmTokenOptional`
- `Auth: RequireOrgRole(owner|admin)`
- Feature gate overlay: `fap_feature:*`

## Runtime Docs Navigation
- 全局 API 契约（v0.3/v0.3/v0.4）: `01-api-design/MBTI-v0.3-endpoints.md`
- Lookup/Auth/Identity canonical PRD: `backend/docs/product/report-lookup-prd.md`
- 后端验收与运行手册: `backend/README.md`
- 文档总入口（含历史分期文档）: `docs/README.md`

历史快照（例如 `docs/03-stage1/`、`_deprecated`）只用于追溯，不作为当前契约。

## Local Setup / Verify
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
composer setup
php artisan route:list --path=api --except-vendor
bash scripts/verify_mbti.sh
```

CI 主链路：
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/ci_verify_mbti.sh
```

## Release Packaging Policy
- 发布产物只允许来自 `git archive`（例如 `bash scripts/package_release.sh`）或 CI 产物。
- 禁止直接压缩本地工作目录（例如 Finder/Explorer 手工 zip 工作区）。
- 原因：工作区可能包含未跟踪文件（如 `backend/.env`），会造成密钥泄露与误部署风险。

## Repository Layout
- `backend/`: Laravel API implementation (routes/controllers/services/migrations/tests)
- `content_packages/`: MBTI content packages and versioned assets
- `01-api-design/`, `02-db-design/`, `03-env-config/`, `04-analytics/`: current design/runtime docs
- `docs/`: product/process docs (including historical stage snapshots)
