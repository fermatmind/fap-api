# PR22 Recon — Global Boot + Region Policy + Payment Router + CDN Map（V0.4）

## 相关入口文件（现状）
- routes: `backend/routes/api.php`（仅 v0.2/v0.3，无 v0.4）
- middleware: `backend/app/Http/Middleware/ResolveOrgContext.php`、`FmTokenAuth.php`、`FmTokenOptional.php`
- services: `backend/app/Services/Assets/AssetUrlResolver.php`、`backend/app/Services/Content/QuestionsService.php`
- assets resolver: `AssetUrlResolver` 仅使用 version.json.assets_base_url → APP_URL fallback

## 相关路由（现状）
- v0.3: `/api/v0.3/scales/*`、`/api/v0.3/orders`、`/api/v0.3/attempts/*`
- v0.4: 无

## 相关 DB 表/迁移（本 PR 预计不新增表）
- `scales_registry`（default_pack_id/default_dir_version/default_region/default_locale）
- `skus`、`orders`、`order_items`（v0.3 commerce）
- `fm_tokens`（FmTokenAuth 解析 user_id/anon_id/org_id）

## 需要新增/修改点（本 PR）
- 新增 v0.4 /boot 返回 region/locale/currency/payment/cdn/compliance/policy_versions
- DetectRegion：只读 X-Region + Accept-Language，写入 RegionContext
- CDN map：config/cdn_map.php
- PaymentRouter：按 region provider_priority
- 强缓存：Cache-Control/Vary/ETag + 304
- 打通 PR16 AssetUrlResolver：region CDN 覆盖 assets_base_url

## 风险点与规避
- 端口占用：accept 脚本开头清理 1822/18000
- CI 工具依赖：workflow 禁止 rg/jq
- pack/seed/config 一致性：verify 脚本显式校验
- sqlite 迁移一致性：不引入更严格 NOT NULL 破坏匿名链路
- 跨 org/越权口径：保持 404
- artifacts 脱敏：sanitize_artifacts 必跑
