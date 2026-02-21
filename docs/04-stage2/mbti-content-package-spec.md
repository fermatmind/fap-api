> Status: Draft
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: MBTI Content Package CN v0.3
> Related Docs:
> - docs/04-stage2/mbti-report-engine-v1.2.md
> - docs/04-stage2/mbti-content-schema.md
> - docs/03-stage1/content-release-checklist.md

# MBTI 内容包规范（Content Package Spec）
用于：版本化、灰度、回滚、跨端复用（小程序/官网/后台）

## 1. 命名与版本口径（与 Stage 1 一致）
- content_package：MBTI-CN-v0.3
- profile_version：mbti32-v2.5（示例）
- axis_dynamics_version：axis-v0.3-r1
- layer_profiles_version：layers-v0.3-r1
- assembly_policy_version：policy-v0.3-r1
- content_graph_version：graph-v0.3-r1

原则：任何面向用户的内容变更，必须体现在版本号上；支持灰度与回滚。

## 2. 目录结构（推荐）
content_packages/MBTI-CN-v0.3/
  type_profiles/
    ENFJ-A.json
    ENFJ-T.json
    ...
  trait_scale_config/
    thresholds.json
  axis_dynamics/
    axis-v0.3-r1/
      EI/
      SN/
      TF/
      JP/
      AT/
  layer_profiles/
    layers-v0.3-r1/
      role_profiles.json
      strategy_profiles.json
      identity_profiles.json
  assembly_policy/
    policy-v0.3-r1.json
  content_graph/
    graph-v0.3-r1.json
  release_notes/
    2025-xx-xx.md

## 3. 资产类型与职责
### TypeProfile（32 条）
- 只写“骨架”：intro/traits/career/growth/relationships/disclaimers
- 不写死强弱结论

### TraitScaleConfig（阈值/状态机）
- 定义各轴 state 分段
- 可按 region/locale 覆盖

### AxisDynamics（卡片库）
- explain / behavior / pitfall / action
- 覆盖 5 轴 × 2 方向 × 6 状态（最低上线量：Explain + Action 优先）

### LayerProfiles（分层标签）
- Role/Strategy/Identity 三类卡片
- Identity（A/T）必须优先完整

### AssemblyPolicy（策略）
- SelectionPolicy + SectionMapping + ToneFilter
- 允许 type_code override（只给 Top 类型优先做）

### ContentGraph（推荐）
- recommended_reads[] 节点与规则
- M3 上线

## 4. 发布流程（与 Stage 1 Checklist 对齐）
拟稿 → 结构校验 → 灰度标记 → 发布 → 回滚规则
- 灰度：按 channel / percent / 流量比例
- 回滚：按版本指针一键切回上一版（不改前端）

## 5. 最低可上线内容量（避免被工作量拖死）
- TypeProfile：32 条（短骨架优先）
- IdentityProfile：2 条（必须完整）
- AxisDynamics：先覆盖 Explain + Action（全轴全状态）
- Role/Strategy：4+4 条可在 M3 完成