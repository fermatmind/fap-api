# B5-B1-Preview｜O59 Canonical Rendered Preview QA Pack v0.1

本包用于 O59 / C32 / E20 / A55 / N68「敏锐的独立思考者」B5-B1 core body staging assets 的真实渲染 QA。

## 本包是什么

- rendered preview QA 标准包
- 用于后续 Codex / fap-web 检查真实页面、PDF、分享卡、history、compare 的可见文本和布局风险
- 只定义 expected / forbidden / section contract / ready gate

## 本包不是什么

- 不是正文资产
- 不是 B5-B2
- 不是 selector runtime
- 不是前端实现
- 不是 production import

## Profile

- scores: O59 / C32 / E20 / A55 / N68
- internal_combination_key: O3_C2_E2_A3_N4
- profile_label: 敏锐的独立思考者
- axis: 高敏感 × 中高开放 × 克制进入

## Required surfaces

- result_page_desktop
- result_page_mobile
- pdf
- share_card
- history
- compare

## Gate

`ready_for_rendered_preview_execution = true`

仍然保持：

- `ready_for_runtime = false`
- `ready_for_production = false`
- `production_use_allowed = false`

Rendered preview 执行后，必须满足：desktop passed、mobile passed、pdf passed、share_card passed、history / compare no metadata leak、no P0 banned terms、repair log all P0 closed。

## 注意

`all` 只作为 placeholder / debug leakage 禁止，不要误伤 schema/kind 中的 `callout`。

`N1 百分位` 允许出现在 full facet directory / metric row，但不能作为 `facet_details` 主体解释。
