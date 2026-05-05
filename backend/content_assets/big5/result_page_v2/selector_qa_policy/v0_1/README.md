# Big Five Result Page V2 Selector QA & Policy v0.1

本包用于 Big Five V2 325 个 selector-ready assets 的选择逻辑验证。

## Files

- `big5_result_page_v2_selector_qa_policy_v0_1_golden_cases.json`: 30 个 Golden Cases。
- `big5_result_page_v2_selector_qa_policy_v0_1_selection_policy.json`: Selection Policy，定义模块选块、阅读模式、scope 降级、slot 限制。
- `big5_result_page_v2_selector_qa_policy_v0_1_conflict_resolution.json`: Conflict Resolution，定义互斥、冲突、降级、安全与渲染泄漏扫描规则。
- `big5_result_page_v2_selector_qa_policy_v0_1_manifest.json`: manifest 与 hash。

## Scope

- Runtime use: `staging_only`
- No runtime wiring.
- No frontend fallback.
- No new Big Five prose assets.
- No scoring / DB / controller / route changes implied.

## Golden Case Groups

- 清晰主轴型：6
- 高张力 / 混合型：7
- 均衡 / 分散型：3
- Facet 反直觉型：5
- 安全降级型：5
- 场景应用型：4

## Next Codex Task

`P0-SELECTOR-QA-AND-POLICY-IMPORT`

导入这些文件到 backend staging，并新增 tests：
1. 30 个 golden cases 可 parse；
2. selection policy 和 conflict resolution 可 parse；
3. golden cases 覆盖 P0 风险；
4. policy 不接 runtime；
5. conflict rules 禁止 fixed type / user_confirmed_type / raw score / [object Object] / deferred placeholder。


## Codex P1-2 staging import normalization

This repo-owned staging import keeps the policy pack advisory-only and not runtime-connected.

Normalization applied during import:

- Added `golden_group` to every golden case using the source bundle group definitions.
- Added `golden_case_31_o59_canonical_preview` for the repo-owned O59 / C32 / E20 / A55 / N68 canonical preview case.
- Normalized facet `include_slots` from uppercase facet ids to selector-ready slot keys that use lowercase facet ids.
- Expanded rendered banned terms for public-surface leakage checks.
- Added a slot resolution report for `include_slots` and `include_registry_keys` against `selector_ready_assets/v0_3_p0_full`.

Safety boundaries:

- Runtime use remains `staging_only` or `not_runtime`.
- `production_use_allowed` remains `false`.
- `ready_for_pilot`, `ready_for_runtime`, and `ready_for_production` remain `false`.
- This package is a QA / policy pack, not a prose body asset and not a production gate.
