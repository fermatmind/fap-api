# PR24 Recon

- 相关入口文件：
  - backend/routes/web.php
  - backend/app/Http/Kernel.php（确认 web middleware 是否会写 cookie）
  - backend/app/Models/ScalesRegistry*（如存在）/ DB 表 scales_registry
- 相关路由：
  - 新增 GET /sitemap.xml
- 相关 DB 表/迁移：
  - scales_registry（primary_slug, slugs_json, updated_at, is_active）
- 需要新增/修改点：
  - SitemapController + SitemapGenerator + SitemapCache
  - 强缓存头 + ETag + 304
  - 仅从 scales_registry(is_active=1) 生成 slug 集合（primary_slug + slugs_json[] 去重）
  - fap-web robots.ts/sitemap.ts 统一引用源
- 风险点与规避：
  - web middleware 写 cookie（Set-Cookie 会打断 CDN 缓存）：路由必须 withoutMiddleware
  - sqlite fresh migrate 可跑：不新增迁移，仅读取 scales_registry
  - CI 工具依赖：workflow 禁止 rg/jq
  - artifacts 脱敏：sanitize_artifacts 必跑
