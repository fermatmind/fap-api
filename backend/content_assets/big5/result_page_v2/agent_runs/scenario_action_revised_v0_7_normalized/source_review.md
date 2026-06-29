# Big Five Scenario / Action Candidates v0.7 Review

## Scope

This package revises only the Scenario / Action section.

- Content assets: 160
- Matrix: 8 canonical profiles × 5 scenarios × 4 scenario roles
- Output role: candidate content assets for Codex normalize / staging import review
- Not a final result-page payload
- No frontend copy
- No CMS / SEO / production / runtime changes

## Why v0.7

The previous version still carried visible repetition around generic scene framing, low-risk action language, and repeated profile/scene formulas. v0.7 rewrites the public fields with a stronger scenario-specific logic:

- each profile has a distinct behavioral center;
- each scenario has its own application logic;
- each role carries a different reading task;
- action protocol uses concrete steps instead of generic encouragement;
- misread/repair explains what may be misread and how to repair the expression.

## Coverage

Profiles covered:

- complex_explorer_low_structure: 复杂理解型探索者
- connective_coordinator: 连接驱动协调者
- orderly_supporter: 秩序维护型支持者
- overloaded_internalizer: 高负荷内耗型
- quiet_deep_worker: 稳态深工者
- sensitive_independent_thinker: 敏锐的独立思考者
- sharp_exploratory_driver: 锋利探索推进者
- vigilant_perfectionist: 戒备型完美主义者

Scenarios covered:

- collaboration: 协作沟通
- growth: 自我管理
- relationship: 关系沟通
- stress: 压力恢复
- work: 工作场景

Roles covered:

- scenario_action_protocol
- scenario_core_pattern
- scenario_misread_and_repair
- scenario_strength_and_risk

## Safety Boundaries

All candidates preserve:

```json
{
  "runtime_use": "staging_only",
  "production_use_allowed": false,
  "ready_for_pilot": false,
  "ready_for_runtime": false,
  "ready_for_production": false
}
```

Public text avoids:

- internal implementation tokens;
- raw or relative-comparison score language;
- fixed-type identity claims;
- clinical / treatment language;
- hiring or screening language;
- deterministic career, income, relationship, success, or life-outcome claims.

## QA Summary

```json
{
  "version": "v0_7",
  "content_asset_count": 160,
  "expected_matrix_count": 160,
  "all_profiles_covered": true,
  "profiles_covered": [
    "complex_explorer_low_structure",
    "connective_coordinator",
    "orderly_supporter",
    "overloaded_internalizer",
    "quiet_deep_worker",
    "sensitive_independent_thinker",
    "sharp_exploratory_driver",
    "vigilant_perfectionist"
  ],
  "all_scenarios_covered": true,
  "scenarios_covered": [
    "collaboration",
    "growth",
    "relationship",
    "stress",
    "work"
  ],
  "all_roles_covered": true,
  "roles_covered": [
    "scenario_action_protocol",
    "scenario_core_pattern",
    "scenario_misread_and_repair",
    "scenario_strength_and_risk"
  ],
  "forbidden_hit_count": 0,
  "forbidden_hits": [],
  "forbidden_scan_scope": [
    "title_zh",
    "summary_zh",
    "body_zh",
    "short_body_zh",
    "benefit_zh",
    "cost_zh",
    "common_misread_zh",
    "cta_zh",
    "repair_zh"
  ],
  "runtime_use_all_staging_only": true,
  "production_use_allowed_true_count": 0,
  "ready_for_pilot_true_count": 0,
  "ready_for_runtime_true_count": 0,
  "ready_for_production_true_count": 0,
  "duplicate_title_count": 0,
  "duplicate_body_count": 0,
  "body_length_min": 262,
  "body_length_max": 315,
  "body_length_mean": 280.27,
  "body_length_outside_260_380": []
}
```

## Requires Codex Follow-Up

Codex should still run:

```text
schema validation
selector contract validation
profile_key / scenario / scenario_role / slot_key mapping check
body_quality metadata recalculation
forbidden-token scan over rendered public text
result page / PDF rendered hygiene scan
human review manifest
staging import only
```

Do not connect this package directly to runtime or production.
