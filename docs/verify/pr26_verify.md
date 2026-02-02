# PR26 Verify

目标：
- 保证 git archive 发布产物包含 backend/app/Http/Controllers/API 与 content_packages
- 保证 Deployer shared content_packages 首次部署不再出现空软链
- 将校验固化为 CI step 与 deploy preflight

本机验收：
1) bash backend/scripts/pr26_accept.sh
2) bash backend/scripts/ci_verify_mbti.sh

关键门禁：
- bash backend/scripts/guard_release_integrity.sh
  - Controllers/API 存在且 php 文件数 > 0
  - 主内容包 manifest/questions/scoring_spec/version JSON 合法
  - php artisan route:list 可运行
  - git archive 内包含 Controllers/API 与 pack manifest
