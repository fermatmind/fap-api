# B5-A-lite｜Source Authority + Module Mapping Governance v0.1

## 本包是什么

本包是 Big Five V2 内容资产治理包，用于在进入 B5-B1 正文入库前，固定内容来源身份、Module 00–10 到当前 8-section skeleton 的映射、runtime layer 决策、anti-target render terms，以及 O59 canonical body import 的 readiness checklist。

本包基于：

- `B5-CONTENT-0 v0.1.1`：内容资产工厂基础规范；
- `FermatMind Big Five 新版结果页正式上线 V2.0`：module master；
- `FermatMind Big Five 完整结果页全文（最终成稿）`：narrative / canonical body master；
- 当前 Big Five 8-section skeleton；
- 325 selector-ready assets；
- Golden Cases + Selection Policy + Conflict Resolution；
- 当前 compact 线上页 anti-target evidence。

## 本包不是什么

- 不是正文资产包；
- 不是 B5-B1；
- 不是 3125 报告生成包；
- 不是 runtime wiring；
- 不是 frontend / backend 改造方案；
- 不是 production import；
- 不把 325 selector-ready assets 当 production；
- 不把 QA / Policy 包当正文资产。

## 为什么要先做 B5-A-lite

Big Five V2 当前同时存在多种材料：V2.0 正式稿、两万字完整稿、当前 8-section skeleton、325 selector-ready assets、QA / Policy 包、frontend fallback 与 compact 线上页。若不先固定 source authority 和 module mapping，后续 B5-B1 容易把正文源、selector 候选、QA 包和 runtime skeleton 混在一起。

## 为什么 B5-B1 只能先做 O59 canonical

两万字完整稿的样本是 O59 / C32 / E20 / A55 / N68，核心画像是“敏锐的独立思考者”，主轴是“高敏感 × 中高开放 × 克制进入”。因此下一步 B5-B1 只能先做这个 canonical profile 的 8-section core body staging import，不得泛化给全部用户。

## 为什么 325 assets 不能接 runtime

325 selector-ready assets 是 selector candidates / staging assets。它们的 runtime status 必须保持 `staging_only`，`runtime_ready=false`，`production_use_allowed=false`。它们可以作为后续 selector QA 和覆盖分析材料，但不能替代 B5-B1 厚正文，也不能直接接 runtime。

## 为什么 QA / Policy 包不是正文资产

Golden Cases + Selection Policy + Conflict Resolution 的角色是 selector QA / policy pack。它用于测试选块、冲突、降级、安全与渲染泄漏，不生成新 Big Five prose assets，不接 runtime，不提供 frontend fallback。

## 下一步如何进入 B5-B1

B5-B1 的导入目标是：

`backend/content_assets/big5/result_page_v2/core_body/v0_1/`

B5-B1 必须只处理 O59 canonical profile，并导入 8 section：

- hero_summary
- domains_overview
- domain_deep_dive
- facet_details
- core_portrait
- norms_comparison
- action_plan
- methodology_and_access

B5-B1 仍然必须保持 staging / preview 状态，直到 coverage QA、safety QA、editorial QA、mapping QA、rendered preview QA 全部通过，repair log 全部 closed，且无 P0 blockers。

## Final state

```json
{
  "ready_for_b5_b1": true,
  "ready_for_runtime": false,
  "ready_for_production": false
}
```

Generated at: 2026-05-04T09:33:36.564735Z
