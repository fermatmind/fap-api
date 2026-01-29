# PR15 — Scale Registry + Slug Lookup (v0.3)

## 目标与用途
- 新增 scales_registry：量表定义的单一真相源（code/driver/默认包/SEO/商业规则）。
- 新增 scale_slugs：slug 索引与唯一性门禁，支撑 SEO 入口稳定定位。
- 新增 v0.3 API：列表、详情、slug lookup。

## 数据结构

### scales_registry
- 主键：code (string, 64)
- 字段：
  - org_id (bigint, default 0)
  - primary_slug (string, 127)
  - slugs_json (json/text)
  - driver_type (string, 32)
  - default_pack_id/default_region/default_locale/default_dir_version
  - capabilities_json/view_policy_json/commercial_json/seo_schema_json
  - is_public (bool), is_active (bool)
  - timestamps
- 约束：unique(org_id, primary_slug)
- 索引：org_id, driver_type, is_public, is_active

### scale_slugs
- 字段：id, org_id, slug, scale_code, is_primary, timestamps
- 约束：unique(org_id, slug)
- 索引：scale_code, is_primary, org_id

## Slug 规范化规则
- 规范化：trim + strtolower
- 允许字符：a-z / 0-9 / -
- 校验正则：^[a-z0-9-]{0,127}$
- 非法 slug：直接返回 NotFound 口径

## 缓存策略
- TTL：300 秒
- key（CacheKeys 统一前缀）：
  - scale_registry:active:{orgId}
  - scale_registry:code:{orgId}:{code}
  - scale_registry:slug:{orgId}:{slug}
- Writer 写入后精确失效：active + code + slug

## 初始化与重建
- 初始化：
  - php artisan fap:scales:seed-default
- 重建 slug 索引：
  - php artisan fap:scales:sync-slugs

## 回滚策略
- 可回滚迁移：drop scales_registry / scale_slugs
- 如需保留数据：先导出，再回滚迁移

## 常见故障排查
- 命令找不到：检查 Console Kernel 是否注册 SeedScaleRegistry/SyncScaleSlugs
- 路由找不到：确认 backend/routes/api.php 已新增 v0.3 路由组
- 唯一冲突：scale_slugs 需要 org_id + slug 唯一，确保 slug 不重复
- lookup 404：检查 slug 规范化规则与 scale_slugs 是否已 sync
