以下为 `fm_iq` 返回的完整结果：

结论：**PASS，仅限 SH-02 zh-CN 冻结候选的 IQ 跨领域内容复核。** 未发现需要退回正文的 IQ 科学表述、职业判断或因果外推错误。此结论不代替 EQ 专业审稿、真实页面 QA、生产版本绑定或排名验收。

审查对象：[candidate.json](</Users/rainie/Desktop/FermatMind内容交付/iq-eq-pages-20261009/eq/reviews/sh02-iq-cross-v3/candidate.json>) 中唯一 `SH-02 / zh-CN / iq-eq-balance-at-work`，完整读取 title、excerpt、SEO title/description、正文与全部 7 项 FAQ。下文 B 行号按解码后的 `body_md` 换行计数；源码路径均相对冻结包 `raw/`。

**冻结完整性已核实：**

- 根 manifest 列出的 **132/132 文件 SHA-256 一致**，无缺件。
- `candidate.json` SHA-256：`6b62a1bc1d651e8bada7b29d7489dd4beb5290b6345f82762f2dba873ac5ddcb`。
- 根 `manifest.json` SHA-256：`3c47bcb0f6aa5c13be6e7af5b7edbf335fa2a592b13462366eaec31ee77345fe`。
- 包内另有 `raw/primary/manifest.json`，其 **10/11 项匹配**；`index.json` 的记录哈希为 `7cc9c776…`，实际为 `d7e8bd3c…`。根 manifest 对实际 `raw/primary/index.json` 的绑定正确。该差异不影响本次依靠源码及候选完成的 IQ 判断，但不能宣称所有嵌套 manifest 链全部通过。

| 核查项 | 具体证据 | 判断 |
|---|---|---|
| 30 题原创矩阵／视觉推理身份 | `fap-api/backend/app/Services/Iq/IqOwnerOriginal30BankService.php:15–25` 区分 canonical scale、legacy alias、原创 bank/form；`:34–39` 列视觉推理公开别名。`fap-web/lib/tests/assessmentLandingUi.ts:19` 明示 30 题原创视觉推理；测试入口 `page.tsx:934–950` 对 IQ 展示 30 题。 | B5、B95、B119 与冻结产品说明相符；没有借 legacy `IQ_RAVEN` 宣称官方 Raven、门萨或授权工具。 |
| Beta 来源与资格 | `IqBetaStandardScore.php:13–21` 明确 `random_simulation_baseline`，μ=5.096、σ=2.034；`:59–64` 明确 `production_normed=false`、`claim_eligible=false`、人口百分位资格 false、percentile null；`:71–77` 执行转换与截断。 | B5、B95 正确。未将模拟基线称为代表性人群 IQ，未把正确率写成人口百分位。 |
| 成绩与报告呈现分开 | `fap-web/lib/iq/presentation.ts:109–132` 分别处理正确题数、总题数、正确率及独立 `normative_reasoning` 快照；常模指标须满足 eligible、版本、参照群体等条件。 | 候选只解释任务表现与 Beta 来源，没有承诺用户实际一定看到 Beta，也没有将前端字段存在当常模已验证。 |
| 工作胜任、招聘与薪资 | B5、B7、B91、B95、B99、B119–121 明确本站测分不能决定职业适配、录用、晋升、绩效或薪资。EQ 的交叉边界亦与 `scientific_contract.json:10–14` 一致。 | 未从本站成绩推出复杂岗位胜任、职业上限或人员筛选结论。 |
| 职位标签与双复杂度 | B11、B17–19、B101–103 按一次任务的信息、假设、错误后果、责任、权限和利益冲突观察。 | 没有将整类销售、运营等职业定为“低认知”，也没有将 EQ 写成专业能力发挥的决定因素。双视角是规划方法，不是经验证的职业分型。 |
| 工作样本与反馈 | B29–47 给出数据分类、方案比较、成本、权限、异议与失败；B57、B69–71 指定可检查材料和具体反馈问题，并保留没有观察机会的缺口。 | 有实质任务证据，未用抽象“高低 IQ/EQ”取代观察，也未将缺少成功案例当能力不足证明。 |
| 自然反例与主动调整 | B49 使用此前自然发生的信息整理成功，限制“任何分析或合作都不擅长”的概括，并保留本次错误；B53 是之后主动改变流程；B73 明确区分二者。 | 反例只限制过度概括，没有证明复杂任务胜任、消除本次困难或认定稳定能力。需要类似任务继续检验的条件明确。 |
| 因果与提升外推 | B55 同时保留流程、试行范围、熟悉度及其他变化；B105–107 明确项目改善不能单独证明 IQ/EQ 提升。B21 明确两到八周不是已验证最佳周期。 | 未将一次前后变化写成确定因果，未承诺一般能力增长或最佳训练周期。 |
| metadata 与 FAQ | 标题和摘要承诺处理具体任务、工作样本、反馈及替代解释，正文逐项兑现；7 项 FAQ 没有重新引入固定比例、岗位定级、测分招聘或能力增长主张。 | 内容承诺一致。未承接原页 Schmidt/Hunter、O’Boyle 等未在本包验证的预测研究主张。 |

原始 Brief 的 SH-02 部分位于 `raw/attachments/逐页内容Brief.md:384–413`。候选完成其核心任务：具体项目推理、需求澄清、批评与边界、失败替代解释、工作样本、反馈循环及资源／兴趣／技能的下一步。正文的合成项目在 B25 明确标注，没有冒充实际用户或研究样本。

**推断及适用范围：**工作样本和具体反馈能够帮助定位本次任务问题，是本文的编辑性方法建议。候选没有把它包装为已验证干预、招聘测验或 IQ/EQ 提升方案；因此本次不需要借情绪调节、婚姻重评或其他工具研究替其证明效果。候选中的 `source_boundaries` 自述未作为通过依据。

**最小修正：**

- **正文、FAQ、候选 metadata：无必改项。**
- 包装记录应核对并修正 `raw/primary/manifest.json` 对 `index.json` 的陈旧哈希；若重新冻结，保留新旧版本区分。当前仅能报告根 manifest 全部匹配，不能报告全包所有 manifest 链全部匹配。
- 发布宿主需使正文之外的标题、摘要、OG/Twitter、结构化数据及说明型 CTA 与本候选一致。冻结旧 SEO 仍在描述不同岗位的作用差异；这属于投影交付核查，不能由本次正文 PASS 自动关闭。

**未核实事项：**

1. 实际 production 激活 SHA、运行题本 `items.json` 数量及 release snapshot 字节；源码中的 `questionCount()` 动态读取 items，入口展示 30 题不等于运行题本已验收。
2. 随机模拟原始报告、模拟方法和参数产生过程；本次确认的是源码声明与计算行为。
3. 实际可用人群常模、信效度、年龄／语言适配及参照群体代表性。
4. 候选真实 API/SSR 投影、桌面／手机／交互、全部入口可达性、页面外 CTA 与完整 SEO 投影。
5. 英文 SH-02、EQ 全面科学审稿及三引擎排名。

本轮只读完成哈希核验、完整候选／原 Brief 阅读与相关源码检查；未读取私有答案键或真实 attempt，未执行业务入口、运行测试、写文件、提交、推送或部署。
