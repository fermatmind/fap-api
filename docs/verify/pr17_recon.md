# PR17 Recon — AssessmentEngine + Drivers (v0.3 attempts start/submit)

Date: 2026-01-29

## 相关入口文件（attempts/results/report/scoring）
- `backend/routes/api.php`
  - v0.2 已有 `/api/v0.2/attempts`, `/attempts/start`, `/attempts/{id}/result`, `/attempts/{id}/report`
  - v0.3 仅有 `/api/v0.3/scales`、`/scales/lookup`、`/scales/{scale_code}`、`/scales/{scale_code}/questions`
- `backend/app/Http/Controllers/MbtiController.php`
  - v0.2 attempts start/submit/result/report 主链路
  - MBTI 计分逻辑 + result_json 写入 + report v1.2 输出
- `backend/app/Services/Score/MbtiAttemptScorer.php`
  - MBTI 计分核心算法（scores_pct / type_code / axis_states）
- `backend/app/Services/Report/ReportComposer.php`
  - MBTI 报告引擎 v1.2（基于 attempts/results + content pack）
- `backend/app/Http/Controllers/API/V0_3/ScalesController.php`
  - v0.3 scales/ questions 输出（基于 `ContentPacksIndex`）
- `backend/app/Services/Content/ContentPacksIndex.php`
  - content pack 定位（pack_id + dir_version）

## DB 表/迁移现状
- `attempts`
  - 创建：`2025_12_14_084436_create_attempts_table.php`
  - 已有字段：anon_id/user_id/scale_code/scale_version/started_at/submitted_at/answers_json/answers_hash/answers_storage_path/region/locale/ticket_code/result_json/type_code
  - 已有版本化字段：`pack_id/dir_version/scoring_spec_version/norm_version/calculation_snapshot_json`（`2026_01_28_110000_add_psychometrics_snapshot_to_attempts.php`）
  - 缺口：org_id/content_package_version/duration_ms/answers_digest（需新增）
  - 索引现状：`idx_attempts_anon_scale`、`attempts_scale_region_locale_idx`、`attempts_pack_norm_idx` 等
- `results`
  - 创建：`2025_12_13_231207_create_results_table.php`
  - 已有字段：attempt_id/scale_code/scale_version/type_code/scores_json/scores_pct/axis_states/content_package_version
  - 缺口：org_id/result_json/pack_id/dir_version/scoring_spec_version/report_engine_version + UNIQUE(org_id, attempt_id)

## 路由现状（route:list）
- v0.2 attempts: 已存在 start/submit/result/report/quality/stats
- v0.3: 仅 scales index/show/lookup/questions；无 attempts 生命周期

## 需要新增/修改点（最小集合）
- v0.3 路由：attempts start/submit/result/report
- 迁移：attempts/results 字段补齐 + 索引/唯一约束（幂等）
- 新增 AssessmentEngine + Drivers + v0.3 Attempts Controller
- 新增 scoring_spec（IQ_RAVEN + SIMPLE_SCORE demo 包）
- 新增 SimpleScore demo seeder + v0.3 tests + verify script + CI workflow
- 文档：assessment-engine v0.3 + verify + recon

## 潜在风险与规避
- 幂等提交：answers_digest 做提交锁；同 digest 复用结果，不同 digest 返回 409
- 并发一致性：attempts/results upsert 必须事务内保证一致
- v0.2 回归：v0.2 路由/逻辑不动；v0.3 新控制器隔离
- 报告契约：MBTI 继续走 ReportComposer v1.2；非 MBTI 用固定最小报告结构
- 索引冲突：所有新增索引先判断存在性（兼容 sqlite/mysql/pgsql）
