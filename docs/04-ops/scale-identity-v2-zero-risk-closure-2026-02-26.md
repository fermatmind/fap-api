# Scale Identity v2 零风险切流收口报告（2026-02-26）

## 1) 结论

- 迁移阶段目标已达成：在不改仓库代码的前提下完成配置切流、门禁验证、灰度观察与回滚演练。
- 当前生产运行稳定：6 个量表 `questions` API 全部返回 `200`，strict gate 持续 `pass=true`。
- 对外契约保持稳定：`scale_code` 继续 legacy 主值，兼容字段并存。

## 2) 当前生产目标态配置

- `FAP_SCALE_IDENTITY_WRITE_MODE=dual`
- `FAP_SCALE_IDENTITY_READ_MODE=dual_prefer_new`
- `FAP_CONTENT_PATH_MODE=dual_prefer_new`
- `FAP_CONTENT_PUBLISH_MODE=dual`
- `FAP_API_RESPONSE_SCALE_CODE_MODE=legacy`
- `FAP_ACCEPT_LEGACY_SCALE_CODE=true`
- `FAP_ALLOW_DEMO_SCALES=true`

## 3) 线上契约探针（2026-02-26）

| scale | http | scale_code | pack_id | dir_version | q_count |
|---|---:|---|---|---|---:|
| MBTI | 200 | MBTI | MBTI.cn-mainland.zh-CN.v0.3 | MBTI-CN-v0.3 | 144 |
| BIG5_OCEAN | 200 | BIG5_OCEAN | BIG5_OCEAN | v1 | 120 |
| CLINICAL_COMBO_68 | 200 | CLINICAL_COMBO_68 | CLINICAL_COMBO_68 | v1 | 68 |
| SDS_20 | 200 | SDS_20 | SDS_20 | v1 | 20 |
| IQ_RAVEN | 200 | IQ_RAVEN | default | IQ-RAVEN-CN-v0.3.0-DEMO | 30 |
| EQ_60 | 200 | EQ_60 | EQ_60 | v1 | 60 |

strict gate 指标（同窗口）：

- `identity_resolve_mismatch_rate=0`
- `dual_write_mismatch_rate=0`
- `content_path_fallback_rate=0`
- `legacy_code_hit_rate=1`（本阶段允许）
- `demo_scale_hit_rate=0`

## 4) 目录与路径解释（为何不是“两棵目录各 6 套”）

当前设计是“按量表分根目录”，不是“双根都 6 套”：

- `content_packages/default/CN_MAINLAND/zh-CN`：MBTI、IQ_RAVEN
- `backend/content_packs`：BIG5_OCEAN、CLINICAL_COMBO_68、SDS_20、EQ_60

因此验收标准应为“各量表在其契约根目录可解析 + alias 回退健康”，而不是“两个根目录都出现相同 6 套目录”。

## 5) 风险控制结果

- 已完成秒级回滚演练，并验证可恢复到目标态。
- `ops:content-path-mirror` 幂等通过（重复执行 `copied=0 updated=0 mismatch=0`）。
- `bash backend/scripts/ci_verify_mbti.sh` 多轮执行均通过。

## 6) 证据索引

运行证据已落地到本机目录（仓库忽略项）：

- `backend/artifacts/scale_identity_rollout/rollout_artifacts_index_20260226_004638.md`
- `backend/artifacts/scale_identity_rollout/prod_scale_contract_probe_20260226_004453.md`
- `backend/artifacts/scale_identity_rollout/scale_runtime_storage_audit_20260226_004148.md`
- `backend/artifacts/scale_identity_rollout/scale_dir_completeness_audit_20260226_003624.md`

## 7) Phase-B 护栏附录（模式语义审计）

为避免“配置值合法但组合语义冲突”的发布风险，Phase-B 增加发布前强校验：

- 命令：`php artisan ops:scale-identity-mode-audit --json=1 --strict=1`
- 发布门禁脚本：`backend/scripts/prA_gate_scale_identity.sh` 已接入该校验。

当前审计规则（strict 失败条件）：

1. 枚举值非法（任何 mode 超出允许集合）：
   - `write_mode`: `legacy|dual|v2`
   - `read_mode`: `legacy|dual_prefer_old|dual_prefer_new|v2`
   - `content_path_mode`: `legacy|dual_prefer_old|dual_prefer_new|v2`
   - `content_publish_mode`: `legacy|dual|v2`
   - `api_response_scale_code_mode`: `legacy|dual|v2`
2. 语义冲突：
   - `read_mode=v2` 且 `write_mode=legacy`
   - `read_mode=v2` 且 `accept_legacy_scale_code=true`

说明：

- 警告项（warnings）用于提示高耦合组合，不直接阻断发布。
- strict 只在 violations 非空时阻断，保证“可回滚 + 可观测 + 可阻断”。
