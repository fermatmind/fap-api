# B5-CONTENT-0 v0.1.1

Big Five 千人千面内容资产工厂的基础规范包。

本包只定义内容资产格式、组合 key、section 标准、安全边界、批次计划和 QA 模板。  
本包不生成正文，不生成 3125 报告，不写代码，不接 runtime，不做 B5-B1。

## What changed from v0.1

1. Added career-style QA template files:
   - `big5_qa_summary_template_v0_1.json`
   - `big5_mapping_qa_template_v0_1.json`
   - `big5_repair_log_template_v0_1.md`
   - `big5_ready_for_pilot_template_v0_1.json`

2. Renamed internal band threshold fields:
   - use `score_range_0_100`
   - use `internal_band_threshold`
   - avoid legacy percentile-threshold naming for internal bands
   - preserve `display_band_label`

3. Kept the canonical O59/C32/E20/A55/N68 key as:
   - `O3_C2_E2_A3_N4`

4. Deferred source authority, runtime decision, and current anti-target render terms to B5-A-lite.

5. All schema example assets are marked:
   - `copy_role = schema_example`
   - `production_use_allowed = false`
   - `runtime_use = not_runtime`
   - `render_surface = []`

## Files

### JSON

- `big5_content_asset_schema_v0_1.json`
- `big5_3125_combination_key_rules_v0_1.json`
- `big5_section_content_standard_v0_1.json`
- `big5_safety_boundary_policy_v0_1.json`
- `big5_generation_batch_plan_v0_1.json`
- `big5_v2_module_to_section_mapping_v0_1.json`
- `big5_qa_summary_template_v0_1.json`
- `big5_mapping_qa_template_v0_1.json`
- `big5_ready_for_pilot_template_v0_1.json`
- `manifest.json`

### CSV

- `main_asset_table_columns_v0_1.csv`
- `module_to_section_mapping_v0_1.csv`
- `section_content_standards_v0_1.csv`
- `qa_criteria_v0_1.csv`

### Markdown

- `README.md`
- `big5_repair_log_template_v0_1.md`
- `SHA256SUMS.txt`

## Ready-for-pilot hard gate

`ready_for_pilot=true` requires all of the following:

- coverage QA passed
- safety QA passed
- editorial QA passed
- mapping QA passed
- rendered preview QA passed
- repair log all closed
- no P0 blockers

Otherwise `ready_for_pilot=false`.

`production_use_allowed` defaults to `false`.

## Deferred

The following are not part of B5-CONTENT-0 v0.1.1 and should be handled by B5-A-lite:

- source authority map
- runtime layer decision
- current anti-target rendered terms
- production/live owner declaration
