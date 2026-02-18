# FAP Backend (Laravel) · Runtime + Acceptance Runbook

Status: Active
Last Updated: 2026-02-18

## Backend 现状摘要

### API 版本与核心路由组
- 总路由（`/api/*`，exclude vendor）：`120`
- `v0.2`（82）：legacy MBTI、auth/lookup、admin、content packs、insights
- `v0.3`（31）：attempt start/submit/report、commerce、org/invite/wallet
- `v0.4`（5）：`/boot` + org assessments（create/invite/progress/summary）

代码真相入口：
- `backend/routes/api.php`
- `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --path=api --except-vendor`

### 鉴权模型（运行中）
- `Auth: Public`
- `Auth: Sanctum`
- `Auth: AdminAuth`
- `Auth: FmTokenAuth`
- `Auth: FmTokenOptional`
- `Auth: RequireOrgRole(owner|admin)`
- Feature gates: `fap_feature:*`

### E2E 验收入口（保持不变）
- `bash scripts/verify_mbti.sh`
- `bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/ci_verify_mbti.sh`

---

## 上线前置校验（必须）
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan migrate --force
php artisan fap:schema:verify
```

## verify_mbti：本机 / 服务器 / CI 可重复验收

### A. 本机模式（自动创建 attempt）
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
bash ./scripts/verify_mbti.sh
echo "EXIT=$?"
```

成功标准：
- 最后一行含 `[DONE] verify_mbti OK`
- `EXIT=0`

产物目录：
- `backend/artifacts/verify_mbti/report.json`
- `backend/artifacts/verify_mbti/share.json`
- `backend/artifacts/verify_mbti/attempt_id.txt`
- `backend/artifacts/verify_mbti/logs/overrides_accept_D.log`

### B. 复用已有 attempt（服务器/CI 常用）
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
ATTEMPT_ID="$(cat artifacts/verify_mbti/attempt_id.txt)" bash ./scripts/verify_mbti.sh
echo "EXIT=$?"
```

### C. 指定 API 地址
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
API="https://your-api.example.com" bash ./scripts/verify_mbti.sh
API="https://your-api.example.com" ATTEMPT_ID="<attempt_uuid>" bash ./scripts/verify_mbti.sh
```

### D. 关键环境变量（避免掉回 GLOBAL/en）
默认：
- `REGION=CN_MAINLAND`
- `LOCALE=zh-CN`
- `EXPECT_PACK_PREFIX=MBTI.cn-mainland.zh-CN.`

可显式覆盖：
```bash
REGION="CN_MAINLAND" LOCALE="zh-CN" EXPECT_PACK_PREFIX="MBTI.cn-mainland.zh-CN." bash ./scripts/verify_mbti.sh
```

建议在 CI/服务器设置：
- `FAP_DEFAULT_REGION=CN_MAINLAND`
- `FAP_DEFAULT_LOCALE=zh-CN`
- `FAP_DEFAULT_PACK_ID=MBTI.cn-mainland.zh-CN.v0.2.1-TEST`

### E. Overrides 回归验收（D-1/D-2/D-3）
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
ATT="$(cat artifacts/verify_mbti/attempt_id.txt)"
bash ./scripts/accept_overrides_D.sh "$ATT"
echo "EXIT=$?"
```

成功标准：
- 输出 `ALL DONE: D-1 / D-2 / D-3 passed`
- `EXIT=0`

## CI 主链路（必须保持绿）
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/ci_verify_mbti.sh
```

## 本地 pre-commit 安全门禁（阻断 `backend/.env`）
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
chmod +x backend/scripts/pre_commit_env_guard.sh
cat > .git/hooks/pre-commit <<'HOOK'
#!/usr/bin/env bash
set -euo pipefail
bash backend/scripts/pre_commit_env_guard.sh
HOOK
chmod +x .git/hooks/pre-commit
```

手工验证：
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/pre_commit_env_guard.sh
```
