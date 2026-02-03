# PR27 Recon

- 目标：
  - 修复 PR 的 selfcheck CI 红：停止对 MBTI-CN-v0.2.1-TEST 执行 strict-assets gate
  - 建立长期稳定口径：selfcheck 只跑 active pack 清单（包含 MBTI-CN-v0.2.2），并固化脚本入口
- 相关入口文件：
  - CI/workflow：.github/workflows（搜索 selfcheck / fap:self-check）
  - 自检命令：php backend/artisan fap:self-check --strict-assets --pkg=...
  - 内容包目录：content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2
- 需要新增/修改点：
  1) 新增 backend/scripts/ci_selfcheck_packs.sh：维护 active packs 清单并逐个自检
  2) 修改 selfcheck workflow：改为调用 ci_selfcheck_packs.sh（不再写死 v0.2.1-TEST）
  3) 新增 pr27_accept/pr27_verify：本机验收闭环（同 CI 口径）
- 风险点与规避：
  - 禁止 jq/rg：脚本与 workflow 统一 grep -E + php -r
  - 端口统一 1827：脚本强制清端口并回收 PID
  - pack/seed/config 一致性：verify.sh 打印并校验 pack_id、目录存在、关键 json 可解析
  - artifacts 脱敏：summary.txt 不包含绝对路径、token、Authorization
