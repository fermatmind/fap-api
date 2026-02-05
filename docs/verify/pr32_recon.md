# PR32 Recon

- Keywords: AssessmentEngine|MbtiAttemptScorer|scoring_spec
- 相关入口文件：
  - backend/app/Services/Assessment/AssessmentEngine.php
  - backend/app/Services/Assessment/Drivers/MbtiDriver.php
  - backend/app/Services/Score/MbtiAttemptScorer.php
- 相关路由：
  - /api/v0.3/attempts/start
  - /api/v0.3/attempts/submit
- 相关 DB 表/迁移：
  - scales_registry (新增 assessment_driver)
- 需要新增/修改点：
  - AssessmentEngine 改为 registry 驱动选择
  - 新增 GenericScoringDriver
  - scoring_spec 升级 + BIG5 pack + seed
- 风险点与规避（端口/CI 工具依赖/pack-seed-config 一致性/sqlite 迁移一致性/404 口径/脱敏）：
  - artisan 不可用时无法 route:list，需在有 vendor 后补跑
  - 脚本禁用 rg/jq，统一 grep -E + php -r
  - pack/seed/config 一致性校验写入 verify.sh
