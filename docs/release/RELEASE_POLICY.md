# RELEASE_POLICY

## 最小可审计交付目录集（必须包含）

1. `backend/routes/api.php`
2. `backend/app`
3. `backend/config`
4. `backend/database/migrations`
5. `backend/composer.json`
6. `backend/composer.lock`
7. `scripts`
8. `docs`
9. `README_DEPLOY.md`

## 黑名单（必须不包含）与原因

1. `.env/.env.*`（排除 `.env.example`）：敏感信息泄露风险。
2. `.git`：源码历史泄露与体积污染。
3. `vendor`/`node_modules`：体积污染与不可控供应链复制。
4. `backend/storage/logs`、`backend/artifacts`、`backend/storage/app/private/reports`、`backend/storage/app/archives`：运行时污染物，不可审计交付。
5. `*.sqlite*`：环境数据泄露风险。
6. `__MACOSX`、`.DS_Store`：非业务污染物。

## 验收动作（必做）

1. `bash scripts/audit_smoke.sh dist/fap-api-release.zip`
2. `bash scripts/release_hygiene_gate.sh ./_audit/fap-api-0212-5/`
3. `bash scripts/supply_chain_gate.sh ./_audit/fap-api-0212-5`
