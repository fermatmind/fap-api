# PR34 Recon

- Keywords: ContentPackResolver|file_get_contents|getReport

- 相关入口文件（命中 KEY_RE 后补齐）：
  - backend/app/Services/ContentPackResolver.php（legacy：fallback + loadJson/loadText 热路径）
  - backend/app/Services/Content/ContentStore.php（hot_redis 资产缓存策略）
  - 历史入口：backend/app/Http/Controllers/MbtiController.php（后续 PR 已删除；current `/api/v0.3/attempts/{id}/report` 走 `AttemptReadController`）
  - backend/app/Http/Middleware/FmTokenOptional.php（fm_user_id / fm_anon_id 注入）
  - backend/routes/api.php（v0.2 路由与 middleware 口径）
  - backend/config/cache.php（hot_redis store）
  - backend/config/content_packs.php（TTL/store 参数）

- 相关路由：
  - GET  /api/v0.3/scales/MBTI/questions
  - POST /api/v0.3/attempts
  - GET  /api/v0.3/attempts/{attempt_id}/report
  - POST /api/v0.3/shares
  - GET  /api/v0.3/shares/{share_id}/click

- 相关 DB 表/迁移：
  - attempts
  - shares
  - fm_tokens
  - (按需) cache_keys / redis keys（仅缓存层，不入库）

- 需要新增/修改点：
  - ContentLoaderService：Cache::remember 统一缓存读盘（pack_id + dir_version + rel_path → key）
  - ContentPackResolver：fallback 扫描与 file_get_contents 只在 cache miss 执行
  - 历史整改项：MbtiController::getReport 的 IDOR 防护；current ownership/read path 已迁到 v0.3 current controllers

- 风险点与规避（端口/CI 工具依赖/pack-seed-config 一致性/sqlite 迁移一致性/404 口径/脱敏）：
  - 缓存 key 禁止写入绝对路径；key 使用 pack_id + dir_version + rel_path 的 hash
  - testing 环境 cache store 走 array；生产环境走 hot_redis/redis
  - 越权与匿名缺省访问统一 404，避免 attempt_id 枚举侧信道
  - artifacts 必须脱敏（绝对路径、token、Authorization）
