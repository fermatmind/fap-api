# 动态常模 v1：Norms Table 规范（全体样本 percentile）

## 目标
v1 仅提供**全体样本**的「超过 X% 的人」能力，默认不开启，不影响主链路。

- 展示口径：超过 `over_percent`% 的人
- 必须可解释、可回滚、可增量更新（v1 用全量窗口重算）
- v1 不做分群（性别/年龄/地区）

## Feature Flags
- `NORMS_ENABLED=0/1`（默认 0）
- `NORMS_VERSION_PIN=<uuid>`（可选：固定到某个 version_id，用于紧急回滚/线上 pin）

## 数据口径（v1 写死）
### 样本来源
- 首选：`results` 表（attempt 完成并产出结果）
- 必须能提取到 5 个维度分数（EI/SN/TF/JP/AT），v1 以 **scores_pct(0~100)** 为主

### 纳入条件（MVP）
- `results.is_valid = 1`（若该列存在；否则不作为过滤条件）
- 时间窗口内：优先 `results.computed_at`，否则 `results.created_at`
- pack 过滤：若 `results.content_package_version` 存在，则与 pack_id 的 version 对齐（见下）

> v1 暂不强制 answers_count、答题时长（字段缺失则不做）

### 指标维度（固定集合）
- `metric_key` ∈ `EI | SN | TF | JP | AT`

### score_int 离散化规则（写死）
- 输入 score（来自 report 的 `scores_pct[metric_key]`）：
  - `score_int = round(score)`（四舍五入，PHP round）
- v1 仅支持整数 score_int 查询

### pack_id 与 results 过滤
- norms 以 `pack_id` 作为版本归属（例：`MBTI.cn-mainland.zh-CN.v0.2.2`）
- 当 `results.content_package_version` 存在时：
  - 从 `pack_id` 解析 version（示例：`v0.2.1-TEST`）
  - 过滤 `results.content_package_version == version`

## percentile 算法（写死）
- 采用 **<= 排名规则**：
  - `P(score) = count(scores <= score) / N`
- 返回展示用：
  - `over_percent = floor(P * 100)`（向下取整）

## 数据表设计
### 1) norms_versions
用途：每次生成常模产生一个版本；回滚/Pin 的单位。

字段：
- `id` (uuid, pk)
- `pack_id` (string)
- `window_start_at` (timestamp, nullable)
- `window_end_at` (timestamp, nullable)
- `sample_n` (int)
- `rank_rule` (string, 固定 `leq`)
- `status` (string: `active | archived | failed`)
- `computed_at` (timestamp, nullable)
- `created_at` (timestamp, nullable)

索引：
- `(pack_id, status)`：`idx_norms_versions_pack_status`
- `(created_at)`：`idx_norms_versions_created_at`

约束/约定：
- 同一 `pack_id` 同时只应有 1 条 `status=active`
- 回滚：将历史版本切回 `active`，并将当前 active 置为 `archived`

### 2) norms_table
用途：查询 percentile 的映射表（O(1) 命中）。

字段：
- `id` (bigIncrements, pk)
- `norms_version_id` (uuid)
- `metric_key` (string(8))
- `score_int` (int)
- `leq_count` (int)
- `percentile` (decimal(8,6), 0~1)
- `created_at` (timestamp, nullable)

索引：
- `(norms_version_id, metric_key, score_int)`：`idx_norms_table_version_metric_score`

说明：
- 存 `leq_count` 便于校验与 debug
- `N` 从 `norms_versions.sample_n` 获取

## 查询契约
### API：percentile 查询
`GET /api/v0.3/norms/percentile?pack_id=...&metric_key=EI&score=42`

返回字段（固定）：
- `ok`
- `pack_id`
- `metric_key`
- `score_int`
- `percentile`（0~1）
- `over_percent`（0~100）
- `sample_n`
- `window_start_at`
- `window_end_at`
- `version_id`
- `rank_rule`（固定 leq）

错误约定：
- `NORMS_ENABLED=0`：`{ok:false,error:"NOT_ENABLED"}`
- 无 active/pin version：`{ok:false,error:"NOT_FOUND"}`
- metric_key 非法：HTTP 422

## report 注入契约（后端）
当 `NORMS_ENABLED=1` 且能命中所有维度 percentile 时，report 追加：

`report.norms`：
- `pack_id`
- `version_id`
- `N`
- `window_start_at`
- `window_end_at`
- `rank_rule`
- `metrics`（EI/SN/TF/JP/AT）：
  - `score_int`
  - `percentile`
  - `over_percent`

降级：
- 任一条件不满足（未启用/无版本/无表/任一维度缺行）→ **不注入** `report.norms`，不影响报告主体

## 回滚策略（写死）
- 线上紧急回滚优先使用：
  - `NORMS_VERSION_PIN=<历史version_id>`
- 或数据层回滚：
  - 将历史版本置为 `active`
  - 将当前 active 置为 `archived`

## v1 样例（用于前端展示）
### 结果页文案
- `超过 {{over_percent}}% 的人（N={{N}}，统计区间：{{window_start_at}}~{{window_end_at}}）`

### API JSON 示例
```json
{
  "ok": true,
  "pack_id": "MBTI.cn-mainland.zh-CN.v0.2.2",
  "metric_key": "EI",
  "score_int": 50,
  "percentile": 0.5,
  "over_percent": 50,
  "sample_n": 200,
  "window_start_at": "2025-01-20 06:14:01",
  "window_end_at": "2026-01-20 06:14:01",
  "version_id": "c4b43172-bb70-4eca-8455-32b0bf27883a",
  "rank_rule": "leq"
}