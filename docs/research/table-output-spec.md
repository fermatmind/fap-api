# Table-first 表格输出规范（Research Tables Spec）

> 目标：保证研究输出可比、可审计、可复核。任何叙述必须基于表格数据。

## 1. 总原则（Table-first）

- 先表后文：先输出表格，再给文字解读。
- 表格为唯一真源（Single Source of Truth）。
- 所有指标必须可追溯到明确定义、口径与样本范围。

## 2. 表格字段（通用）

- table_id：表格编号（T1/T2/...）
- table_title：表格标题
- row_id：行标识（稳定）
- metric_key：指标键（稳定、可版本化）
- metric_name：指标名称
- value：数值或统计量
- unit：单位（必须显式）
- precision：精度（小数位数）
- n：样本量
- cohort_key：样本/人群分组标识
- window_start：时间窗口起始（YYYY-MM-DD）
- window_end：时间窗口结束（YYYY-MM-DD）
- version：数据/算法版本（vX.Y.Z）
- source：数据来源/口径来源
- note：注释（口径、异常、排除说明）

## 3. 排序规则（通用）

- 优先级：table_id → metric_key → cohort_key → window_start → value
- 当为分位/分数分布：按 score 升序
- 当为排名类指标：按 value 降序

## 4. 精度与单位（默认值，可按需调整）

- score：4 位小数（precision=4）
- percentile：2 位小数（0-100）
- n：整数（precision=0）
- 比例/百分比：2 位小数，单位为 %
- 时间字段：ISO 8601 日期（YYYY-MM-DD）
- 必须显式标注 unit；不得在 note 里隐含单位

## 5. 分页策略（如需）

- 当单表行数 > 500：分页输出
- 分页字段：page, page_size, total_rows
- 每页必须包含完整表头与字段定义

## 6. 注释与口径（必须）

- note 用于：口径说明、异常处理、缺失值处理、模型版本差异
- 口径必须覆盖：样本范围、时间窗口、排除条件
- 所有表格必须附 “数据来源/口径来源” 字段

## 7. Norms 数据字段映射（必须）

- metric_key：指标键（如 mbti_ei_score）
- score：原始或标准化分数
- percentile：百分位（0-100）
- n：样本量
- window_start：时间窗口起始（YYYY-MM-DD）
- window_end：时间窗口结束（YYYY-MM-DD）
- version：常模/算法版本（vX.Y.Z）
- cohort_key（可选）：人群分组键（如 region_age_gender）
- unit（可选）：分数单位或量表标识
- method（可选）：分数计算口径/统计方法
- source（可选）：数据来源或数据集标识
- note（可选）：异常、修订或差异说明

