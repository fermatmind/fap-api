# Scale Identity v2 零风险切流收口报告（Phase-C，2026-02-26）

## 1) 结论

- Phase-C（硬切换与遗留下线）在仓库层已完成：legacy 输入关闭、`scale_code` 主字段切 v2、demo scales 下线、CI 合约反转。
- `run_full_scale_regression=1` 时，主链已真实执行 hard-cutover contract，且通过。
- 发布节奏保持 `5% -> 25% -> 100%`，每级失败可秒级回滚。

## 2) Phase-C 目标态配置（发布配置）

- `FAP_SCALE_IDENTITY_WRITE_MODE=dual`
- `FAP_SCALE_IDENTITY_READ_MODE=v2`
- `FAP_API_RESPONSE_SCALE_CODE_MODE=v2`
- `FAP_ACCEPT_LEGACY_SCALE_CODE=false`
- `FAP_ALLOW_DEMO_SCALES=false`
- `FAP_CONTENT_PATH_MODE=dual_prefer_new`
- `FAP_CONTENT_PUBLISH_MODE=dual`

## 3) 对外契约（硬切换）

1. legacy `scale_code` 输入：统一 `410`
2. legacy 错误码：`SCALE_CODE_LEGACY_NOT_ACCEPTED`
3. legacy 错误详情字段：
   - `details.requested_scale_code`
   - `details.scale_code_legacy`
   - `details.replacement_scale_code_v2`
4. v2 `scale_code` 输入：统一 `200`
5. `scale_code` 主字段：统一返回 v2；并保留 `scale_code_legacy`、`scale_code_v2`
6. demo scales：`DEMO_ANSWERS`、`SIMPLE_SCORE_DEMO` 下线（`410`）

## 4) CI / Gate 已落地变更

1. 新增 hard-cutover 合约脚本：
   - `backend/scripts/ci/verify_scale_identity_hard_cutover.sh`
2. 合约脚本反转：
   - `backend/scripts/ci/verify_scale_identity_contract.sh`
   - 新增 `LEGACY_EXPECTED_STATUS=410`
   - 新增 `V2_EXPECTED_STATUS=200`
   - strict mode audit + strict gate 串联验证
3. 主链接入 hard-cutover：
   - `backend/scripts/ci_verify_mbti.sh`
   - `backend/scripts/ci_verify_scales.sh`
   - `.github/workflows/ci_verify_mbti.yml`
4. 发布门禁阈值收紧为 0：
   - `backend/scripts/prA_gate_scale_identity.sh`
   - `identity_resolve_mismatch_rate<=0`
   - `dual_write_mismatch_rate<=0`
   - `content_path_fallback_rate<=0`
   - `legacy_code_hit_rate<=0`
   - `demo_scale_hit_rate<=0`
5. 新增 hard-cutover CI 测试：
   - `backend/tests/Feature/Ops/ScaleIdentityHardCutoverCiTest.php`

## 5) 实跑结果（2026-02-26）

已执行：

- `php artisan test --filter ScaleIdentityRuntimePolicyTest`
- `php artisan test --filter DemoScaleDeprecationGateTest`
- `php artisan test --filter ScaleCodeLegacyRejectTest`
- `php artisan test --filter AssessmentScaleCodeLegacyRejectTest`
- `php artisan test --filter ScaleCodeV2PrimaryResponseTest`
- `php artisan test --filter AssessmentScaleCodeV2PrimaryResponseTest`
- `php artisan test --filter ScaleIdentityModeAuditCommandTest`
- `php artisan test --filter ScaleIdentityGateCommandTest`
- `php artisan test --filter ScaleIdentityHardCutoverCiTest`
  - 结果：全部通过
- `RUN_FULL_SCALE_REGRESSION=1 bash backend/scripts/ci_verify_mbti.sh`
  - 结果：通过（exit 0，日志包含 hard-cutover contract 实际执行）
- `API=http://127.0.0.1:<port> bash backend/scripts/ci/verify_scale_identity_hard_cutover.sh`
  - 结果：通过（six-scale probes + strict mode audit + strict gate）

六量表 hard-cutover probe（contract 内实跑）：

- legacy code：全部 `410`
- v2 code：全部 `200`
- demo scales（`DEMO_ANSWERS`、`SIMPLE_SCORE_DEMO`）：全部 `410`
- strict mode audit：`pass=true`
- strict gate：`pass=true`

## 6) 发布门禁（固定）

每一档（5% / 25% / 100%）必须同时满足：

1. `ops:scale-identity-mode-audit --json=1 --strict=1` 通过
2. `ops:scale-identity-gate --json=1 --strict=1` 通过
3. six-scale hard-cutover probes 通过
4. `bash backend/scripts/ci_verify_mbti.sh` 通过

## 7) 秒级回滚配置（固定）

- `FAP_SCALE_IDENTITY_READ_MODE=dual_prefer_new`
- `FAP_API_RESPONSE_SCALE_CODE_MODE=legacy`
- `FAP_ACCEPT_LEGACY_SCALE_CODE=true`
- `FAP_ALLOW_DEMO_SCALES=true`
- `FAP_SCALE_IDENTITY_WRITE_MODE=dual`

回滚后立即执行：

- `php artisan ops:scale-identity-mode-audit --json=1 --strict=1`
- `php artisan ops:scale-identity-gate --json=1 --strict=1`
- `bash backend/scripts/ci_verify_mbti.sh`

## 8) 说明

- 这次硬切换不要求 `content_pack_id` 同步改为 v2 前缀；当前约束是 API 输入/主字段与门禁契约完成切换。
- 目录分根策略不变：`content_packages/default/...` 与 `backend/content_packs` 继续按量表职责分布。
- `ScaleCodeInputGuard` 已调整判定优先级：demo 下线（`SCALE_DEPRECATED`）优先于 legacy 拒绝，避免 demo 请求被错误归类为 `SCALE_CODE_LEGACY_NOT_ACCEPTED`。
