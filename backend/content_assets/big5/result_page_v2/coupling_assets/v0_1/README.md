# B5-CONTENT-2｜维度耦合 / 组合动力资产 v0.1

本包生成 Big Five 维度之间的组合动力 staging assets。

## Scope

- 12 个 coupling groups
- 每组 5 个 asset roles
- 总计 60 条 assets
- `asset_type = coupling`
- `asset_layer = L4_coupling`
- `module_key = module_04_coupling`
- `section_key = core_portrait`
- `runtime_use = staging_only`
- `production_use_allowed = false`

## Not in scope

- 不生成完整报告
- 不生成 3125 篇长文
- 不做 B5-B2
- 不做 facet
- 不做 canonical profiles
- 不做 scenario action
- 不接 runtime
- 不写代码
- 不改前后端

## Included coupling groups

- `n_high_x_o_mid_high`｜高情绪 × 中高开放
- `n_high_x_e_low`｜高情绪 × 低外向
- `e_low_x_c_low`｜低外向 × 低尽责
- `c_low_x_n_high`｜低尽责 × 高情绪
- `a_mid_x_n_high`｜中位宜人 × 高情绪
- `o_high_x_c_low`｜高开放 × 低尽责
- `c_high_x_n_high`｜高尽责 × 高情绪
- `o_low_x_c_high`｜低开放 × 高尽责
- `e_high_x_n_high`｜高外向 × 高情绪
- `e_high_x_a_high`｜高外向 × 高宜人
- `a_low_x_n_high`｜低宜人 × 高情绪
- `o_high_x_a_low`｜高开放 × 低宜人

## Asset roles

- `coupling_core_explanation`
- `coupling_benefit_cost`
- `coupling_common_misread`
- `coupling_action_strategy`
- `coupling_scenario_bridge`

## O59 canonical support

本包覆盖 O59 / C32 / E20 / A55 / N68「敏锐的独立思考者」需要的 5 个 coupling：

- `n_high_x_o_mid_high`
- `n_high_x_e_low`
- `e_low_x_c_low`
- `c_low_x_n_high`
- `a_mid_x_n_high`

## QA status

- asset_count: 60
- duplicate_asset_keys: 0
- duplicate_body_zh: 0
- empty_body_zh: 0
- banned_user_visible_body_hits: 0
- ready_for_asset_review: true
- ready_for_pilot: false
- ready_for_runtime: false
- ready_for_production: false

## Readiness

This package is suitable for asset review only. It is not runtime-ready and must not be used as production content before independent editorial QA, safety QA, mapping QA, rendered preview QA, and repair log closure.
