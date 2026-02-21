# PR16 Recon — Content Pack schema/assets/URL resolver (IQ_RAVEN)

Date: 2026-01-29

## 相关入口文件（questions 输出链路）
- `backend/routes/api.php`
  - v0.2 已有 `/api/v0.3/content-packs/{pack_id}/{dir_version}/questions`
  - v0.2 MBTI questions: `MbtiController::questions()`
  - v0.3 目前仅有 scales index/show/lookup，无 questions endpoint
- `backend/app/Http/Controllers/MbtiController.php`
  - 直接读 content_packages/*/questions.json，固定 MBTI 144 题校验
- `backend/app/Http/Controllers/API/V0_2/ContentPacksController.php`
  - 使用 `ContentPacksIndex` 定位 questions.json 与 manifest.json
- `backend/app/Services/Content/ContentPacksIndex.php`
  - 以 pack_id + dir_version 扫描、缓存内容包索引
- `backend/app/Services/ContentPackResolver.php`
  - 通过 manifest 扫描与 fallback 链路构建（pack_id/scale/region/locale/version）

## pack 定位与读取关键点
- `content_packs.root` 指向 repo 根目录的 `content_packages`（local/s3 兼容）
- `ContentPacksIndex::find(pack_id, dir_version)` 为 v0.2 内容包读取主入口
- MBTI 读取直接按默认 region/locale/dir_version 组路径（`MbtiController`）

## FapSelfCheck 现有结构
- `backend/app/Console/Commands/FapSelfCheck.php`
  - `--strict-assets` 当前用于“禁止临时文件 + 已知文件未声明”
  - questions.json 校验：默认强校验 MBTI 144 题 + 维度/打分字段
  - `meta/landing.json` 为硬性门禁（必须存在且字段完整）

## assets 现状
- assets 仅在 manifest.assets 列表中存在，`version.json` 有 `assets_base_url` 字段
- 无统一 assets URL 解析层、无 questions 级 assets 透出规则
- selfcheck 仅校验 manifest.assets 是否存在/JSON schema 对齐

## 需要新增/修改点（文件/类/函数）
- `backend/routes/api.php`：新增 `/api/v0.3/scales/{scale_code}/questions`
- `backend/app/Services/Assets/AssetUrlResolver.php`：统一 assets URL 解析
- `backend/app/Services/Content/*`：questions 读取 + assets 映射层
- `backend/app/Console/Commands/FapSelfCheck.php`：strict-assets 增加 questions assets 路径校验
- `backend/app/Http/Controllers/API/V0_3/ScalesController.php`：questions API
- demo 包与自检脚本/文档（见 PR16 checklist）

## 风险与规避
- 不破坏 MBTI 输出：questions 资产映射仅在 v0.3 新接口内启用
- assets 字段不存在时保持原样，不引入新字段
- strict-assets 仅在 `--strict-assets` 打开时生效
- demo 包需包含 `meta/landing.json`，避免 self-check 失败
