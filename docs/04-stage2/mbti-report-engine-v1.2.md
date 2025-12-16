> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: MBTI Report Engine v1.2
> Related Docs:
> - docs/04-stage2/README.md
> - docs/04-stage2/mbti-content-package-spec.md
> - docs/04-stage2/mbti-content-schema.md
> - docs/03-stage1/api-v0.2-spec.md

# MBTI 动态报告引擎 v1.2（FAP Content Engine）
对齐：连续光谱 + 分层叙事 + 卡片矩阵 + 弱特质处理 + 内容图谱承接

## 1. 一句话定义
32 型只写“静态骨架”，差异化主要靠：
AxisDynamics（轴强度卡片库） + LayerProfiles（分层标签卡片） + AssemblyPolicy（拼装策略）补齐。
前端 UI 稳定，运营可调阈值/策略，内容可版本化发布。

## 2. 输入与输出边界
### 输入（来自测评引擎）
- type_code：如 ENFJ-A
- scores：五轴百分比（EI/SN/TF/JP/AT）
- region / locale（默认 CN_MAINLAND / zh-CN）
- profile_version（内容包版本指针）

说明：测评引擎负责算分；报告引擎不关心题目细节。

### 输出（给前端）
稳定 Report JSON（前端只渲染，不做规则判断）：
- profile（TypeProfile 静态骨架）
- scores（五轴 percent + side + state）
- highlights[]（Top-2 强度轴卡片）
- borderline_note（最弱轴 <55 的“灵活/双栖提示”）
- sections.*.cards[]（按规则分发）
- layers.role/strategy/identity（分层叙事卡）
- recommended_reads[]（内容图谱推荐，M3 上线）

## 3. 六模型（v1.2 最终形态）
### 3.1 TypeProfile（32 型静态骨架）
- 目的：提供类型主叙事，不随百分比变化
- 约束：避免写死“极度/一定/总是”这类依赖强度的句子，把强弱解释交给 AxisDynamics

### 3.2 TraitScaleConfig（阈值与状态机）
- 目的：把 percent → state 变成可配置资产（运营可调）
- 输出：每条轴生成一个 state
- 推荐状态集合：
  - very_weak（≈50–54 或 <55）：触发弱特质叙事（灵活/双栖/情境化）
  - weak（55–59）
  - moderate（60–69）
  - clear（70–84）
  - strong（85–94）
  - very_strong（95–100）：更“点中但克制”的风险提示

### 3.3 AxisDynamics（轴强度卡片库）
- 目的：把“百分比不同→文案不同”收敛为可复用卡片
- 索引维度：
  - axis：EI/SN/TF/JP/AT
  - side：E/I, S/N, T/F, J/P, A/T
  - state：very_weak/weak/moderate/clear/strong/very_strong
  - card_type：explain / behavior / pitfall / action
  - region/locale + version

### 3.4 LayerProfiles（分层标签卡片）
- RoleProfile（4 类）
- StrategyProfile（4 类）
- IdentityProfile（A/T 2 类，必须优先写全）
用途：作为“第二个个性化引擎”，增强专业感与叙事颗粒度。

### 3.5 AssemblyPolicy（拼装控制台）
由三部分组成：
- SelectionPolicy：
  - top_strength_axes：Top-2 强度轴 → highlights[]
  - weakest_axis：最弱轴（若 very_weak/<55）→ borderline_note
  - identity_overlay：A/T → 语气滤镜
- SectionMapping：把卡片分发到 traits/career/growth/relationships
- ToneFilter：由 A/T + state 控制语气与风险提示力度

### 3.6 ContentGraph（内容图谱与推荐）
- 目的：承接留存/增长
- 输出：recommended_reads[]（3–6 条）
- 推荐依据：top_strength_axes + role + strategy + identity

## 4. 写作与合规约束（摘要）
- 不做诊断、不替代专业建议；避免“病理化/医疗化”表述
- 弱特质（very_weak）要强调情境切换与灵活性，不下绝对结论
- 极强态（very_strong）风险提示要“克制但具体可执行”

## 5. 与 Stage 2 里程碑对齐
- M1：TypeProfile + TraitScaleConfig（能算 state）+ Report 基础输出
- M2：AxisDynamics（先 Explain+Action）+ IdentityProfile 完整 + highlights[]
- M3：Role/Strategy + borderline_note + ContentGraph 推荐 + 运营实验迭代阈值/策略