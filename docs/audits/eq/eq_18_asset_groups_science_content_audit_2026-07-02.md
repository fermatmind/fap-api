# EQ 18 类结果页内容资产科学性与商业化丰富度审计报告

日期：2026-07-02

范围：

- `backend/content_packs/EQ_60/v1/raw/report_assets/`
- `backend/content_packs/EQ_60/v1/raw/personalization_routes/route_matrix.json`
- `backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/report_assets/`
- `backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/personalization_routes/route_matrix.json`
- `backend/app/Services/Report/Eq60ReportComposer.php`
- `backend/tests/Fixtures/eq/v5/`

本报告只做扫描和 PR 拆分规划，不修改业务代码、题库、评分、常模、前端、SJT 状态、`docs/codex/pr-train.yaml` 或 `docs/codex/pr-train-state.json`。

## 1. Executive Summary

- 当前 EQ v5/v2.3 report payload 已有 18 类 resolved assets，前端 `EQResultV5` 实际渲染 13 个顶层结果页板块。
- `EQ_60/v1` 与 `EQ_EMOTIONAL_INTELLIGENCE/v1` 的 raw report assets 和 route matrix 当前逐文件一致，镜像 drift 风险低。
- 当前内容资产厚度已明显超过早期版本：30 个 core formulation、48 个 mechanism state、6 个 generic scenes、180 个 scene variants、18 个 career environment level、32 个 action prescriptions、60 条 personalization routes。
- 科学边界总体方向正确：self-report、非 ability、非 MSCEIT、非 clinical、非 hiring、SJT planned/unavailable、all-free contract 均已在核心资产中出现。
- 主要风险已经从“有没有资产”转为“全量资产 claim strength 是否一致、是否足够具体、是否减少 AI 模板感、是否避免过度生活推断”。
- forbidden term 扫描命中大量 `diagnosis`、`hiring`、`MSCEIT`、`unlock` 等词，其中大部分出现在边界声明、拒答样例、agent forbidden claims 或历史兼容字段，不能简单视为违规，但需要按用户可见路径做语义分层。
- `seo_geo_authority.json` 不在本次 18 类 resolved page assets 中，但包含大量 SEO/GEO 教育和 FAQ 内容；后续如果进入结果页、Agent 或公开页面，也需要单独治理。
- `score_system` 与 `psychometric_evidence_status` 仍需进一步产品化：百分位、标准分、band、常模状态必须避免被用户理解为能力排名或全球正式常模。
- `core_formulation`、`mechanism_map`、`reality_scene_variants`、`personalization_route` 是“千人千面”的核心，但也是最容易出现模板味、过度推断和双语 claim drift 的模块。
- `career_environment` 已避免具体职业推荐，但仍需更像职业决策工具：岗位验证问题、组织差异、权力结构、情绪劳动边界需要继续加厚。
- `action_prescriptions` 需要继续把“行动处方”降格为低风险练习建议，避免看起来像治疗、训练保证或确定有效干预。
- `quality` / `quality_confidence` 是低置信路径的主闸门，必须确保 D/SPEEDING 不继续消费强 formulation、强 career、强 action 文案。
- `agent_dialogue_playbooks` 和 `backend_integration_contract` 已具备 Agent 可维护种子，但需要更严格的 retrieval tags、拒答边界、用户意图样例和禁答 fixture。
- 建议拆 19 个 PR：1 个审计/PR train 设计 PR + 18 个资产组修复 PR，每个资产组至少一个 PR，避免一次性大改造成 schema、composer、前端消费和内容科学边界混杂。

## 2. Current Asset Inventory

| # | Asset group | Primary source | Asset count | zh-CN/en | In report payload | Frontend visible | Agent-readable |
|---|---|---:|---:|---|---|---|---|
| 1 | `result_snapshot` | `report_assets/result_snapshot.json` | 10 | yes/yes | yes: `assets.result_snapshot` | yes: Hero | yes |
| 2 | `commercial_conversion_actions` | `report_assets/commercial_conversion_assets.json` | 12 total, 7 selected | yes/yes | yes: `assets.commercial_conversion_actions` | yes: Save/Share/Agent | yes |
| 3 | `scientific_contract` | `report_assets/scientific_contract.json` | 1 | yes/yes | yes | yes | yes |
| 4 | `score_system` | `report_assets/score_system.json` | system object: global, 5 bands, 4 dimensions, dimension bands, notes | yes/yes | yes | yes, via score/matrix/boundary | yes |
| 5 | `core_formulation` | `report_assets/core_formulations.json` | 30 | yes/yes | yes | yes: Hero | yes |
| 6 | `mechanisms` | `report_assets/mechanism_map.json` | 8 pairs x 6 states = 48 | yes/yes | yes | yes | yes |
| 7 | `reality_scenes` | `reality_translation.json`, `reality_scene_variants.json` | 6 generic + 180 variants | yes/yes | yes | yes | yes |
| 8 | `career_environment` | `report_assets/career_environment.json` | 6 variables x 3 levels = 18 | yes/yes | yes | yes | yes |
| 9 | `action_prescription` | `report_assets/action_prescriptions.json` | 32 | yes/yes | yes | yes | yes |
| 10 | `sjt_bridge` | `report_assets/sjt_bridge.json` | 5 | yes/yes | yes | yes | yes |
| 11 | `cross_assessment_context` | `report_assets/cross_assessment_context.json` | 52 | yes/yes | yes | yes | yes |
| 12 | `quality` | Composer runtime fields + `quality_confidence` | runtime object | yes/yes through resolved assets | yes | yes | yes |
| 13 | `quality_confidence` | `report_assets/quality_confidence.json` | 4 | yes/yes | yes | yes | yes |
| 14 | `psychometric_evidence_status` | `report_assets/psychometric_evidence_status.json` | 7 | yes/yes | yes | partly, via boundary/evidence | yes |
| 15 | `result_page_depth_modules` | `report_assets/result_page_depth_modules.json` | 63 | yes/yes | yes | yes | yes |
| 16 | `agent_dialogue_playbooks` | `report_assets/agent_dialogue_playbooks.json` | 5 | yes/yes | yes | not a main report section, Agent surface | yes |
| 17 | `backend_integration_contract` | `report_assets/backend_integration_contract.json` | 5 | yes/yes | yes | not user-facing by default | yes |
| 18 | `personalization_route` | `personalization_routes/route_matrix.json` | 60 | yes/yes | yes | partly: headline/route evidence | yes |

Observed frontend sections:

1. `EQResultHero`
2. `EQEvidenceSnapshot`
3. `EQResultDepthModules`
4. `EQQualityBanner`
5. `EQEmotionalMatrix`
6. `EQMechanismCard`
7. `EQRealitySceneCards`
8. `EQCareerEnvironmentLens`
9. `EQActionPrescription`
10. `EQSJTBridgeCTA`
11. `EQScientificBoundary`
12. `EQCrossAssessmentContext`
13. `EQSaveShareRelated` including Agent entry

## 3. Scientific Boundary Audit

### Self-report boundary

Status: present but should be made consistently unavoidable.

The strongest boundary language appears in `scientific_contract`, `sjt_bridge`, `cross_assessment_context`, `agent_dialogue_playbooks`, and `agent_knowledge_base_schema`. The risk is not absence of boundary; it is uneven placement. Some rich modules such as scene variants, action prescriptions, career environment, and route headlines may be read before a user reaches the Scientific Boundary section.

Required next step: every high-interpretation asset family should include a concise self-report scope field or embedded cautious phrasing.

### Non ability test

Status: generally present.

Risk remains in English phrasing where terms like capability, skill, performance, response quality, strategy, or functioning can sound ability-like if used without boundary. EQ-60 should consistently say self-perceived emotional and relational patterns, not true emotional ability.

### Non MSCEIT

Status: present in SJT bridge and SEO/GEO FAQ.

Risk: SJT future bridge mentions scenario judgment. It must continue to say planned/unavailable and not MSCEIT-like. No clickable SJT route should be implied before the SJT product exists.

### Non clinical diagnosis

Status: present.

Risk: repeated phrases around distress, treatment, safety, or clinical support are useful, but some user-facing instances can sound like advice for mental health triage. Keep as boundary/referral, not clinical guidance.

### Non hiring screening

Status: present.

Risk: `career_environment`, `cross_assessment_context`, and Agent playbooks mention hiring boundary. The system must ensure career environment variables never become selection, promotion, role assignment, or job-performance prediction.

### Non job performance prediction

Status: present in boundary and SEO assets.

Risk: career language such as fit, strain, collaboration complexity, emotional labor, and feedback intensity can drift into job-performance inference. The right framing is environment questions to verify, not occupational prediction.

### SJT planned/unavailable

Status: correct in current payload: `next_module.available=false`, `sjt_bridge` planned/unavailable.

Risk: conversion and Agent entry must not present SJT as a next test route until SJT take flow, content pack, scorer, and integrated report are implemented and tested.

### No paywall / unlock / premium

Status: all-free product strategy is preserved. Search hits for `unlock` mostly appear in legacy compatibility fields such as `unlock_source=none` / `unlock_stage=full` or forbidden claim metadata.

Risk: user-visible conversion copy should avoid unlock/premium language entirely. Future commercial conversion assets must stay free-retention oriented: save, revisit, share, retest, related tests, Agent explanation.

## 4. Content Quality Audit

### AI/template smell

Current highest-risk areas:

- 180 scene variants can read formulaic if every card follows the same rhythm: typical response, strength, cost, better move, boundary.
- 60 personalization routes risk route IDs being more differentiated than the user-facing route copy.
- mechanism states can sound like deterministic life conclusions if not anchored to the two or three score signals that triggered them.
- result_page_depth_modules at 63 assets are useful, but may feel like explanatory filler if not ordered and selected sharply.

### Repetition

Boundary phrases are intentionally repeated, but the report risks fatigue if the same “not diagnosis / not hiring / not ability” sentence appears in every section. Better pattern: one global boundary, plus short local boundaries only where the module could overclaim.

### Specificity

Reality and career assets are now broad enough, but still need more “this says me” texture:

- trigger condition
- what the user might notice
- what not to conclude
- one precise low-risk experiment
- when not to apply the advice

### Translation quality

English is mostly serviceable, but some phrases can still feel like translated professional documentation rather than native consumer psychology copy. Chinese is safer after the science-boundary repair, but can become overly formal and less memorable.

### Evidence boundary

`psychometric_evidence_status` exists, but evidence status needs better productization: what is known, what is provisional, what is not yet validated, and what a user should do with that uncertainty.

### Next action

Action prescriptions and conversion assets exist, but a commercial result page needs a clear sequence:

1. save result
2. read one relevant deep module
3. try one low-risk practice
4. optionally ask read-only Agent about one concrete scenario
5. retest later

## 5. Asset Group Findings

### Asset Group 1: `result_snapshot`

- 当前用途：首屏 summary，承接 formulation、evidence point、minimal action、share-safe line、continue path。
- 当前强项：10 个 formulation 对应 snapshot 已覆盖，适合 Hero 第一屏。
- 主要问题：首屏钩子还可更 sharp；部分 formulation 的 share-safe line 与 one-liner 可能相似，记忆点不足。
- 科学风险：若首屏标题过强，用户会先看到标签，再看到边界。
- 内容厚度缺口：需要每个 snapshot 有 evidence anchor、what-not-to-read、one useful next action。
- AI 味 / 模板味问题：不同 formulation 之间句式可能趋同。
- zh-CN/en 问题：英文需要更 native、短句化；中文避免“你就是……”。
- Agent 维护风险：Agent 可能过度引用 snapshot 作为完整结论。
- 建议修复方向：首屏文案分层为 `claim`, `evidence_anchor`, `one_small_move`, `do_not_overread`, `share_safe_line`。
- 是否需要 schema 变更：可选；优先在现有字段内修。
- 是否需要 composer 变更：否。
- 是否需要前端变更：否。
- 推荐 PR id：`PR-EQ-ASSET-SCI-01`。

### Asset Group 2: `commercial_conversion_actions`

- 当前用途：保存、邮件回看、PDF、分享卡、复测、相关测试、Agent entry 等免费留存动作。
- 当前强项：已从付费转化转向免费产品留存。
- 主要问题：需要明确所有 conversion 都是 free continuation，不是 unlock；Agent entry 不能暗示报告可被改写。
- 科学风险：PDF/分享卡如果过度简化，可能传播强标签。
- 内容厚度缺口：每个动作需要目的、适用时机、边界、用户收益。
- AI 味 / 模板味问题：CTA 文案可能偏通用 SaaS。
- zh-CN/en 问题：英文 conversion copy 要更短、更自然；中文避免“升级”“解锁”。
- Agent 维护风险：Agent entry 可能成为“问 Agent 得最终答案”的入口。
- 建议修复方向：所有 action 增加 free-retention framing 和 report-authority boundary。
- 是否需要 schema 变更：否。
- 是否需要 composer 变更：否。
- 是否需要前端变更：可能需要，若新增动作类型或排序。
- 推荐 PR id：`PR-EQ-ASSET-SCI-02`。

### Asset Group 3: `scientific_contract`

- 当前用途：全局科学边界、self-report、非 ability、非 clinical、非 hiring。
- 当前强项：边界完整，是当前 EQ 内容系统的安全锚。
- 主要问题：边界说明偏说明书，需要更用户可读、更可扫描。
- 科学风险：若只放在页面后部，用户可能先记住强 formulation。
- 内容厚度缺口：需要“如何正确使用结果”和“何时不使用结果”更明确。
- AI 味 / 模板味问题：边界段落容易像合规模板。
- zh-CN/en 问题：中文略正式；英文需更 consumer-native。
- Agent 维护风险：Agent 必须优先检索该资产作为回答边界。
- 建议修复方向：拆成 `what_it_reflects`, `what_it_does_not_measure`, `how_to_use`, `when_to_get_support`。
- 是否需要 schema 变更：可选。
- 是否需要 composer 变更：否。
- 是否需要前端变更：否。
- 推荐 PR id：`PR-EQ-ASSET-SCI-03`。

### Asset Group 4: `score_system`

- 当前用途：总分、维度、band、percentile、standard score、score notes。
- 当前强项：已经区分 self-report 和 provisional norm。
- 主要问题：标准分和百分位仍可能被用户读成能力排名；band 名称可能被读成发展等级判断。
- 科学风险：percentile / band 最容易被截图传播为“我的真实 EQ 水平”。
- 内容厚度缺口：需要解释 score uncertainty、response quality、sample context。
- AI 味 / 模板味问题：维度定义容易泛化，缺少维度之间边界。
- zh-CN/en 问题：英文 terms like stable/proficient/integrated need careful non-ability framing。
- Agent 维护风险：Agent 可能把 percentile 当排名解释。
- 建议修复方向：所有 score explainer 明确“阶段性自评样本中的相对位置感，不是能力排名”。
- 是否需要 schema 变更：否。
- 是否需要 composer 变更：可能需要，如果新增 display copy 或 score caveat fields。
- 是否需要前端变更：可能需要，若前端要展示新 caveat。
- 推荐 PR id：`PR-EQ-ASSET-SCI-04`。

### Asset Group 5: `core_formulation`

- 当前用途：核心洞察和主 formulation。
- 当前强项：30 条 formulation 已具备千人千面基础。
- 主要问题：formulation 越多，claim strength 越难一致；标题仍可能像稳定人格类型。
- 科学风险：从 self-report 维度直接组织成“核心模式”，易被读成人格诊断。
- 内容厚度缺口：每条需要更强 evidence basis、alternative explanations、do-not-overread。
- AI 味 / 模板味问题：优势、代价、发展杠杆字段容易套模板。
- zh-CN/en 问题：英文标题需避免 type-label feel；中文避免定性过强。
- Agent 维护风险：Agent 可能只看 formulation id 回答，不看 quality/evidence。
- 建议修复方向：所有 formulation 标题、one-liner、core_claim 加 `this response pattern suggests` / `本次作答显示`。
- 是否需要 schema 变更：否。
- 是否需要 composer 变更：否，除非增加 route-aware formulation selection。
- 是否需要前端变更：否。
- 推荐 PR id：`PR-EQ-ASSET-SCI-05`。

### Asset Group 6: `mechanisms`

- 当前用途：解释 SA/ER/EM/RM 之间的组合机制。
- 当前强项：8 个机制 pair/group x 6 state，解释力强于普通维度条形图。
- 主要问题：机制资产可能从两个维度分数推出过多现实生活结论。
- 科学风险：机制不是行为观测，也不是因果模型。
- 内容厚度缺口：需要把 `why_it_matters` 与 `what_it_feels_like` 做成假设语言，而非确定结论。
- AI 味 / 模板味问题：每个机制状态结构相同，语言容易重复。
- zh-CN/en 问题：英文需避免 “you tend to” 过强；中文避免“会导致”。
- Agent 维护风险：Agent 可能把 mechanism 当预测规则。
- 建议修复方向：每个 mechanism 增加 evidence chain 和 uncertainty phrase。
- 是否需要 schema 变更：否。
- 是否需要 composer 变更：可能需要，若要减少同时输出机制数量或按 confidence 过滤。
- 是否需要前端变更：否。
- 推荐 PR id：`PR-EQ-ASSET-SCI-06`。

### Asset Group 7: `reality_scenes`

- 当前用途：把结果转译到反馈、冲突、边界、团队、压力恢复、职业环境场景。
- 当前强项：6 generic scenes + 180 formulation-aware variants，已经具备商业化深度。
- 主要问题：scene variants 是最大模板味来源；如果每个 formulation 都套同一场景结构，用户会感觉“换皮”。
- 科学风险：现实场景推断必须受文化、权力差、安全关系、创伤、高冲突限制。
- 内容厚度缺口：需要更细的 relationship risk、power dynamics、context-fit disclaimers。
- AI 味 / 模板味问题：typical response / strength / cost / better move 容易模板化。
- zh-CN/en 问题：英文现实感需更口语；中文避免咨询师腔。
- Agent 维护风险：Agent 可能直接给冲突/边界建议，需安全升级规则。
- 建议修复方向：按场景族重写一轮：反馈、冲突、边界、团队、压力、职业各自独立风格。
- 是否需要 schema 变更：否。
- 是否需要 composer 变更：可能需要，若要更严格 variant fallback 或场景去重。
- 是否需要前端变更：可能需要，若新增 visible risk note。
- 推荐 PR id：`PR-EQ-ASSET-SCI-07`。

### Asset Group 8: `career_environment`

- 当前用途：职业环境变量，不做具体职业推荐。
- 当前强项：6 变量 x 3 levels，方向正确。
- 主要问题：fit/strain language 仍可能被读成岗位适配判断。
- 科学风险：不能从 EQ 自评推断职业表现、职业成功、适合/不适合某岗位。
- 内容厚度缺口：需要面试验证问题、岗位观察清单、组织文化差异、管理风格差异。
- AI 味 / 模板味问题：变量解释可能像 HR competency model。
- zh-CN/en 问题：中文“消耗/适配”需降低确定性；英文 “fit” 需谨慎。
- Agent 维护风险：Agent 可能回答“我适合做什么工作”。
- 建议修复方向：把职业模块全部改成 environment-check lens：ask, observe, test, do-not-conclude。
- 是否需要 schema 变更：可选，若新增 interview_questions / observation_checklist。
- 是否需要 composer 变更：否。
- 是否需要前端变更：可能需要，若新增清单字段。
- 推荐 PR id：`PR-EQ-ASSET-SCI-08`。

### Asset Group 9: `action_prescription`

- 当前用途：今天做什么、脚本、7 天练习、watch out。
- 当前强项：32 条处方覆盖主要 formulation / lever。
- 主要问题：“处方”一词和 7 天练习容易显得像确定有效干预。
- 科学风险：不能承诺改善、治疗、关系修复成功或职场冲突解决。
- 内容厚度缺口：需要适用/不适用条件、高风险关系边界、何时寻求支持。
- AI 味 / 模板味问题：脚本容易像通用沟通建议。
- zh-CN/en 问题：英文 scripts 需更自然；中文避免鸡汤化。
- Agent 维护风险：Agent 可能扩写成治疗建议。
- 建议修复方向：所有 action 改为 low-risk practice / reflection experiment。
- 是否需要 schema 变更：否。
- 是否需要 composer 变更：可能需要，低置信时强制只选 retest_reflection。
- 是否需要前端变更：否。
- 推荐 PR id：`PR-EQ-ASSET-SCI-09`。

### Asset Group 10: `sjt_bridge`

- 当前用途：说明 EQ-SJT 16 题未来模块，当前 planned/unavailable。
- 当前强项：明确不是 MSCEIT、不是认证测评。
- 主要问题：有 5 个 bridge variants，需要统一避免“继续完成”被理解为可点击入口。
- 科学风险：scenario judgment 仍可能被误读成 true ability。
- 内容厚度缺口：需要更清楚解释“补充 self-report 的是什么，不补充什么”。
- AI 味 / 模板味问题：未来模块说明可能像 roadmap 文案。
- zh-CN/en 问题：英文 “judgment” / “scenario” 需避免 assessment credential tone。
- Agent 维护风险：Agent 可能建议用户开始 SJT。
- 建议修复方向：统一 planned copy，明确 unavailable and not ability certification。
- 是否需要 schema 变更：否。
- 是否需要 composer 变更：否。
- 是否需要前端变更：否，除非要隐藏 button-like UI。
- 推荐 PR id：`PR-EQ-ASSET-SCI-10`。

### Asset Group 11: `cross_assessment_context`

- 当前用途：连接 EQ 与 MBTI / Big Five / Enneagram / RIASEC 等上下文。
- 当前强项：52 个资产，已经形成跨测评解释基础。
- 主要问题：跨测评最容易产生“综合判断过度自信”。
- 科学风险：不能从其他测试类型推导 EQ，也不能用 EQ 修正其他测试。
- 内容厚度缺口：需要说明 correlation vs analogy vs decision lens。
- AI 味 / 模板味问题：跨测评连接容易写成“X 类型 + EQ 说明你……”模板。
- zh-CN/en 问题：英文需避免 deterministic integration；中文避免“组合画像”过强。
- Agent 维护风险：Agent 容易把多测试拼成固定人格结论。
- 建议修复方向：把 cross-assessment 定位为 reading lens，不是 evidence merge。
- 是否需要 schema 变更：否。
- 是否需要 composer 变更：可能需要，若按已有用户 profile memory 选择上下文。
- 是否需要前端变更：否。
- 推荐 PR id：`PR-EQ-ASSET-SCI-11`。

### Asset Group 12: `quality`

- 当前用途：runtime quality object：level、confidence_label、flags、explanation_asset_id。
- 当前强项：已进入 payload，并能驱动 low_confidence_result。
- 主要问题：quality 自身不是内容资产文件，容易被其他资产绕过。
- 科学风险：低质量结果若仍显示正常 hero、career、action，会破坏测量边界。
- 内容厚度缺口：需要质量状态机说明：A/B/C/D/SPEEDING 各自允许哪些模块强度。
- AI 味 / 模板味问题：低置信提示如果通用，会显得像错误提示。
- zh-CN/en 问题：低置信中文要避免责备，英文避免 “invalid” harsh tone。
- Agent 维护风险：Agent 必须先看 quality 再回答。
- 建议修复方向：定义 quality gating matrix：哪些资产可展示、降级、隐藏。
- 是否需要 schema 变更：可能需要。
- 是否需要 composer 变更：是。
- 是否需要前端变更：可能需要。
- 推荐 PR id：`PR-EQ-ASSET-SCI-12`。

### Asset Group 13: `quality_confidence`

- 当前用途：质量解释、复测建议、低置信说明。
- 当前强项：4 个等级资产，覆盖 A-D。
- 主要问题：D/SPEEDING 需要更强地阻止强解释链路。
- 科学风险：低置信结果若只显示 banner，不足以抵消后续强内容。
- 内容厚度缺口：缺少 “what can still be used” 和 “what should not be used”。
- AI 味 / 模板味问题：低置信文案容易重复“谨慎参考”。
- zh-CN/en 问题：中文避免批评用户；英文避免 overly clinical wording。
- Agent 维护风险：Agent 在低置信时应只做复测准备和轻量反思。
- 建议修复方向：低置信资产增加 allowed_help / blocked_help。
- 是否需要 schema 变更：可选。
- 是否需要 composer 变更：是，若要低置信全局降级。
- 是否需要前端变更：可能需要。
- 推荐 PR id：`PR-EQ-ASSET-SCI-13`。

### Asset Group 14: `psychometric_evidence_status`

- 当前用途：常模、内容效度、内部一致性、因子结构、重测、测量等值、效标效度说明。
- 当前强项：7 个证据状态资产让科学边界更透明。
- 主要问题：证据状态如果太技术，用户不读；如果太营销，变成权威背书。
- 科学风险：provisional evidence 不能包装成正式验证。
- 内容厚度缺口：需要每项状态有 user meaning、current limitation、future validation。
- AI 味 / 模板味问题：科学术语堆叠。
- zh-CN/en 问题：英文 scientific terms 要准确，中文要避免学术腔过重。
- Agent 维护风险：Agent 可能引用证据状态过度背书。
- 建议修复方向：做成 product evidence cards：known / not yet known / how to read it。
- 是否需要 schema 变更：否。
- 是否需要 composer 变更：否。
- 是否需要前端变更：可能需要，若要单独 evidence cards。
- 推荐 PR id：`PR-EQ-ASSET-SCI-14`。

### Asset Group 15: `result_page_depth_modules`

- 当前用途：深读模块，帮助结果页从解释进入证据、现实和行动。
- 当前强项：63 个资产，扩展空间大。
- 主要问题：如果 selection 不够精确，会让页面变长但不更有价值。
- 科学风险：深读模块可能把假设解释写成确定结论。
- 内容厚度缺口：需要每个 depth module 绑定 route/formulation/quality。
- AI 味 / 模板味问题：最容易产生“看起来丰富但泛泛”的大段内容。
- zh-CN/en 问题：英文要减少 explanatory essay feel；中文要更像产品模块。
- Agent 维护风险：Agent 检索可能召回太宽泛内容。
- 建议修复方向：按 reading path 分层：30秒、3分钟、深读；每层只服务一个问题。
- 是否需要 schema 变更：可能需要 selection metadata。
- 是否需要 composer 变更：可能需要，若要 route-aware depth selection。
- 是否需要前端变更：可能需要。
- 推荐 PR id：`PR-EQ-ASSET-SCI-15`。

### Asset Group 16: `agent_dialogue_playbooks`

- 当前用途：Agent 理解结果、场景建议、职业环境、低置信、unsupported hiring 的对话策略。
- 当前强项：已包含边界、拒答和只读原则。
- 主要问题：5 个 playbook 对 18 类资产和 60 routes 来说偏少。
- 科学风险：Agent 最容易突破边界，尤其是招聘、诊断、关系危机、职业预测。
- 内容厚度缺口：需要更多 intent examples、retrieval tags、safe response skeleton、escalation rule。
- AI 味 / 模板味问题：playbook 本身还像 seed，不像完整操作系统。
- zh-CN/en 问题：拒答样例两种语言需同强度。
- Agent 维护风险：高。
- 建议修复方向：建立 Agent KB schema v2：intent -> allowed assets -> blocked claims -> response shape。
- 是否需要 schema 变更：可能需要。
- 是否需要 composer 变更：可能需要，若 runtime context 要筛选 playbooks。
- 是否需要前端变更：否。
- 推荐 PR id：`PR-EQ-ASSET-SCI-16`。

### Asset Group 17: `backend_integration_contract`

- 当前用途：后端权威层、schema mapping、frontend consumption、canonical fixtures、contract tests、agent readiness。
- 当前强项：工程治理意识强，能防止前端硬编码内容。
- 主要问题：当前更像工程注释资产，不一定需要进入用户可见 payload。
- 科学风险：低，但如果被 Agent 错当成用户解释文案，会显得技术化。
- 内容厚度缺口：需要明确哪些字段是 public-reader safe，哪些仅 internal。
- AI 味 / 模板味问题：偏工程文档口吻。
- zh-CN/en 问题：不是主要问题。
- Agent 维护风险：Agent 可能暴露 internal contract。
- 建议修复方向：把 backend contract 标为 internal/agent-policy only，不给前端用户可见渲染。
- 是否需要 schema 变更：可能需要 visibility flag。
- 是否需要 composer 变更：可能需要，若从 public report assets 移到 agent context only。
- 是否需要前端变更：否。
- 推荐 PR id：`PR-EQ-ASSET-SCI-17`。

### Asset Group 18: `personalization_route`

- 当前用途：60 条 deterministic route，决定 route headline、selected assets、signal signature。
- 当前强项：已经是 EQ 千人千面的核心。
- 主要问题：route 数量足够，但 route 差异必须体现在用户可见内容，而不是只在 selected_asset_ids。
- 科学风险：route 不能变成固定人格类型或能力标签。
- 内容厚度缺口：每条 route 需要 route-level evidence label、why-specific、do-not-overread、next best read。
- AI 味 / 模板味问题：route copy 高度可能模板化。
- zh-CN/en 问题：英文 route headline 要像 native product copy；中文避免“某某型”。
- Agent 维护风险：Agent 可能把 route_id 当人格类型。
- 建议修复方向：route matrix v3：route headline + evidence anchor + one scene + one action + boundary。
- 是否需要 schema 变更：可能需要。
- 是否需要 composer 变更：是。
- 是否需要前端变更：可能需要，若展示 route headline/depth。
- 推荐 PR id：`PR-EQ-ASSET-SCI-18`。

## 6. Comparison With MBTI / Big Five / Enneagram

### 已经接近的部分

- EQ 已有独立 renderer、resolved assets、content pack authority、canonical fixtures、all-free contract。
- 18 类资产覆盖了首屏、证据、质量、矩阵、机制、现实场景、职业环境、行动、SJT、科学边界、跨测评、Agent。
- route matrix 60 条、scene variants 180 条，已经具备超越通用 EQ 小测的个性化基础。

### 明显不足的部分

- MBTI / Big Five / Enneagram 的商业化体验通常有更强“持续阅读路径”和“身份记忆点”；EQ 当前更科学克制，但首屏记忆点还可更强。
- EQ 的科学边界比其他人格测试更敏感，因此内容厚度增加后，需要更精细 claim governance。
- EQ 的 career environment 尚未像职业决策工具一样提供足够的验证问题、岗位观察清单和组织差异。
- EQ 的 Agent 资产仍偏 seed，需要成为可检索、可拒答、可审计的内容操作系统。

### 需要加厚的模块

- `result_snapshot`
- `reality_scenes`
- `career_environment`
- `action_prescription`
- `result_page_depth_modules`
- `personalization_route`
- `agent_dialogue_playbooks`

### 需要降 claim 的模块

- `core_formulation`
- `mechanisms`
- `score_system`
- `psychometric_evidence_status`
- `career_environment`
- `action_prescription`
- `cross_assessment_context`

### 需要更千人千面的模块

- `personalization_route`
- `result_snapshot`
- `reality_scene_variants`
- `mechanisms`
- `action_prescriptions`
- `career_environment`

## 7. PR Split Recommendation

建议总计 19 个 PR：1 个扫描报告 PR + 18 个内容资产修复 PR。

| PR | Title | Repo | Branch | Depends on | Scope | Files likely touched | Non-goals | Local checks | Risk | Acceptance criteria | Frontend follow-up |
|---|---|---|---|---|---|---|---|---|---|---|---|
| PR-EQ-ASSET-AUDIT-00 | 18 类 EQ 内容资产科学审计与 PR train 设计 | fap-api | `codex/pr-eq-asset-audit-00-18-groups` | none | docs-only audit | `docs/audits/eq/*.md` | no content fixes | `git diff --check` | low | report complete | no |
| PR-EQ-ASSET-SCI-01 | `result_snapshot` 科学边界与首屏钩子加厚 | fap-api | `codex/pr-eq-asset-sci-01-result-snapshot` | 00 | snapshot copy | `result_snapshot.json`, mirror, compiled, fixtures | no composer | lint/compile, Eq60 fixtures | medium | 10 snapshots stronger and safer | no |
| PR-EQ-ASSET-SCI-02 | `commercial_conversion_actions` 免费转化动作与留存语义修复 | fap-api | `codex/pr-eq-asset-sci-02-conversion-actions` | 00 | conversion assets | `commercial_conversion_assets.json`, mirror | no paywall | lint/compile, paywall test | medium | no unlock/premium, all-free retention | maybe |
| PR-EQ-ASSET-SCI-03 | `scientific_contract` 科学边界重写 | fap-api | `codex/pr-eq-asset-sci-03-scientific-contract` | 00 | boundary | `scientific_contract.json`, mirror | no scoring | lint/compile, contract test | high | self-report and non-use boundaries clear | no |
| PR-EQ-ASSET-SCI-04 | `score_system` 分数、百分位、band 与常模边界修复 | fap-api | `codex/pr-eq-asset-sci-04-score-system` | 03 | score language | `score_system.json`, mirror, fixtures | no score algorithm | lint/compile, V5 contract | high | no ability ranking wording | maybe |
| PR-EQ-ASSET-SCI-05 | `core_formulation` 全量降断言与个性化加厚 | fap-api | `codex/pr-eq-asset-sci-05-core-formulation` | 03,04 | 30 formulations | `core_formulations.json`, mirror, fixtures | no selection logic | lint/compile, golden cases | high | all formulations self-report framed | no |
| PR-EQ-ASSET-SCI-06 | `mechanism_map` 机制证据链收紧与去模板化 | fap-api | `codex/pr-eq-asset-sci-06-mechanisms` | 05 | 48 mechanism states | `mechanism_map.json`, mirror | no dimensions/scoring | lint/compile, contract | high | no causal/behavior prediction | maybe composer |
| PR-EQ-ASSET-SCI-07 | `reality_scenes` / variants 现实场景加厚与边界修复 | fap-api | `codex/pr-eq-asset-sci-07-reality-scenes` | 06 | 6 generic + 180 variants | `reality_translation.json`, `reality_scene_variants.json`, mirror | no route matrix | lint/compile, fixtures | high | scene variants specific but bounded | maybe |
| PR-EQ-ASSET-SCI-08 | `career_environment` 职业环境变量科学化与决策工具化 | fap-api | `codex/pr-eq-asset-sci-08-career-environment` | 05 | 18 career level assets | `career_environment.json`, mirror | no career recommendation | lint/compile, forbidden scan | high | no job fit/prediction claims | likely |
| PR-EQ-ASSET-SCI-09 | `action_prescription` 行动处方降干预承诺与实用加厚 | fap-api | `codex/pr-eq-asset-sci-09-action-prescription` | 05,13 | 32 prescriptions | `action_prescriptions.json`, mirror | no treatment claims | lint/compile, low-confidence fixtures | high | practices framed as low-risk | no |
| PR-EQ-ASSET-SCI-10 | `sjt_bridge` planned/unavailable 边界与未来模块说明修复 | fap-api | `codex/pr-eq-asset-sci-10-sjt-bridge` | 03 | SJT bridge | `sjt_bridge.json`, mirror | no SJT route/scorer | lint/compile, no-SJT tests | high | unavailable, not MSCEIT, not ability | no |
| PR-EQ-ASSET-SCI-11 | `cross_assessment_context` 跨测评上下文边界与价值说明 | fap-api | `codex/pr-eq-asset-sci-11-cross-assessment` | 05 | 52 cross assets | `cross_assessment_context.json`, mirror | no profile memory | lint/compile, contract | medium | no deterministic cross-test conclusion | maybe |
| PR-EQ-ASSET-SCI-12 | `quality` 基础质量字段与低置信语义修复 | fap-api | `codex/pr-eq-asset-sci-12-quality-runtime` | 03 | quality gating design | `Eq60ReportComposer.php`, tests, maybe docs | no asset rewrite except needed | Eq60V5ReportContractTest, low-confidence | high | low confidence gates strong modules | yes maybe |
| PR-EQ-ASSET-SCI-13 | `quality_confidence` 低置信解释、复测建议与重复文案清理 | fap-api | `codex/pr-eq-asset-sci-13-quality-confidence` | 12 | confidence assets | `quality_confidence.json`, mirror, fixtures | no scorer | lint/compile, low-confidence fixtures | high | D/SPEEDING only cautious path | maybe |
| PR-EQ-ASSET-SCI-14 | `psychometric_evidence_status` 证据状态产品化与科学措辞修复 | fap-api | `codex/pr-eq-asset-sci-14-evidence-status` | 03,04 | evidence assets | `psychometric_evidence_status.json`, mirror | no validation claim | lint/compile, contract | medium | provisional evidence clear | maybe |
| PR-EQ-ASSET-SCI-15 | `result_page_depth_modules` 深读模块加厚与去 AI 味 | fap-api | `codex/pr-eq-asset-sci-15-depth-modules` | 05,07 | 63 depth modules | `result_page_depth_modules.json`, mirror | no new UI | lint/compile, fixtures | medium | selected depth useful and bounded | likely |
| PR-EQ-ASSET-SCI-16 | `agent_dialogue_playbooks` Agent 检索、拒答和追问边界修复 | fap-api | `codex/pr-eq-asset-sci-16-agent-playbooks` | 03,12 | Agent playbooks | `agent_dialogue_playbooks.json`, maybe `agent_knowledge_base_schema.json`, mirror | no live LLM | EqAgent tests, lint/compile | high | forbidden claims and retrieval policy stronger | no |
| PR-EQ-ASSET-SCI-17 | `backend_integration_contract` 后端权威层与前端消费契约修复 | fap-api | `codex/pr-eq-asset-sci-17-backend-contract` | 16 | integration contract | `backend_integration_contract.json`, composer/tests if visibility changes | no runtime authority shift | contract tests | medium | internal vs user-facing boundary clear | maybe |
| PR-EQ-ASSET-SCI-18 | `personalization_route` 千人千面 route matrix 内容差异化升级 | fap-api | `codex/pr-eq-asset-sci-18-route-matrix` | 05,06,07,08,09 | 60 routes | `route_matrix.json`, mirror, Composer, fixtures | no scoring | V5 contract, golden cases, fixtures | high | route copy visibly differentiated and bounded | likely |

## 8. Manifest Drafts

Do not write these entries yet. Suggested `docs/codex/pr-train.yaml` draft:

```yaml
- id: PR-EQ-ASSET-AUDIT-00
  title: "18 类 EQ 内容资产科学审计与 PR train 设计"
  repo: fap-api
  branch: codex/pr-eq-asset-audit-00-18-groups
  depends_on: []
  scope:
    include:
      - docs/audits/eq/eq_18_asset_groups_science_content_audit_2026-07-02.md
  local_checks:
    - git diff --check

- id: PR-EQ-ASSET-SCI-01
  title: "result_snapshot 科学边界与首屏钩子加厚"
  repo: fap-api
  branch: codex/pr-eq-asset-sci-01-result-snapshot
  depends_on: [PR-EQ-ASSET-AUDIT-00]
  scope: { include: ["backend/content_packs/EQ_60/v1/raw/report_assets/result_snapshot.json", "backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/report_assets/result_snapshot.json", "backend/content_packs/EQ_60/v1/compiled/**", "backend/tests/Fixtures/eq/v5/**"] }
  local_checks: &eq_asset_checks
    - cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan content:lint --pack=EQ_60 --pack-version=v1
    - cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan content:compile --pack=EQ_60 --pack-version=v1
    - cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test --filter=Eq60ContentGateTest
    - cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test --filter=Eq60V5ReportContractTest
    - git diff --check

- id: PR-EQ-ASSET-SCI-02
  title: "commercial_conversion_actions 免费转化动作与留存语义修复"
  repo: fap-api
  branch: codex/pr-eq-asset-sci-02-conversion-actions
  depends_on: [PR-EQ-ASSET-AUDIT-00]
  local_checks: *eq_asset_checks

- id: PR-EQ-ASSET-SCI-03
  title: "scientific_contract 科学边界重写"
  repo: fap-api
  branch: codex/pr-eq-asset-sci-03-scientific-contract
  depends_on: [PR-EQ-ASSET-AUDIT-00]
  local_checks: *eq_asset_checks

# Repeat same shape for PR-EQ-ASSET-SCI-04 through PR-EQ-ASSET-SCI-18,
# with the dependencies and scoped files listed in Section 7.
```

## 9. State Drafts

Do not write these entries yet. Suggested `docs/codex/pr-train-state.json` draft:

```json
{
  "PR-EQ-ASSET-AUDIT-00": {
    "status": "pending",
    "commit_sha": null,
    "pr_url": null,
    "checks": [],
    "failure_reason": null,
    "merged_at": null,
    "remote_branch_deleted": false,
    "local_cleanup_executed": false
  },
  "PR-EQ-ASSET-SCI-01": {
    "status": "pending",
    "commit_sha": null,
    "pr_url": null,
    "checks": [],
    "failure_reason": null,
    "merged_at": null,
    "remote_branch_deleted": false,
    "local_cleanup_executed": false
  },
  "PR-EQ-ASSET-SCI-02": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-03": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-04": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-05": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-06": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-07": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-08": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-09": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-10": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-11": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-12": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-13": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-14": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-15": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-16": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-17": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false },
  "PR-EQ-ASSET-SCI-18": { "status": "pending", "commit_sha": null, "pr_url": null, "checks": [], "failure_reason": null, "merged_at": null, "remote_branch_deleted": false, "local_cleanup_executed": false }
}
```

## 10. Final Recommendation

First execute `PR-EQ-ASSET-AUDIT-00` as a docs-only baseline so the 18 follow-up content PRs have a stable scope and manifest plan.

After that, do not start with the largest files. The safest first implementation sequence is:

1. `PR-EQ-ASSET-SCI-03` scientific contract
2. `PR-EQ-ASSET-SCI-04` score system
3. `PR-EQ-ASSET-SCI-12` quality runtime
4. `PR-EQ-ASSET-SCI-13` quality confidence
5. `PR-EQ-ASSET-SCI-05` core formulation

Reason: these modules define the claim boundary and quality gate. If they are not fixed first, later reality scenes, career environment, action prescriptions, personalization routes, and Agent playbooks will continue to inherit ambiguous claim strength.

Highest-risk implementation PRs:

- `PR-EQ-ASSET-SCI-05` core formulation
- `PR-EQ-ASSET-SCI-07` reality scenes / variants
- `PR-EQ-ASSET-SCI-08` career environment
- `PR-EQ-ASSET-SCI-09` action prescription
- `PR-EQ-ASSET-SCI-12` quality runtime
- `PR-EQ-ASSET-SCI-16` Agent playbooks
- `PR-EQ-ASSET-SCI-18` personalization route

Composer-sensitive PRs:

- `PR-EQ-ASSET-SCI-04` score caveat display if new fields are added
- `PR-EQ-ASSET-SCI-06` mechanism selection/quantity guard if tightened
- `PR-EQ-ASSET-SCI-07` scene variant fallback/selection if revised
- `PR-EQ-ASSET-SCI-12` quality gating matrix
- `PR-EQ-ASSET-SCI-13` low-confidence display constraints if enforced globally
- `PR-EQ-ASSET-SCI-15` route-aware depth selection
- `PR-EQ-ASSET-SCI-16` Agent playbook filtering
- `PR-EQ-ASSET-SCI-17` backend contract visibility
- `PR-EQ-ASSET-SCI-18` route matrix deterministic selection

Likely frontend follow-up PRs:

- `PR-EQ-ASSET-SCI-02` if conversion action types/order change
- `PR-EQ-ASSET-SCI-04` if score caveats need new UI
- `PR-EQ-ASSET-SCI-08` if career checklists are added
- `PR-EQ-ASSET-SCI-12` / `13` if low-confidence globally hides or downgrades sections
- `PR-EQ-ASSET-SCI-14` if psychometric evidence cards become visible
- `PR-EQ-ASSET-SCI-15` if depth modules become route-aware sections
- `PR-EQ-ASSET-SCI-18` if route headline/evidence is promoted in the hero
