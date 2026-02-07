# PR45 Recon

- Keywords: ApiExceptionRenderer|withExceptions|bootstrap/app.php
- 目标：
  - API 路径 /api/* 出现未捕获异常时统一返回 JSON，不返回 HTML debug 页面
- 相关入口文件：
  - backend/bootstrap/app.php (withExceptions)
