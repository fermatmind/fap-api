# Codex 指令：Selector Reference Consistency Scan

模式：scan
风险级别：L1

## Goal

扫描 325 selector-ready assets 与 B5-CONTENT-1~6 的引用一致性，输出缺失引用、命名漂移、slot/module drift、O59 canonical case gap 和 rendered regression scan gap。

本任务不修资产、不接 runtime、不生成正文。

## Known context

325 selector-ready assets 是 staging selector candidates，不是 production-ready。Golden Cases + Selection Policy + Conflict Resolution 是 selector QA / policy pack，不是正文资产。

## Hard invariants

- 不修改代码。
- 不修改内容资产。
- 不接 runtime。
- 不把 325 assets 当 production-ready。
- 不把 QA / Policy 包当正文。
- 不生成新正文。
- 不修 Golden Cases，除非用户另开 execute 任务。

## Must scan

- `selector_ready_assets/v0_3_p0_full/assets.json`
- B5-CONTENT-1 trait-band assets
- B5-CONTENT-2 coupling assets
- B5-CONTENT-3 facet assets
- B5-CONTENT-4 canonical profile assets
- B5-CONTENT-5 scenario action assets
- B5-CONTENT-6 route matrix
- Golden Cases + Selection Policy + Conflict Resolution QA policy pack

## Must report

- Missing trait-band references
- Missing coupling references
- Missing facet route references
- Missing canonical profile references
- Missing scenario references
- Slot naming drift
- Module naming drift
- Whether O59 production canonical golden case exists
- Whether rendered regression banned terms cover compact/English/facet list regressions
- Whether any selector metadata could leak to user-visible payload

## Acceptance

- Scan outputs a detailed reference consistency report.
- No files changed.
- No runtime changes.
- Clear P0/P1/P2 severity assignments.
- Next repair task can be scoped from the report.
