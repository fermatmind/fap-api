# Big Five Low Quality Content Assets v0.3 Review

## Editorial summary

This is the third Low Quality candidate package for Big Five Result Page V2. It rewrites the v0.2 package to reduce repeated structure and improve the logic of degraded-state guidance.

The package focuses only on low-confidence / degraded answer quality states. It does not create a final result page payload, does not write frontend copy, does not connect CMS, SEO, runtime, rollout, or production.

## Asset count

- Content assets: 14
- Scope: `low_quality`
- Runtime use: `staging_only`
- Production use allowed: `false`
- Ready for pilot/runtime/production: `false / false / false`

## Covered low-quality states

- `lower_confidence_explanation` — 这次先看轮廓
- `rushed_answer_context` — 节奏过快时先降速
- `interrupted_context` — 被打断时先保留入口
- `inconsistent_pattern_context` — 前后信号不稳时
- `missing_or_sparse_information` — 信息不完整时
- `unclear_self_observation` — 自我观察还没成形时
- `safe_to_show_when_low_quality` — 低置信时可以看什么
- `should_soften_or_hide_when_low_quality` — 哪些内容要放轻
- `non_blaming_boundary` — 质量提示不是责备
- `retest_recommended_context` — 什么时候值得重答
- `share_boundary_low_quality` — 低置信结果不宜外传
- `compare_boundary_low_quality` — 低置信结果不宜做对比重点
- `history_boundary_low_quality` — 低置信报告怎么保存
- `next_step_low_quality` — 接下来只做一小步

## What changed from v0.2

- Reduced repeated “explain / boundary / next step” phrasing.
- Separated rushed, interrupted, inconsistent, incomplete, unclear self-observation, share, compare, history, and next-step cases.
- Added clearer show / soften / hide logic.
- Avoided blaming language.
- Avoided high-confidence interpretation from weak material.
- Kept public text within 180–320 Chinese-character target range.
- Preserved staging-only and production-disabled flags.

## Risk boundaries

The package avoids:
- blame or moral judgment
- claims that the user lied
- strong interpretation from weak material
- raw score, public ranking, private identifiers
- clinical, treatment, hiring, income, success, or relationship outcome claims
- internal implementation terms in user-visible text

## QA summary

```json
{
  "schema_version": "big5_low_quality_revised.qa_scan.v0_3",
  "scan_scope": "public_visible_fields_only",
  "content_asset_count": 14,
  "covered_low_quality_states": [
    "lower_confidence_explanation",
    "rushed_answer_context",
    "interrupted_context",
    "inconsistent_pattern_context",
    "missing_or_sparse_information",
    "unclear_self_observation",
    "safe_to_show_when_low_quality",
    "should_soften_or_hide_when_low_quality",
    "non_blaming_boundary",
    "retest_recommended_context",
    "share_boundary_low_quality",
    "compare_boundary_low_quality",
    "history_boundary_low_quality",
    "next_step_low_quality"
  ],
  "forbidden_hit_count": 0,
  "forbidden_hits": [],
  "blame_or_moral_judgment_hit_count": 0,
  "runtime_use_all_staging_only": true,
  "production_use_allowed_true_count": 0,
  "ready_for_pilot_true_count": 0,
  "ready_for_runtime_true_count": 0,
  "ready_for_production_true_count": 0,
  "body_length_min": 180,
  "body_length_max": 198,
  "body_length_outside_180_320": [],
  "duplicate_title_count": 0,
  "duplicate_body_count": 0,
  "public_payload_fields_checked": [
    "title_zh",
    "summary_zh",
    "short_body_zh",
    "body_zh",
    "cta_zh",
    "public_payload.title_zh",
    "public_payload.explanation_zh",
    "public_payload.safe_to_show_zh",
    "public_payload.should_avoid_zh",
    "public_payload.retest_guidance_zh",
    "public_payload.summary_zh",
    "public_payload.short_body_zh",
    "public_payload.cta_zh"
  ],
  "notes": [
    "This is a candidate artifact only.",
    "No final big5_result_page_v2 payload generated.",
    "No frontend copy, CMS, SEO, runtime, rollout, or production change.",
    "Codex must recalculate body_quality and validate selector contract before staging import."
  ]
}
```

## Codex follow-up required

Codex should perform:

1. Schema validation.
2. Selector contract validation.
3. `state_scope` / `slot_key` mapping check.
4. `body_quality` metadata recalculation.
5. Forbidden-token scan over rendered public text.
6. Result page / PDF / share / history / compare rendered hygiene scan.
7. Human review manifest.
8. Staging import only.

## Explicit non-goals

- No final `big5_result_page_v2` payload.
- No fap-web copy.
- No CMS / SEO / production / runtime text.
- No rollout or production import.

## Codex normalization note

Converted to backend candidate JSONL, added selector candidates and review manifest, recalculated body_quality, and kept staging-only/non-production flags.
