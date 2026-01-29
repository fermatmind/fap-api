# Assessment Engine v0.3

Date: 2026-01-29

## 目标与边界
- 目标：引入统一 AssessmentEngine + Drivers，实现 v0.3 attempts start/submit/result/report 主链路；新增量表仅需 content pack + scales_registry 即可跑通评分与报告。
- 非目标：不改 v0.2 主链路；不接入付费锁（PR20）；不引入 answer_sets 存储（PR21）。

## Attempts 生命周期（v0.3）
1) `POST /api/v0.3/attempts/start`
   - 输入：`scale_code`（可选 `region/locale/anon_id/client_*`）
   - 行为：创建 attempts，写入 `started_at`、`pack_id/dir_version`、`question_count`；`scale_version` 固定 `v0.3`。
   - 输出：`attempt_id + scale_code + pack_id + dir_version`。

2) `POST /api/v0.3/attempts/submit`
   - 输入：`attempt_id + answers + duration_ms`
   - 行为：计算 `answers_digest` 幂等锁；调用 AssessmentEngine 评分；写入 results（UNIQUE(org_id,attempt_id)）。
   - 输出：`result`（固定字段集）+ `attempt_id`。

3) `GET /api/v0.3/attempts/{id}/result`
   - 读取 `results.result_json` 返回，附带 `pack_id/dir_version/scoring_spec_version`。

4) `GET /api/v0.3/attempts/{id}/report`
   - MBTI：复用 ReportComposer v1.2（JSON 结构不漂移）。
   - 非 MBTI：返回 GenericReportBuilder 的最小报告结构。
   - 返回固定：`{ ok, locked:false, report, meta:{scale_code, pack_id, dir_version, scoring_spec_version, report_engine_version} }`。

## ctx 固定字段
AssessmentEngine 的 `ctx` 统一注入：
- `duration_ms`（提交耗时）
- `started_at`, `submitted_at`
- `region`, `locale`
- `org_id`
- `pack_id`, `dir_version`
- `scoring_spec_version`

## ScoreResult 固定字段
- `raw_score`：原始得分
- `final_score`：最终得分（含 time_bonus）
- `breakdown_json`：分解明细（**time_bonus 固定写在此**）
- `type_code`：可空（MBTI 输出）
- `axis_scores_json`：可空（MBTI 轴向百分比/状态）
- `normed_json`：可空（IQ 归一化/正确率等）

## scoring_spec.json 最小契约（示例）
### 1) simple_score
```
{
  "version": "2026.01",
  "scale_code": "SIMPLE_SCORE_DEMO",
  "driver_type": "simple_score",
  "answer_scores": {
    "SS-001": {"1": 1, "2": 2, "3": 3, "4": 4, "5": 5}
  },
  "severity_levels": [
    {"min": 0, "max": 9, "label": "low"},
    {"min": 10, "max": 17, "label": "medium"},
    {"min": 18, "max": 25, "label": "high"}
  ]
}
```

### 2) generic_likert
```
{
  "version": "2026.01",
  "scale_code": "LIKERT_DEMO",
  "driver_type": "generic_likert",
  "options_score_map": {"1": 1, "2": 2, "3": 3, "4": 4, "5": 5},
  "dimensions": {
    "stress": {
      "items": {"Q1": 1, "Q2": 1, "Q3": -1}
    }
  }
}
```

### 3) iq_test
```
{
  "version": "2026.01",
  "scale_code": "IQ_RAVEN",
  "driver_type": "iq_test",
  "answer_key": {"RAVEN_DEMO_1": "B"},
  "score": {"correct": 1, "wrong": 0},
  "time_bonus": {
    "rules": [
      {"max_ms": 30000, "bonus": 3},
      {"max_ms": 60000, "bonus": 2},
      {"max_ms": 120000, "bonus": 1},
      {"max_ms": 99999999, "bonus": 0}
    ]
  }
}
```

## 幂等策略（answers_digest）
- 提交时将 `answers` 按 `question_id` 排序，仅保留 `question_id + code`，再做 sha256。
- 已提交且 digest 相同：直接返回已有结果。
- 已提交且 digest 不同：返回 409（`ATTEMPT_ALREADY_SUBMITTED`）。

## MBTI 报告契约不漂移策略
- v0.3 MBTI 报告仍使用 `ReportComposer` v1.2。
- results 必须写入 `scores_pct + axis_states + scores_json` 以保证报告可用。
- report 响应固定 `locked=false`，付费锁在 PR20 处理。
