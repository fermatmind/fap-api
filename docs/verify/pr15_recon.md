# PR15 勘察结论（Scale Registry + Slug Lookup v0.3）

## 相关入口文件
- backend/routes/api.php
  - 当前仅有 v0.2 路由组；/api/v0.3 尚不存在。
- backend/app/Support/CacheKeys.php
  - 缓存 key 统一由 CacheKeys 生成，前缀为 fap:v={app_version}:{cache_prefix}。
- backend/app/Console/Kernel.php
  - 采用显式 $commands 列表 + Commands 目录自动加载；新增命令建议显式注册避免缓存找不到。
- backend/app/Http/Controllers/LookupController.php
  - 现有 lookup 接口使用统一 envelope：ok=true/false + error/message。
- backend/app/Http/Controllers/MbtiController.php
  - 现有 v0.2 scale meta / questions 参考响应结构（ok + payload）。

## 相关 DB 表/迁移
- 当前 migrations 中未发现 scales_registry / scale_slugs 相关表。
- 现有表集中于 attempts/results/content packs/norms/admin/ai 等，与本 PR 新表无冲突。

## 相关路由
- 已存在：/api/v0.2/scales/MBTI、/api/v0.2/lookup/* 等。
- 当前不存在：/api/v0.3/*（route:list 未命中 v0.3）。

## 需要新增/修改点
- 新增 v0.3 路由组与 controllers：/scales、/scales/{code}、/scales/lookup?slug=。
- 新增 migrations：scales_registry + scale_slugs（幂等创建/回滚）。
- 新增 models/services/commands/seeders/tests。
- 新增 CacheKeys 中的 scale registry 缓存 key 方法。

## 潜在风险与规避
- 路由分组：/api 已由 RouteServiceProvider 前缀；v0.3 路由应在同文件新增独立 prefix("v0.3")。
- Envelope 规范：沿用 ok + error/message 结构，404 返回 ok=false + error=not_found。
- 命令注册：Console Kernel 采用显式注册，建议将新 Artisan 命令加入 $commands。
- 命名冲突：scale_slugs.slug 唯一约束与 org_id 组合，避免与现有 slug 命名混淆。
- 迁移幂等：Schema::hasTable/hasColumn/hasIndex 防护，避免重复迁移失败。
