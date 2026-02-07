# PR43 Recon

- Keywords: EnsureUuidRouteParams|shares|attempts
- 相关入口文件：
  - backend/routes/api.php
  - backend/bootstrap/app.php
  - backend/app/Http/Middleware/EnsureUuidRouteParams.php
- 相关路由：
  - /api/v0.2/attempts/{id}/report
  - /api/v0.2/shares/{shareId}/click
- 需要新增/修改点：
  - route param uuid 预校验，统一 404，避免异常穿透与侧信道
