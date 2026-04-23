# big5_lifecycle_review_notes_v1

版本：v1
状态：v1 core lifecycle staging 资产审阅稿
范围：continuation、career_next_step、growth_next_step、history、compare、pdf、shell_tools、retention_closeout。未接 runtime、未写数据库、未改 live registry。

## 本轮处理方式

- 将 3 个桌面草稿统一规范化为 lifecycle schema：entry_id、surface、placement、audience_state、when、title、body、primary_cta_label、secondary_cta_label、notes。
- 顶层不保留 CTA 目标字段，避免被误认为 runtime contract；CTA 映射只放在 notes.cta_targets，供后续审稿和接入设计参考。
- continuation 只负责读后承接：回到 action_plan、history、compare、pdf、retake，不重复五维、facet、synergy 和 action matrix 的主体解释。
- career_next_step 与 growth_next_step 均绑定 8 个 canonical profile family，作为下一步导航语，不生成独立职业报告或成长正文。
- 第二批新增 history、compare、pdf，继续使用同一 schema，并把三类 surface 都限定为“读完之后如何继续使用结果”的承接层。
- history 负责基线保存、稳定/变化模式和复访周期，不写成“查看历史”的按钮提示。
- compare 负责变化解释入口和边界提示，不写成成绩单，也不重复 norms_comparison 或 action_plan 正文。
- pdf 负责留存、复盘、讨论材料定位，不写成下载说明、权限说明或商业收口。
- 最后一批新增 shell_tools 与 retention_closeout，继续使用同一 schema，并把工具区与页面收口限定为“如何继续使用这份结果”的承接层。
- shell_tools 只组织现有 history、compare、pdf、retake、action_plan 入口，不解释人格结构，也不新增 route 或 runtime 字段。
- retention_closeout 只负责结果页收尾、复访理由和长期使用说明，不写商业化最终转化 CTA。

## 最成熟的 surface

- continuation：覆盖工作、关系、压力恢复、个人成长、compare、history、pdf、action_plan 回跳，边界最清晰。
- growth_next_step：与 canonical profile 的成长杠杆绑定较稳定，和 action_plan 的分层相对清楚。
- history：first baseline、compare ready、stable pattern、shifted pattern、revisit cycle 覆盖完整，适合做长期使用入口。
- compare：not ready、ready、stable、shifted、scenario delta、boundary 覆盖完整，最能避免“分数高低”的误读。
- pdf：archive、discussion、coaching/reflection、unavailable、boundary 覆盖完整，已从“下载功能”转为复盘材料定位。
- shell_tools：覆盖工具区总承接、PDF、history、compare、retake、action_plan 六个入口，适合接在结果页工具区。
- retention_closeout：覆盖首读留存、对比可用、行动优先、复看周期、PDF 讨论材料、长期使用说明六个收口语境，适合作为结果页尾部承接。

## 最容易写成功能说明的 entry

- continuation_compare_ready：容易退化成“点击查看对比”的工具提示，二审时应保留“为什么对比有价值”的一句解释。
- continuation_pdf_archive：容易退化成“下载 PDF”，二审时应保留“可复盘材料”的定位。
- continuation_history_revisit：容易退化成“查看历史”，二审时应保留“长期基线”的使用理由。
- history_first_result_baseline：容易退化成“保存到历史”，二审时要保留“未来可对照”的理由。
- history_second_result_compare_prompt：容易变成 compare 入口提示，二审时要保留“历史从存档转为变化线索”的分层。
- pdf_unavailable_explain：容易写成权限或错误提示，二审时要保持“结果仍可使用”的低压表达。
- shell_tools_pdf_ready：容易退化成“下载 PDF”按钮说明，二审时要保留复盘材料定位。
- shell_tools_retake_ready：容易退化成“重新测试”召回提示，二审时要保留阶段性复测条件。
- shell_tools_group_intro：容易写成工具区功能总览，二审时要保留长期工作台定位。

## 最容易和正文重复的 entry

- compare_scenario_delta_prompt：容易重复 action_plan 的场景行动建议；当前只保留“把变化放回场景”的导航语。
- compare_ready_stable_pattern：容易重复人格稳定性解释；当前只说明稳定结果的使用方式。
- history_shift_signal_read：容易扩写成变化原因分析；当前只提醒先回看环境与节奏。
- pdf_ready_coaching_and_reflection：容易扩写成职业或成长正文；当前只保留材料留存与讨论入口。
- retention_closeout_action_first：容易重复 action_plan 的具体动作；当前只保留“先做一个最小动作”的收口。
- retention_closeout_compare_when_ready：容易重复 compare 正文；当前只说明为什么对比比回忆可靠。
- retention_closeout_long_term_use：容易重复 history/compare 的具体解释；当前只保留长期使用说明。

## 最容易写成伪职业正文的 entry

- career_next_step_canonical_05：连接、协调、承接很容易被扩写成职业角色建议；当前只保留方向入口。
- career_next_step_canonical_06：稳态深工容易被误写成岗位匹配；当前只保留工作环境与可见性提示。
- career_next_step_canonical_07：破局、探索、锋利判断容易被写成商业化职业定位；当前只保留任务环境提示。

## canonical coverage

覆盖最好：
- BF_CANONICAL_01：career 和 growth 均能清楚承接到工作、恢复与个人成长。
- BF_CANONICAL_02：调速、关系缓冲和工作推进的分层清楚。
- BF_CANONICAL_04：理解到推进的 lifecycle 方向清楚。
- BF_CANONICAL_06：职业与成长的分层清楚，一个强调稳定贡献可见性，一个强调更早表达。

仍偏弱：
- BF_CANONICAL_05：高外向 × 高宜人的连接优势容易和关系正文重复，下一轮需要更精细地区分协作承接与边界维护。
- BF_CANONICAL_08：秩序、可靠、支持容易写成静态优点，下一轮需要补更具体的长期使用场景。

## 第二批复核修正

- 将 history、compare、pdf 草稿的 notes.cta_targets 从数组规范为 primary/secondary 对象，和第一批 lifecycle schema 保持一致。
- 统一将 CTA 目标映射为 surface:* 或 anchor:*，只作为 review metadata，不作为 runtime 字段。
- 去掉用户可见标题中的英文概念暴露；正文里只保留必要产品词 PDF，其他场景均使用中文表达。
- 将 history、compare、pdf 从 manifest deferred 中移除，并更新总 entry count 到 41。

## 第三批复核修正

- 将 shell_tools 与 retention_closeout 草稿的 schema 统一为 fap.big5.lifecycle_copy_library.v1.surface_pack。
- 为 shell_tools_group_intro 补齐 primary/secondary CTA label 与 notes.cta_targets，避免出现空 target 或不完整 schema。
- 将草稿中的数组/对象式条件收敛为 reviewer 可读的 when 字段，不新增 runtime 字段。
- 移除 review notes 中残留的英文商业收口词，统一为中文治理表述。
- 将 shell_tools 与 retention_closeout 从 manifest deferred 中移除，并更新总 entry count 到 53。

## 明确 deferred

- none for v1 core lifecycle pack

## 本次复核修正

- 移除了 manifest 中对本地桌面绝对路径的直接依赖，改为记录为本地 seed 来源。
- 将 source_refs 从泛化锚点改为已存在的 source of truth、section 和 style 章节引用。
- 再次确认 CTA 目标只保留在 notes.cta_targets 中，作为审稿映射，不作为 runtime contract。

## 接入前提醒

- 这些文件是 lifecycle staging assets，不是 runtime payload contract。
- notes.cta_targets 仅是审稿映射，不应直接当作 API 字段发布。
- 接入前应由 runtime/UX 明确现有 surface 的真实路由或锚点名称。
