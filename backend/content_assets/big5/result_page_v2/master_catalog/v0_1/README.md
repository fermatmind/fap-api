# B5-CONTENT-7｜Big Five 内容资产总索引 / Master Asset Catalog v0.1

## 本包是什么

本包是 Big Five 内容资产系统的 master catalog / inventory / dependency graph / readiness map。它统一记录当前已完成的 Big Five 内容资产包、治理包、QA 包、selector 包和 route matrix 的定位、数量、依赖关系、QA 状态、可用范围和下一步导入优先级。

本包纳入 13 个对象：

1. B5-CONTENT-0｜内容资产结构规范
2. B5-A-lite｜Source Authority + Module Mapping Governance
3. B5-B1｜O59 Canonical Core Body Asset Import
4. B5-B1-Preview｜O59 Rendered Preview QA Pack
5. B5-B1-Preview-Codex-Handoff｜Rendered Preview Execution Brief
6. B5-CONTENT-1｜五维 × 五档基础维度资产
7. B5-CONTENT-2｜维度耦合 / 组合动力资产
8. B5-CONTENT-3｜Facet 刻面解释 / 反直觉重构资产
9. B5-CONTENT-4｜代表性画像 / 标准画像路由资产
10. B5-CONTENT-5｜场景应用 / 行动矩阵资产
11. B5-CONTENT-6｜3125 五维组合路由矩阵
12. 325 selector-ready assets v0.3 P0 full candidate
13. Golden Cases + Selection Policy + Conflict Resolution QA policy pack

## 本包不是什么

- 不是新正文。
- 不是新内容资产。
- 不是 3125 报告。
- 不是 B5-B2。
- 不是 runtime。
- 不是 CMS import。
- 不是 production import approval。
- 不是 frontend 或 backend 代码。

## Source roles

- V2.0 正式稿：module master。
- 两万字完整稿：narrative / canonical body master。
- B5-CONTENT-0：资产工厂结构规范。
- B5-A-lite：source authority 与 module-to-section governance。
- B5-B1：O59 canonical 厚正文 staging body。
- B5-CONTENT-1~6：基础资产、耦合、facet、画像路由、场景行动、3125 route matrix。
- 325 selector-ready assets：staging selector candidates，不是正文资产。
- QA / Policy 包：selector QA / policy pack，不是正文资产。

## Readiness rules

本 catalog 的最终状态是：

```json
{
  "ready_for_asset_review": true,
  "ready_for_pilot": false,
  "ready_for_runtime": false,
  "ready_for_production": false,
  "production_use_allowed": false
}
```

所有被索引包的 `runtime_use` 必须是 `staging_only` 或 `not_runtime`。所有 `production_use_allowed` 必须为 `false`。

## Import priority

### P0

- B5-A-lite
- B5-B1 O59 core body
- B5-B1 Preview QA
- B5-CONTENT-0
- B5-CONTENT-1
- B5-CONTENT-2
- B5-CONTENT-3
- B5-CONTENT-4
- B5-CONTENT-5
- B5-CONTENT-6

### P1

- 325 selector-ready assets
- Golden Cases + Selection Policy + Conflict Resolution
- B5-B1 Preview Codex Handoff

### P2

- future B5-B2 canonical profile thick body
- future rendered QA expansion
- future CMS/runtime import

## Core gaps

- B5-B2 厚正文尚未生成。
- rendered preview 仍需真实 fap-web 验证。
- 325 selector assets 仍需和 B5-CONTENT-1~6 做引用一致性检查。
- Golden Cases 仍需修 production canonical case / slot naming / rendered regression scan。
- share card / PDF / history / compare 仍需 surface-level QA。
- runtime composer 未接。
- CMS import pipeline 未接。
- production import policy 未批准。

## Checksum policy

`manifest` 不嵌入自身 sha256，避免 self-referential checksum drift。`SHA256SUMS.txt` 记录 manifest 和其余包内文件的真实 sha256，但不记录 `SHA256SUMS.txt` 自身。
