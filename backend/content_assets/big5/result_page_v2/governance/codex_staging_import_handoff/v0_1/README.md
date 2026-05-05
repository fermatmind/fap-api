# B5-CONTENT-HANDOFF-1｜Codex Staging Import Handoff v0.1

## 本包是什么

这是 Big Five 内容资产进入 Codex staging import 的交接包。它把已经完成的治理包、正文 staging 包、rendered preview QA 包、基础资产包、3125 route matrix、master catalog、325 selector candidates 和 selector QA / Policy 包整理成一个可执行的导入计划。

本包只做：

- Codex handoff
- import planning
- verification checklist
- fixture mapping plan
- rendered preview execution plan
- selector reference consistency plan
- acceptance matrix

## 本包不是什么

本包不是新正文资产，不是 B5-B2，不是 3125 报告，不是 runtime composer 方案，不是生产导入方案，不是前端/后端代码变更。

所有 package 的最终状态保持：

```json
{
  "ready_for_codex_execution": true,
  "ready_for_asset_review": true,
  "ready_for_pilot": false,
  "ready_for_runtime": false,
  "ready_for_production": false,
  "production_use_allowed": false
}
```

## 为什么可以进入 Codex staging import

V2.0 正式稿已经明确自己是“新版大五人格结果页”的正式可用稿，并要求保留两万字深度报告核心内容、加入模块化阅读、反馈、分享与保存模块。两万字稿明确以 O59 / C32 / E20 / A55 / N68 为样本，目标是形成可用于上线评审、内容拆分与后续入库的长篇报告正文。

因此，下一步不是继续生成正文，而是把当前资产包导入 staging、校验、做 rendered preview、做 selector reference consistency scan。

## 为什么仍不能 runtime / production

- B5-B2 厚正文尚未生成。
- rendered preview 仍需真实 fap-web 验证。
- 325 selector assets 仍是 staging candidates。
- Golden Cases + Selection Policy + Conflict Resolution 仍是 QA / Policy 包，不是正文资产。
- runtime composer 未接。
- CMS import pipeline 未接。
- production import policy 未批准。

## 推荐 Codex 执行顺序

1. P0-1：导入 / 校验 B5-A-lite + B5-B1 + B5-B1-Preview + B5-CONTENT-0~7 到 fap-api staging content_assets。
2. P0-2：执行 fap-web O59 rendered preview contract test。
3. P1-1：扫描 325 selector-ready assets 与 B5-CONTENT-1~6 的引用一致性。
4. P1-2：修复 Golden Cases + Selection Policy + Conflict Resolution QA policy pack。
5. P1-3：扩展 B5-H rendered preview QA。
6. P2：再考虑 B5-B2、CMS import、runtime composer、production import governance。

## Checksum policy

`manifest` 不嵌入自身 sha256，避免自引用 checksum drift。`SHA256SUMS.txt` 记录除自身以外所有包内文件的真实 sha256。

Generated at: 2026-05-05T00:47:28+00:00
