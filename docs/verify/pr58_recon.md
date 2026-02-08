# PR58 Recon

- Scope: `backend/database/migrations/*`
- Keywords: `migrations|dropIfExists|catch`

## Findings to address
1) 伪 create 迁移风险
- `create_*.php` 中 `up()` 含 `Schema::hasTable(...)` guard（跳过/分支创建）
- `down()` 仍含 `Schema::dropIfExists(...)`
- 风险：rollback 误删历史既有表

2) 吞异常风险
- `catch (\Throwable ...) {}` 空 catch 会掩盖 schema drift
- 目标：fail-fast，禁止 silent catch

## Fix rules
- 对命中伪 create 规则的迁移：在 `down()` 注释掉 `dropIfExists`，并加注释：
  - `// Prevent accidental data loss. This table might have existed before.`
- migrations 中禁止空 catch，允许 catch 但必须 rethrow 或显式处理

## Verification strategy
- 新增 Unit tests：
  - `MigrationRollbackSafetyTest`
  - `MigrationNoSilentCatchTest`
- sqlite fresh migrate 可跑通
- `bash backend/scripts/pr58_accept.sh`
- `bash backend/scripts/ci_verify_mbti.sh`
