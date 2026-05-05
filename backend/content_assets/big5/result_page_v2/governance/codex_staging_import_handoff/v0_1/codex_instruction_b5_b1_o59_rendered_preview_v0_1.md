# Codex 指令：B5-B1 O59 Rendered Preview Contract Test

模式：execute
风险级别：L1

## Goal

在 `fap-web` 中建立 O59 canonical rendered preview contract test，使用 `fap-api` staging fixture：

`backend/tests/Fixtures/big5_result_page_v2/canonical_o59_core_body_preview.payload.json`

验证 O59 canonical payload 被前端渲染后，不出现 compact、英文 heading、metadata leak、placeholder leak。

## Known context

O59 canonical profile:

- O59 / C32 / E20 / A55 / N68
- internal key: `O3_C2_E2_A3_N4`
- profile label: `敏锐的独立思考者`
- axis: `高敏感 × 中高开放 × 克制进入`

## Hard invariants

- 不修改 backend。
- 不接 runtime。
- 不生成新正文。
- 不修改 B5-B1 core body。
- 不改 frontend runtime。
- 不发明 fallback bridge。
- 不让 invalid / missing payload 生成新正文。

## In scope

- Import or copy O59 fixture into `fap-web/tests/fixtures/big5/result_page_v2/`.
- Add contract test using existing render/test utilities.
- Test desktop and mobile result page if supported.
- If PDF / share / history / compare harness is not available, mark them as `pending_surfaces`.

## Must render

- 敏锐的独立思考者
- O59 / C32 / E20 / A55 / N68
- 高敏感 × 中高开放 × 克制进入
- Big Five 描述的是连续人格特质，不是固定人格类型
- 不用于医学诊断
- 不用于心理治疗
- 不用于招聘筛选
- 画像名只是辅助理解标签
- 不是没尽责
- 不是社交差
- 不是玻璃心
- 工作
- 关系
- 压力
- 个人成长
- 30 / 60 / 90 天路径
- 方法与边界说明

## Must not render

- A compact overview
- Five-domain distribution with percentile-oriented context
- Focused read on domain-level strengths
- Facet-level signals arranged for quick interpretation
- Norms Comparison
- Methodology and Access
- N1 百分位 作为主体解释
- all 作为 placeholder / debug leakage
- 优先关注成长面向
- O6, C5, E6
- [object Object]
- deferred_to_future
- policy_not_shipped
- frontend_fallback
- internal_metadata
- selector_basis
- selection_guidance
- editor_notes
- qa_notes
- source_reference
- import_policy
- runtime_use
- production_use_allowed

## Contextual rules

- `all` 只禁止用户可见的孤立 placeholder/debug token，不误伤 `callout` / schema kind / internal code string。
- `N1 百分位` 允许在 full facet directory / metric row，不允许作为 `facet_details` 主体解释。

## Acceptance

- fap-web contract test can read O59 fixture.
- Valid payload enters Big5 V2 rendered preview path.
- Must-render terms appear.
- P0 must-not-render terms do not appear.
- Invalid/missing payload safely falls back to old path without inventing new body copy.
- Output includes `tested_surfaces`, `pending_surfaces`, `passed_surfaces`, `failed_surfaces`.
- Unsupported PDF/share/history/compare surfaces are pending, not passed.

## Output contract

Return:

- changed files
- test file names
- fixture path
- test commands
- tested_surfaces / pending_surfaces / passed_surfaces / failed_surfaces
- blocker list
- no-runtime-change confirmation
