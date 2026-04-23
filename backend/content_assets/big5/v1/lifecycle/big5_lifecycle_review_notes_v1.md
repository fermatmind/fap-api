# big5_lifecycle_review_notes_v1

版本：v1
状态：第一批 lifecycle staging 资产审阅稿
范围：continuation、career_next_step、growth_next_step。未接 runtime、未写数据库、未改 live registry。

## 本轮处理方式

- 将 3 个桌面草稿统一规范化为 lifecycle schema：entry_id、surface、placement、audience_state、when、title、body、primary_cta_label、secondary_cta_label、notes。
- 顶层不保留 CTA 目标字段，避免被误认为 runtime contract；CTA 映射只放在 notes.cta_targets，供后续审稿和接入设计参考。
- continuation 只负责读后承接：回到 action_plan、history、compare、pdf、retake，不重复五维、facet、synergy 和 action matrix 的主体解释。
- career_next_step 与 growth_next_step 均绑定 8 个 canonical profile family，作为下一步导航语，不生成独立职业报告或成长正文。

## 最成熟的 surface

- continuation：覆盖工作、关系、压力恢复、个人成长、compare、history、pdf、action_plan 回跳，边界最清晰。
- growth_next_step：与 canonical profile 的成长杠杆绑定较稳定，和 action_plan 的分层相对清楚。

## 最容易写成功能说明的 entry

- continuation_compare_ready：容易退化成“点击查看对比”的工具提示，二审时应保留“为什么对比有价值”的一句解释。
- continuation_pdf_archive：容易退化成“下载 PDF”，二审时应保留“可复盘材料”的定位。
- continuation_history_revisit：容易退化成“查看历史”，二审时应保留“长期基线”的使用理由。

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

## 明确 deferred

- history.json
- compare.json
- pdf.json
- shell_tools.json
- retention_closeout.json

## 本次复核修正

- 移除了 manifest 中对本地桌面绝对路径的直接依赖，改为记录为本地 seed 来源。
- 将 source_refs 从泛化锚点改为已存在的 source of truth、section 和 style 章节引用。
- 再次确认 CTA 目标只保留在 notes.cta_targets 中，作为审稿映射，不作为 runtime contract。

## 接入前提醒

- 这些文件是 lifecycle staging assets，不是 runtime payload contract。
- notes.cta_targets 仅是审稿映射，不应直接当作 API 字段发布。
- 接入前应由 runtime/UX 明确现有 surface 的真实路由或锚点名称。
