# PR38 Recon

- Keywords: ContentLoaderService|filemtime|getReport

- 相关入口文件：
  - backend/app/Services/Content/ContentLoaderService.php
  - backend/app/Services/ContentPackResolver.php
  - 历史入口：backend/app/Http/Controllers/MbtiController.php（已删除）

- 相关测试：
  - backend/tests/Unit/Services/ContentLoaderMtimeCacheTest.php（新增）
  - backend/tests/Feature/V0_2/AttemptReportOwnershipTest.php（新增 share_id 覆盖）

- 需要新增/修改点：
  - ContentLoaderService：cache key 加入 filemtime（已存在），并确保 Cache 异常自动降级直读
  - ContentPackResolver：loader 回调仅返回 abs path，读盘由 ContentLoaderService 统一完成
  - 历史整改项：MbtiController::canAccessAttemptReport；current share/access 校验已迁到 current v0.3 read path
  - 404 口径保持一致

- 风险点与规避：
  - mtime 使用 filemtime，避免读取文件内容带来的额外 I/O
  - cache 不可用时不抛 500，直接降级直读
