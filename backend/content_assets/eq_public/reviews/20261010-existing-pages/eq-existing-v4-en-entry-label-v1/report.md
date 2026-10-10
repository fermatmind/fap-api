**EQ-01/en：PASS（冻结候选正文语义与 locale 身份）。** 未发现需 RETURN 的实质缺陷；本结论不代表部署、publication 或英文线上验收通过。

核查对象：[candidate.json](/Users/rainie/Desktop/FermatMind内容交付/iq-eq-pages-20261009/eq/reviews/eq-existing-v4-en-entry-label-v1/candidate.json)。SHA-256：`8be5aae3800f5e4e42d8b0d4f2f45775691602eeee4d5dec82deb1cf814b8ef8`。

已核实：

- manifest 全部 **131/131** 文件哈希匹配。字节比较结果 `BYTE_EXACT_SINGLE_REPLACEMENT = True`：旧候选仅出现一次的 `Compare versions and start the test` 替换为 `Start the EQ-60 test` 后，与新候选逐字节相同。变化位于 candidate 第137行；`href` 保持 `#choose-version`，其余17项 patch、正文、标题及 SEO 均未变化。
- 将全部18项 patch 在内存中应用于哈希匹配的英文 lookup 后，**11项 FAQ、原 id/q、其他 locale 均保留**。身份仍为 `EQ_60`、`en`、既有 slug；冻结 lookup 只有一个 `eq_60` form，60题、约10分钟、`FREE`。新 label 不再承诺多版本比较。
- 完整英文正文及合并后 registry 解释了 SA/ER/EM/RM、自评与能力测验区别、真实行为反馈、隐私、免费范围及使用限制；未引入第五维、EQ-SJT 参加入口、真人审稿身份或外部工具效度转移。
- 反向计分、POMP、标准分及模型百分位与冻结 scorer 第69、116–143、687–691行一致。独立演算得到 `S=60 → POMP=75`；条件参数 `μ=53.5、σ=7.5 → 标准分113`。正文明确113不是IQ、POMP75不是第75百分位，PROVISIONAL不证明代表性常模。
- 初步内容证据、计划中的信度/结构/外部关联/语言等值验证，以及非临床、非招聘用途，与冻结英文 scientific contract 和 evidence status 相符。报告字段说明也与冻结 Hero、Matrix、EvidenceSnapshot 组件相符。

未核实事项：中文入口 snapshot 第48、115–116行支持单版本入口及该链接文字的语义，但**不能证明英文实页锚点存在、点击可达、候选已经发布或实际报告运行成功**。这些属于实施后的英文页面 QA 与 publication 验收，不构成本轮正文退回理由。

本轮仅执行文件读取、哈希/字节比较、内存 patch 合并和数值复算；未修改候选、产品资产或权限，未提交、推送、部署、采集或执行业务入口。
