# Whitepaper 权威页文档模板（Authority Page Template）

> 目标：提供可审计、可复核、可引用的权威白皮书模板。聚焦事实与方法，不做营销表述。

## 0. 文档元信息（E-E-A-T 必填）

- 标题（Title）：
- 结论摘要（非营销）：
- 作者（Author）：姓名 + 职称/角色
- 机构（Institution/Affiliation）：
- 发布日期（datePublished）：YYYY-MM-DD
- 最后更新（dateModified）：YYYY-MM-DD
- 版本号（version）：vX.Y.Z
- 更正渠道（Correction Contact）：邮箱/工单入口/公开 Issue（需可追踪）
- 责任声明（Accountability）：内容负责人 + 审核负责人（如有）
- 许可/引用（License/Citation）：使用协议与推荐引用方式

## 1. 结论摘要（非营销）

- 关键发现（Key Findings）：
- 影响范围（Scope of Applicability）：适用人群/情境
- 效应大小或影响程度（Effect Size）：
- 置信水平或不确定性（Confidence/Uncertainty）：
- 可复核性（Reproducibility）：数据与方法是否可复核

## 2. 样本与方法（Samples & Methods）

- 数据来源（Sources）：
- 样本定义（Sample Definition）：
- 样本量（N）：
- 时间窗口（Time Window）：
- 纳入/排除标准（Inclusion/Exclusion）：
- 预处理与清洗（Preprocessing）：
- 分析方法（Methods）：统计模型/算法/检验方法
- 质量控制（QA/QC）：
- 隐私与合规（Privacy/Compliance）：

## 3. 核心表格（Table-first）

- 必须先给出表格，再给文字解读。
- 表格输出需遵循 `docs/research/table-output-spec.md`。
- 建议核心表格（按需增减）：
  - T1：样本概览（Sample Overview）
  - T2：核心指标/常模（Norms / Metrics）
  - T3：分组对比或效应（Group Comparison / Effects）
  - T4：稳健性/敏感性分析（Robustness / Sensitivity）

## 4. 限制与偏差（Limitations & Biases）

- 选择偏差（Selection Bias）：
- 测量偏差（Measurement Bias）：
- 混杂变量（Confounders）：
- 缺失数据（Missingness）：
- 外部效度（Generalizability）：
- 其他已知限制（Known Limitations）：

## 5. 更正渠道（Corrections & Errata）

- 提交更正方式（Contact/Issue）：
- 处理时限（SLA）：
- 更正发布策略（Errata Log）：
- 版本追踪（Versioning）：

## 6. 引用方式（How to Cite）

- 推荐引用格式（示例）：
  - 作者. 标题. 机构. 发布日期. 版本号. 访问日期.
- 数据集引用（如有 Dataset/DOI）：

## 7. JSON-LD 规范要求（只写字段清单，不写代码）

### 7.1 Article（白皮书页面）

- @type: Article
- headline
- description
- author（name, affiliation）
- publisher（name）
- datePublished
- dateModified
- version
- mainEntityOfPage
- keywords
- about
- citation（可指向 Dataset 或外部研究）
- isBasedOn（如基于其他研究/数据）
- license
- inLanguage

### 7.2 Dataset（数据集或常模）

- @type: Dataset
- name
- description
- creator（name, affiliation）
- publisher（name）
- datePublished
- dateModified
- version
- temporalCoverage
- spatialCoverage（如适用）
- variableMeasured
- measurementTechnique
- distribution（可用下载/访问入口）
- license
- identifier（如 DOI 或内部 ID）
- keywords
- citation
- isBasedOn
- funding（如适用）
- includedInDataCatalog（如适用）

