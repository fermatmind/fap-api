# 任务包 7：Weekly Metrics 指标口径与计划（文档）

版本：v0.1  
更新时间：2026-01-21

---

## 0. 目标
为有效性反馈（validity feedback）提供**周报指标口径**与**生成计划**说明，不实现命令与聚合逻辑。

约束：
- 仅文档交付
- 不影响线上主链路

---

## 1) Feature Flag（预留）
- `WEEKLY_METRICS_ENABLED=0/1`（默认 0）
- 仅作为未来任务包的开关文档占位

---

## 2) 数据源
- 主表：`validity_feedbacks`
- 时间字段：`created_at`
- 幂等字段：`created_ymd`（同 attempt_id 当日唯一）

纳入条件（MVP）：
- 仅统计时间窗口内的记录
- 不做额外有效性过滤（无 `is_valid` 字段）

---

## 3) 聚合维度
- `pack_id`
- `pack_version`
- `report_version`

> 注：这些字段由服务端绑定（见 `validity/feedback-spec.md`），前端不可传入。

---

## 4) 指标口径（MVP）
### 4.1 样本量
- `N`：窗口内记录数

### 4.2 均分
- `avg_score`：`score` 的算术平均，保留 2 位小数

### 4.3 分布
- 1..5 分计数与占比（`count` / `N`）

### 4.4 低分定义（MVP 写死）
- `low_score`：`score <= 2`

### 4.5 低分 Top tags
- 基于 `reason_tags_json` 展开计数
- 统计 `low_score` 样本的 tag 频次，取 Top K（默认 10）

### 4.6 低分 Top type_code
- 统计 `low_score` 样本的 `type_code` 频次，取 Top K（默认 10）

### 4.7 free_text 关键词摘要（MVP 简化）
简化方案（便于落地，未来可升级）：
1) 合并 `low_score` 样本的 `free_text`
2) 统一大小写、去标点、去掉数字与短词（len < 2）
3) 去掉基础停用词（如：的/了/是/and/the 等）
4) 取 Top K 高频词

输出：`keywords`（list of string）

---

## 5) 输出格式建议（Markdown / JSON 均可）
建议字段：
- 时间范围（window_start ~ window_end）
- 维度键（pack_id / pack_version / report_version）
- `N`、`avg_score`
- `distribution`（1..5）
- `low_score_top_tags`
- `low_score_top_type_code`
- `free_text_keywords`

---

## 6) 计划与降级
### 6.1 计划
- 由后续任务包实现命令或 cron 聚合
- 先落地“单窗口聚合”，再扩展为多窗口批量

### 6.2 降级策略
- 无数据：`N=0`，其他指标为空或 `0`
- 聚合失败：**不影响线上 API**（只影响周报产物）

---

## 7) 与主链路关系
- 该周报为离线/后台统计
- **不得影响** `backend/scripts/ci_verify_mbti.sh` 主链路
