# B5-B1-Preview-Codex-Handoff｜O59 Rendered Preview Execution Brief v0.1.1

本文件包把 **B5-B1 O59 rendered preview QA pack** 转成 Codex / `fap-web` 可以执行的前端 rendered preview 合同测试说明。

## 本包是什么

- Codex handoff / execution brief。
- 面向 `fap-web` 的合同测试需求说明。
- 用于验证 `fap-api` PR #1129 已合并 fixture：
  `backend/tests/Fixtures/big5_result_page_v2/canonical_o59_core_body_preview.payload.json`。
- 用于保证 O59 canonical payload 被前端渲染后，不出现 compact、英文 heading、metadata leak、placeholder leak。

## 本包不是什么

- 不写代码。
- 不改前端。
- 不改后端。
- 不接 runtime。
- 不生成新正文。
- 不修改 B5-B1 core body。
- 不修改 rendered QA pack。
- 不表示 runtime-ready 或 production-ready。

## Canonical profile

- Scores: `O59 / C32 / E20 / A55 / N68`
- Internal key: `O3_C2_E2_A3_N4`
- Profile label: `敏锐的独立思考者`
- Axis: `高敏感 × 中高开放 × 克制进入`

## Required rendered surfaces

- `result_page_desktop`
- `result_page_mobile`
- `pdf` if supported by existing harness
- `share_card` if supported by existing harness
- `history` if supported by existing harness
- `compare` if supported by existing harness

如果 PDF / share / history / compare 当前没有可用 test harness：

- 不要新建复杂 runtime。
- 标记为 `pending_surface`。
- 不能声称 rendered QA passed。

## v0.1.1 小修订

本修订仅修正 handoff 包结构和测试说明，不进入 Codex execute：

1. `manifest.json` 文件列表按真实 zip 内容去重，`file_count` 等于实际文件数。
2. visible text matrix 中的 `surfaces` 数组已去重，移除重复 `pdf`。
3. `all` 的检查被限定为用户可见的孤立 placeholder/debug token；不得用 raw substring 扫描误伤 `callout`、`allowlisted`、schema kind 或 internal code string。
4. Codex 执行后必须输出 `tested_surfaces`、`pending_surfaces`、`passed_surfaces`、`failed_surfaces`。PDF / share_card / history / compare 如果没有现成 test harness，只能进入 `pending_surfaces`，不能声称 passed。

## Required Codex execution output

```json
{
  "tested_surfaces": [],
  "pending_surfaces": [],
  "passed_surfaces": [],
  "failed_surfaces": []
}
```

每个 declared surface 必须且只能落入 `pending_surfaces`、`passed_surfaces` 或 `failed_surfaces` 之一。`pending_surfaces` 不计入 passed。

## Contextual rule for `all`

- 禁止：用户可见文本中孤立显示的 placeholder/debug token `all`。
- 允许：`callout`、`allowlisted`、schema kind、internal code string、非用户可见 JSON / TS / fixture / QA metadata。
- 实现要求：不要对 raw text 做 substring `includes("all")` 断言。

## Final state

```json
{
  "ready_for_codex_execution": true,
  "ready_for_runtime": false,
  "ready_for_production": false,
  "production_use_allowed": false
}
```
