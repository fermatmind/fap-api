# B5-CONTENT-1｜五维 × 五档基础维度资产 v0.1

本包用于 Big Five 千人千面内容资产工厂的第一批基础维度资产生产。

## 定位

- 资产范围：5 traits × 5 bands = 25 个 `domain_band` assets。
- 运行状态：`runtime_use = staging_only`。
- 生产状态：`production_use_allowed = false`。
- 当前状态：`ready_for_asset_review = true`，`ready_for_pilot = false`，`ready_for_runtime = false`，`ready_for_production = false`。

## 不做什么

- 不生成完整报告。
- 不生成 3125 篇长文。
- 不做 B5-B2。
- 不接 runtime。
- 不写代码。
- 不改前后端。
- 不生成 selector runtime。
- 不做 coupling。
- 不做 facet。
- 不做 canonical profiles。
- 不做 3125 matrix。

## 覆盖

- O｜开放性：very_low / low / mid / high / very_high
- C｜尽责性：very_low / low / mid / high / very_high
- E｜外向性：very_low / low / mid / high / very_high
- A｜宜人性：very_low / low / mid / high / very_high
- N｜情绪性：very_low / low / mid / high / very_high

## Canonical O59 / C32 / E20 / A55 / N68 支撑

内部 key 固定为：

`O3_C2_E2_A3_N4`

其中：

- O59：internal_band = `mid`，display_band_label = `中高`
- C32：internal_band = `low`，display_band_label = `偏低`
- E20：internal_band = `low`，display_band_label = `明显偏低`
- A55：internal_band = `mid`，display_band_label = `中位略高`
- N68：internal_band = `high`，display_band_label = `中高`

## QA 门槛

本包已经完成作者侧结构检查：

- 资产数 = 25
- 5 traits 全覆盖
- 5 bands 全覆盖
- duplicate asset_key = 0
- duplicate body_zh = 0
- empty body_zh = 0
- body_zh banned hit = 0
- 每条资产均包含 benefit / cost / common misread / action

但本包仍需要人工编辑审核、独立安全 QA、mapping QA 复核、rendered preview QA 和 repair log 全部关闭后，才能进入 pilot。

## 文件

- `big5_trait_band_assets_v0_1.json`
- `big5_trait_band_assets_v0_1.jsonl`
- `big5_trait_band_assets_main_v0_1.csv`
- `big5_trait_band_assets_coverage_v0_1.csv`
- `big5_trait_band_assets_mapping_qa_v0_1.csv`
- `big5_trait_band_assets_manifest_v0_1.json`
- `big5_trait_band_assets_qa_summary_v0_1.json`
- `big5_trait_band_assets_mapping_qa_v0_1.json`
- `big5_trait_band_assets_safety_qa_v0_1.json`
- `big5_trait_band_assets_ready_for_pilot_v0_1.json`
- `big5_trait_band_assets_repair_log_v0_1.md`
- `SHA256SUMS.txt`
