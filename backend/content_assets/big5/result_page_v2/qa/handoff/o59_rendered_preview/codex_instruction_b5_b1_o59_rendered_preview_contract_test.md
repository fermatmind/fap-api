# Codex 指令：B5-B1 O59 Rendered Preview Contract Test v0.1.1

模式：execute
风险级别：L2

## Repo

`fap-web`

## Goal

使用 `fap-api` PR #1129 已合并的 fixture：

`backend/tests/Fixtures/big5_result_page_v2/canonical_o59_core_body_preview.payload.json`

在 `fap-web` 中建立 rendered preview contract test，验证 O59 canonical payload 被前端渲染后不出现 compact、英文 heading、metadata leak、placeholder leak。

## Known context

Canonical profile:

- O = 59
- C = 32
- E = 20
- A = 55
- N = 68
- internal key = `O3_C2_E2_A3_N4`
- profile label = `敏锐的独立思考者`
- axis = `高敏感 × 中高开放 × 克制进入`

## Hard invariants

- 不改 backend。
- 不接 runtime。
- 不生成新正文。
- 不修改 B5-B1 core body。
- 不修改 rendered QA pack。
- 不发明 frontend fallback 正文。
- 不声称 PDF / share / history / compare passed unless existing harness actually tests them。
- 如果 fixture 缺失，不要合成；停止并报告 missing fixture。

## In scope

- 在 `fap-web` 添加或更新 contract test。
- 如有需要，将 PR #1129 fixture 复制到 `fap-web/tests/fixtures/big5/result_page_v2/` 作为 test fixture。
- 验证 result page desktop / mobile rendered output。
- PDF / share_card / history / compare 只有在已有 harness 支持时测试；否则标记 pending_surface。

## Must check

### Surfaces

- result page desktop
- result page mobile
- PDF surface, if supported by existing test harness
- share card, if supported by existing test harness
- history / compare safe summary, if existing test harness supports

If PDF/share/history/compare currently have no available test harness:

- Do not create complex runtime.
- Mark them as `pending_surface`.
- Do not claim rendered QA passed.

### Must render visible terms

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

### Must not render visible terms

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

- `all` 只禁止作为 placeholder / debug leakage，不误伤 `callout` / schema kind / internal code string。
- `N1 百分位` 允许在 full facet directory / metric row，不允许作为 `facet_details` 主体解释。

## Section render contract

- `hero_summary` has profile label, axis, five-domain snapshot, strengths, risks, 48h action.
- `domains_overview` has five-domain reading instructions and high/low is not good/bad.
- `domain_deep_dive` has five-trait explanations.
- `facet_details` does not degrade into percentile-only list and must have reframe narrative.
- `core_portrait` has core coupling and dominant tension.
- `norms_comparison` is Chinese relative reference and does not show English heading.
- `action_plan` covers workplace / relationships / stress_recovery / personal_growth.
- `methodology_and_access` has non-type, non-diagnosis, non-hiring, facet, privacy, and retest boundary.


## Required output arrays

Codex 最终报告必须输出：

```json
{
  "tested_surfaces": [],
  "pending_surfaces": [],
  "passed_surfaces": [],
  "failed_surfaces": []
}
```

规则：

- `result_page_desktop` 和 `result_page_mobile` 是 required surfaces，必须被实际测试并进入 `passed_surfaces` 或 `failed_surfaces`。
- `pdf`、`share_card`、`history`、`compare` 只有在现有 test harness 支持时才可进入 `tested_surfaces` / `passed_surfaces`。
- 如果没有现成 harness，不要新建复杂 runtime，不要声称 passed；必须放入 `pending_surfaces` 并说明原因。
- 每个 declared surface 必须且只能出现在 `pending_surfaces`、`passed_surfaces` 或 `failed_surfaces` 之一。

## Strict contextual rule for `all`

不要全局按 raw substring `"all"` 断言。

只禁止用户可见文本中孤立显示的 placeholder/debug token `all`，例如 facet row 单独渲染出一行 `all`。

不得误伤：

- `callout`
- `allowlisted`
- schema kind
- internal code string
- 非用户可见 JSON / TS / fixture / QA metadata

## Acceptance

- `fap-web` contract test can read O59 fixture.
- Valid payload enters Big5 V2 rendered preview path.
- UI visible text contains must-render key terms.
- UI visible text does not contain P0 must-not-render terms.
- Invalid / missing payload still safely falls back to old path and does not invent body copy.
- No runtime is added.
- Backend is not modified.
- No new body copy is generated.
- Pending surfaces are explicitly reported and do not count as passed.

## Output contract

Return:

1. Changed files
2. Fixture import summary
3. Contract test summary
4. Surface execution summary
5. Pending surfaces, if any
6. Validation commands and results
7. Boundary check
8. Whether rendered preview execution is pass / partial / blocked

## Done when

The PR proves O59 canonical fixture can be rendered in `fap-web` without compact regression, English heading regression, metadata leakage, or placeholder leakage on supported surfaces, while unsupported surfaces are explicitly marked pending.
