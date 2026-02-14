# SUPPLY_CHAIN

## CI 固化命令（阻断模式）

`/Users/rainie/Desktop/GitHub/fap-api/.github/workflows/supply-chain-audit.yml` 固化执行：

1. `cd backend && composer install --no-interaction --prefer-dist --no-progress`
2. `cd backend && composer audit --no-interaction`
3. `osv-scanner scan -L backend/composer.lock`
4. `bash scripts/supply_chain_gate.sh`

任一步骤失败即 job 失败，PR 状态红灯。

## 版本更新策略

1. 每周至少一次执行依赖审计（建议周一）。
2. 所有依赖升级必须提交更新后的 `backend/composer.lock`。
3. 升级后必须重新通过 `supply-chain-audit` 与 `release-hygiene-gate`。

## 风险响应流程（audit 红灯）

1. 记录失败证据（Composer/OSV 输出）。
2. 定位受影响依赖并评估影响范围。
3. 提交修复（升级/替代/临时抑制并附理由）。
4. 重新触发 CI，确认两条 gate 全绿后再合并/发布。
