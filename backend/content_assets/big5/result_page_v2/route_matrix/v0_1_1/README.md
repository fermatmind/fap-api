# B5-CONTENT-6｜3125 五维组合路由矩阵 v0.1.1

本包是 B5-CONTENT-6 v0.1 的 semantic route consistency 小修订。

## 修订范围

只修复 low / medium confidence rows 的用户可见 route semantics：

- 新增 `canonical_profile_usage`。
- 新增 `profile_label_public_allowed`。
- 修正 `primary_axis_zh`、`one_sentence_route_zh`、`share_safe_summary_zh`、`low_resonance_response_zh`、`partial_resonance_response_zh`，使其与实际 `domain_bands` 一致。
- low confidence rows 使用 `generic_band_route`，不把 nearest canonical profile label 或 canonical axis 作为用户可见标签。
- medium confidence rows 使用 `nearest_route_only`，可以作为相近画像家族的内部路线索引，但用户可见主轴仍按实际 band 生成。
- high confidence rows 使用 `direct_profile_route`；O59 canonical row 保持 `敏锐的独立思考者` 与 `高敏感 × 中高开放 × 克制进入`。

## 不变范围

- 不生成 3125 篇报告。
- 不生成正文 `body_zh`。
- 不做 B5-B2。
- 不接 runtime。
- 不写代码。
- 不改前后端。
- route row 总数仍为 3125。
- 每个 O shard 仍为 625。
- `runtime_use` 仍全部为 `staging_only`。
- `production_use_allowed` 仍全部为 `false`。
- `ready_for_pilot` 仍全部为 `false`。

## O59 canonical row

`O3_C2_E2_A3_N4` 仍保持：

- `nearest_canonical_profile_key = sensitive_independent_thinker`
- `nearest_canonical_profile_label_zh = 敏锐的独立思考者`
- `profile_match_confidence = high`
- `canonical_profile_usage = direct_profile_route`
- `profile_label_public_allowed = true`
- `primary_axis_zh = 高敏感 × 中高开放 × 克制进入`

## QA status

```json
{
  "total_rows": 3125,
  "semantic_axis_band_mismatch_count": 0,
  "low_confidence_canonical_axis_leak_count": 0,
  "low_confidence_public_profile_label_count": 0,
  "body_zh_field_count": 0,
  "production_use_allowed_true_count": 0,
  "runtime_use_not_staging_only_count": 0,
  "ready_for_pilot_true_count": 0
}
```

## Checksum policy

`manifest.json` does not embed its own sha256. `SHA256SUMS.txt` records real sha256 values for all package files except `SHA256SUMS.txt` itself, avoiding self-referential checksum drift.
