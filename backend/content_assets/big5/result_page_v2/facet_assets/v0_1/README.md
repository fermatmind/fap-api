# B5-CONTENT-3｜Facet 刻面解释 / 反直觉重构资产 v0.1

本包生成 Big Five 30 个 facet 的解释性资产，重点避免 `facet_details` 退化为 `N1 百分位 / all / 纯数字列表`。

## Scope

- 30 facets × 3 asset roles = 90 assets
- Roles: `facet_glossary`, `facet_high_reframe`, `facet_low_reframe`
- Module: `module_05_facet_reframe`
- Section: `facet_details`
- Runtime: `staging_only`
- Production use: `false`

## Not in scope

- 不生成完整报告
- 不生成 3125 长文
- 不做 B5-B2
- 不做 canonical profiles
- 不做 scenario action
- 不接 runtime
- 不写代码
- 不改前后端

## Canonical O59 Support

本包覆盖 O59 / C32 / E20 / A55 / N68 相关的反直觉发现：

- 不是没尽责
- 不是社交差
- 不是玻璃心

并覆盖 Top Facet 解释：

- 压力信号感知
- 条理能力
- 进入门槛
- 自我效能
- 现实过滤下的开放

## Safety

每条 asset 均保留：

- `continuous_trait_language`
- `non_diagnostic`
- `non_hiring`
- `no_hard_typing`
- `no_accuracy_claim`
- `inference_only`

Facet 内容只能作为解释性推断，不能作为独立测量结论。Percentile / 分数只能出现在 full facet directory 或 metric row，不能作为 `facet_details` 主体解释。

## Status

```json
{
  "ready_for_asset_review": true,
  "ready_for_pilot": false,
  "ready_for_runtime": false,
  "ready_for_production": false
}
```
