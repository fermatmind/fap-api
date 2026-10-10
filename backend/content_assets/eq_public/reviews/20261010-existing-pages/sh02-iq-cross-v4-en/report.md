以下为 `fm_iq` 返回的完整结果：

SH-02 en：**PASS（fm_iq 跨领域内容复核）**。仅适用于本次冻结候选的完整正文、7 条 FAQ 与所含 metadata，不代表页面发布或线上验收通过。

候选：[candidate.json](</Users/rainie/Desktop/FermatMind内容交付/iq-eq-pages-20261009/eq/reviews/sh02-iq-cross-v4-en/candidate.json:4>)  
SHA256：`6467360229414cac3920f5f84322ecda33bfb11b4b5cb331bd8d502b346c898d`

已运行冻结包校验：manifest 共 **123 项，全部 SHA256 匹配，无缺件或不匹配**。manifest SHA256：`7e27c090674b83fda673e7736f2b7005e987cabae158d9ba27da7f51e9d9837b`。候选只有 SH-02/en 一项，slug 为 `iq-eq-balance-at-work`；解码正文 12,389 字符、124 个实际换行，没有把字面 `\n` 当成正文换行。

专业判断如下：

| 核查项 | 结论与具体依据 |
|---|---|
| 本站 IQ 身份 | 正文限定为 30 道原创矩阵／视觉推理题，没有写成官方 Raven、门萨或完整智力诊断。冻结 [assessmentLandingUi.ts](</Users/rainie/Desktop/FermatMind内容交付/iq-eq-pages-20261009/eq/reviews/sh02-iq-cross-v4-en/raw/fap-web/lib/tests/assessmentLandingUi.ts:19>)明确 30 题矩阵与原创视觉推理定位；该文件第 99 行保留 `owner_original_30` 入口。 |
| Beta 与人群常模 | “random simulation, not representative population IQ norms”准确。冻结 [IqBetaStandardScore.php](</Users/rainie/Desktop/FermatMind内容交付/iq-eq-pages-20261009/eq/reviews/sh02-iq-cross-v4-en/raw/fap-api/backend/app/Services/Assessment/IqBetaStandardScore.php:13>)明确随机模拟来源；第 59 行起将生产常模、主张资格、人口百分位资格均设为 false，百分位为 null。候选没有把 Beta、正确率或题数转换为人口 IQ／排名。 |
| 职业及组织决策边界 | 开头明确不据结果判断整体工作能力、招聘、晋升、岗位分配、薪酬或职业适配；FAQ 与结尾保持一致。没有“低认知岗位”、高分适合复杂职业或 EQ 决定专业能力发挥等桥接。EQ 边界亦与冻结 [scientific_contract.json](</Users/rainie/Desktop/FermatMind内容交付/iq-eq-pages-20261009/eq/reviews/sh02-iq-cross-v4-en/raw/fap-api/backend/content_packs/EQ_60/v1/raw/report_assets/scientific_contract.json:19>)的自评、非能力、非招聘说明一致。 |
| 合成案例与因果边界 | 提醒系统项目明确注明原创虚构、非题目、非真实报告、非验证证据。方案、事件定义、分母、预算、权限、异议、试点失败和调整后观察均有具体展开。没有将项目改善推成 IQ／EQ 增长。 |
| 替代解释与责任 | 将可观察的工作量异议和“不愿合作”的动机解释分开；同时检查取消更新流程、名单时效、责任归属、资源、权限及 Morgan 的具体行为。既没有全归因个人能力，也没有用组织条件抹去个人失误。 |
| 反例与工作证据 | 自然反例只削弱“完全不能分析／协作”的概括，没有否认本次错误。调整后检查漏约、错发信息、人工耗时、无法联系案例与缺失数据；工作样本及反馈问题可执行、可失败、可复盘。 |
| FAQ 与 metadata | 7 条 FAQ 均保持任务层面的判断，不提供职业等级或分数配比。title、excerpt、seo_title、seo_description 与正文的“reasoning, communication and evidence”承诺一致，没有预测或保证效果的措辞。 |

引用核查：候选正文没有外部论文引用、职业元分析系数、效应量或外部工具效度主张。无需借包内 MSCEIT、Bar-On、Gross 或其他论文为本站能力／职业预测背书。正文的实践步骤作为可调整建议呈现；四周、季度、12 个月安排明确为可选规划，没有称为验证过的最佳周期。

**未发现需修改候选字节的 IQ 专业内容问题。**

未核实事项与验收边界：

- 本包未包含当前 IQ bank service、release snapshot 和 alias 映射全文；本次可核实冻结公开定位与 Beta 实现的一致性，不能证明当前生产已激活同一题本或 release。未读取答案键、真实 attempt。
- 冻结 API 中 Guide #35 的正文外 `start_test` 仍指向 MBTI，并带自动相关职业。候选正文的三个链接不含这些项；实际页面仍须由宿主核对并处理其承接关系，不能把自动相关职业当适配建议。
- 未进行候选 API／SSR 投影、桌面／手机、交互、CMS 发布、canonical／robots／hreflang／sitemap 或生产字节读回验收；没有排名结论。

本轮只读返回结果，**未实际写入报告文件**，未修改候选、产品资产、专家配置、权限或定时；无 commit、push、部署及业务写入。
