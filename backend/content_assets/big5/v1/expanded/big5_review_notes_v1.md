# big5_review_notes_v1

版本：v1
状态：P1 内容资产扩展审阅稿
范围：只覆盖文件型 staging 资产，不接 runtime、不写数据库、不改 live registry。

## 本轮扩展层

1. 梯度修饰层：生成 25 个 sentence-level modifier，覆盖 O/C/E/A/N 五维各 g1-g5。用途是后续在结果摘要、五维深解、人格总览、相对参照、成长与下一步动作中注入强度差异。
2. 动力耦合层：生成 12 条核心 synergy，优先覆盖 canonical profiles 中已经出现或最容易支撑 P1 样本的组合。
3. facet 解释层：生成完整 30 facet glossary，写法按 table row、metric card、tooltip/hover copy 共用设计。
4. facet 精准层：生成 23 条 anomaly / split-profile rules，五个 domain 均不少于 4 条，并包含 c_order_maintainer_split 与 e_warm_but_reserved_split。
5. 行动矩阵层：生成 40 条 scenario-bound action rules，覆盖 workplace、relationships、stress_recovery、personal_growth 四个场景。
6. 治理层：生成 manifest 与 coverage matrix，便于后续审稿、接入和差距追踪。

## 潜在歧义

- 低宜人相关 copy 容易滑向“强势 / 不好相处”的评价，需要二审确认所有句子都保留了边界、责任和表达压强的结构解释。
- 高情绪性相关 copy 容易被读成心理问题描述，本轮已避免病理化词汇，但 N5、N6 相关 precision 仍建议重点审。
- 低尽责相关 copy 必须持续避免“懒”的道德化解释；action matrix 中 C_L 场景需要后续结合真实 UI 再做低羞耻表达校验。
- 高开放低尽责 synergy 很关键，但也容易被写成“想太多不落地”。本轮用“理解入口强、执行轨道需承托”作为统一解释。

## 覆盖较好的 canonical profile

- BF_CANONICAL_01 敏锐的独立思考者：直接覆盖 n_high_x_e_low、o_high_x_c_low，并有 N/E/C/O 多个 modifier 与 stress_recovery、personal_growth action 支撑。
- BF_CANONICAL_02 高压现场推动者：直接覆盖 e_high_x_a_low、e_high_x_n_high、a_low_x_n_high，行动层覆盖 workplace、relationships、stress_recovery。
- BF_CANONICAL_03 戒备型完美主义：直接覆盖 c_high_x_n_high，facet precision 与 action matrix 对高标准、高预警、恢复成本支持较强。
- BF_CANONICAL_04 复杂理解型低续航者：直接覆盖 o_high_x_c_low，O/C modifier 与 personal_growth/workplace 动作较充分。
- BF_CANONICAL_05 连接驱动协调者：已补充 e_high_x_a_high，能支撑高外向 × 高宜人的协作势能与边界成本。
- BF_CANONICAL_06 稳态深工者：已补充 e_low_x_c_high，能支撑低外向 × 高尽责的低噪音长续航结构。
- BF_CANONICAL_07 锋利探索推进者：直接覆盖 e_high_x_a_low，并可复用 O 高、E 高、A 低相关 modifier 与行动规则。

## 当前仍需二审或后续加厚的 canonical profile

- BF_CANONICAL_05 连接驱动协调者：已补充 e_high_x_a_high，但仍建议在 lifecycle 阶段加厚关系承接后的恢复与边界文案。
- BF_CANONICAL_06 稳态深工者：已补充 e_low_x_c_high，但低情绪性带来的“恢复较快但可能低估慢性消耗”仍需要下一阶段加厚。
- BF_CANONICAL_08 秩序维护型支持者：已有 o_low_x_c_high 与 A 高、C 高 modifier，但“高宜人 × 高尽责 × 低开放”的三维关系/秩序复合结构仍需要下一轮加厚。

## 明确 deferred

- big5_lifecycle_copy_library_v1。
- history、compare、PDF、continuation、career-next-step、growth-next-step 正式文案库。
- Codex/runtime 接入、registry 接入、数据库 seed 或 migration。

## 审稿建议

- 内容二审优先顺序：synergy library → facet precision → action matrix → gradient modifiers → facet glossary。
- 产品审阅重点：action rule 是否足够场景化、是否能映射到现有 8 section。
- 研发审阅重点：manifest、coverage matrix、id 命名和 source_refs 是否足以支持后续接入。
- 接入前应补一轮 JSON schema 校验，并决定哪些字段进入 runtime contract，哪些只保留为审稿元数据。
