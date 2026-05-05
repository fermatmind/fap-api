# B5-CONTENT-5｜场景应用 / 行动矩阵资产 v0.1

本包生成 Big Five 8 个 canonical profiles 在 5 个真实生活场景中的 scenario action staging assets。

## Scope

- 8 canonical profiles × 5 scenarios × 4 asset roles = 160 assets
- asset_type = `scenario_action`
- asset_layer = `L6_scenario_action`
- runtime_use = `staging_only`
- production_use_allowed = `false`

## Not in scope

- 不生成完整报告
- 不生成 3125 篇长文
- 不做 B5-B2 厚正文
- 不做 3125 matrix
- 不接 runtime
- 不写代码
- 不改前后端

## Scenarios

- workplace
- relationships
- stress_recovery
- personal_growth
- collaboration

## QA status

- asset_count: 160
- duplicate asset_key: 0
- duplicate body_zh: 0
- empty body_zh: 0
- banned_user_visible_body_hits: 0
- ready_for_asset_review: true
- ready_for_pilot: false
- ready_for_runtime: false
- ready_for_production: false

## Safety posture

All assets keep continuous_trait_language, non_diagnostic, non_hiring, no_hard_typing, no_accuracy_claim, profile_label_assistive_only, and low_risk_action_only tags. Collaboration assets are share-safe and do not expose sensitive scores.

## v0.1.1 checksum policy

This v0.1.1 package only revises manifest / checksum handling. Asset bodies, asset count, profile coverage, scenario coverage, role coverage, QA summary semantics, runtime flags, and production flags are unchanged from v0.1.

Manifest self-hash is not embedded to avoid self-referential checksum drift. `SHA256SUMS.txt` records the real sha256 for all package files except `SHA256SUMS.txt` itself, including `big5_scenario_action_assets_manifest_v0_1.json`.
