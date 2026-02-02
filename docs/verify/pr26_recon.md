# PR26 Recon

- 目标：
  - 修复 git archive 发布产物缺失 API 控制器/内容包的问题
  - 修复 Deployer shared_dirs 初始化导致 content_packages 变空软链的问题
  - 将上述两类问题固化为 CI + 部署门禁

- 关键入口：
  - .gitattributes / 任意子目录 .gitattributes（export-ignore）
  - deploy.php（deploy:update_code=git archive；deploy:shared 软链）
  - .github/workflows/selfcheck.yml（CI 门禁入口）
  - backend/scripts/guard_release_integrity.sh（门禁脚本）

- 风险与规避：
  - 只改部署/门禁相关文件，避免业务逻辑变更
  - 脚本不依赖 jq/rg，仅用 grep/sed/awk/php -r
  - artifacts 统一脱敏
