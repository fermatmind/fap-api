# Enneagram Result Page Content Asset Workspace v0.1

This is the editable workspace for Enneagram result-page content asset thickening. Use it to draft one small module or batch at a time, then convert the approved draft into repo-owned payloads in a future PR.

This file is not runtime content. It is not a candidate package. It is not imported or activated.

## Global Rules

- Write for reflection: motivation, repeated patterns, stress cues, relationship tendencies, growth observations.
- Do not write final type verdicts. Use "leans toward", "shows stronger signals", or "current result is closer to".
- Do not diagnose, recommend treatment, screen for hiring, predict salary, predict success, or claim fixed identity.
- Do not expose raw scores, score vectors, attempt ids, dominance gap, entropy, selector traces, release hashes, or private report payloads.
- Benchmark 123test and Truity for section shape only. Do not copy their wording or claims.
- Keep Big Five and MBTI as complements, not replacements or inferior alternatives.

## Benchmark Links

- 123test Enneagram test: https://www.123test.com/enneagram-test/
- Truity Enneagram Personality Test: https://www.truity.com/test/enneagram-personality-test
- Truity Enneagram chart explanation: https://www.truity.com/blog/how-do-you-read-enneagram-chart

## Module 1: Result Overview Hero

Current objective:
Explain the user's current leading Enneagram tendency as a reflection cue.

Observed gap:
Clear-state copy must avoid direct identity-verdict phrasing and remain non-final.

Existing backend sources:
- `batch_1r_a.instant_summary`
- `batch_1r_a.type_deep_dive_summary`

Agent task:
Draft short zh-CN copy for the hero, using score-free and non-final language.

Output skeleton:

```json
{
  "module_id": "result_overview_hero",
  "locale": "zh-CN",
  "type_code_or_pair": "TBD",
  "scope": "clear|close_call|diffuse",
  "public_payload": {
    "heading": "",
    "lead": "",
    "body": "",
    "reflection_prompt": "",
    "boundary_note": ""
  },
  "source_trace": {
    "source_ledger_row_id": "",
    "source_asset_batch": "",
    "source_asset_key": "",
    "benchmark_refs_used": [],
    "copy_policy": "original_paraphrase_only"
  },
  "safety_flags": {
    "non_diagnostic": true,
    "non_hiring": true,
    "no_hard_typing": true,
    "no_accuracy_superiority": true,
    "no_score_vector_leak": true,
    "public_safe_only": true
  },
  "rollback_notes": ""
}
```

## Module 2: Top Three Candidate Reading

Current objective:
Explain the top three type tendencies without raw numbers.

Observed gap:
Rendered inventory found raw-like score displays and private metric language risk.

Existing backend sources:
- `batch_1r_a.all9_profile`
- `batch_1r_d.all9_profile`
- `batch_1r_e.all9_profile`

Agent task:
Write score-free ranking language: strongest signal, secondary signal, quieter but present signal.

Avoid:
- numeric scores,
- percentage-like confidence,
- dominance gap,
- "this proves your type".

## Module 3: Primary Type Deep Dive

Current objective:
Make the primary type section feel complete, localized, and useful.

Content slots:
- core motivation,
- likely defense pattern,
- stress reaction,
- relationship cue,
- growth cue,
- reflection prompt.

Existing backend sources:
- `batch_1r_a.type_deep_dive_summary`
- `batch_1r_b.type_deep_dive_summary`
- `batch_1r_d.type_deep_dive_summary`

Agent task:
Draft one type at a time. Keep each paragraph specific enough to feel useful, but never fixed or diagnostic.

## Module 4: All Nine Profile Score Band

Current objective:
Turn all-nine ordering into readable public-safe bands.

Allowed public bands:
- stronger signal,
- present signal,
- mixed signal,
- quieter signal.

Forbidden:
- raw score,
- score vector,
- exact rank confidence,
- private metric labels.

Existing backend sources:
- `batch_1r_a.all9_profile`
- `batch_1r_d.all9_profile`
- `batch_1r_e.all9_profile`

Agent task:
Write a short band explanation and a per-type micro-summary.

## Module 5: Confidence and Dominance Boundary

Current objective:
Explain why results are a reading, not a verdict.

Observed gap:
Dominance gap and entropy should not appear in user-facing content.

Existing backend sources:
- `batch_1r_a.methodology_boundary_card`
- `batch_1r_b.methodology_boundary_card`
- `batch_1r_c.methodology_boundary_card`
- `batch_1r_d.methodology_boundary_card`
- `batch_1r_e.methodology_boundary_card`

Agent task:
Replace private metrics with public-safe guidance: clear leaning, close call, diffuse pattern, low-quality result.

## Module 6: Close-Call Pair Differentiation

Current objective:
Replace scaffold copy with pair-specific contrast.

Observed gap:
Rendered inventory recorded scaffold text in the close-call section.

Existing backend sources:
- `batch_1r_d.close_call_card`
- `batch_1r_f.close_call_pair_card`

Agent task:
For one pair at a time, write:
- where the two types can look similar,
- what motivation difference separates them,
- what stress or relationship cue helps reflect,
- a non-final boundary note.

This is the highest-priority true content gap.

## Module 7: Work Reality

Current objective:
Explain work style as reflection, not employment screening.

Existing backend sources:
- `batch_1r_b.work_style_summary`
- `batch_1r_g.scene_localization`

Forbidden:
- employment-screening fit,
- job fit judgment,
- salary,
- performance prediction,
- promotion likelihood.

Agent task:
Write practical work-pattern reflection: attention, conflict, feedback, energy, overuse risk.

## Module 8: Growth Spectrum

Current objective:
Make growth content concrete and not repetitive.

Observed gap:
Rendered inventory flagged repeated-copy risk.

Existing backend sources:
- `batch_1r_b.growth_axis`
- `batch_1r_b.stress_trigger`
- `batch_1r_b.seven_day_observation`
- `batch_1r_d.growth_axis`
- `batch_1r_d.stress_trigger`

Agent task:
Write type-specific observations and one seven-day experiment. Avoid therapy claims.

## Module 9: Relationship and Conflict Reflection

Current objective:
Support social discussion and relationship reflection safely.

Existing backend sources:
- `batch_1r_b.relationship_need`
- `batch_1r_g.scene_localization`

Agent task:
Write language users can discuss with friends or partners without labeling the other person.

Avoid:
- "your partner is",
- diagnosis,
- fixed role assignment,
- manipulation advice.

## Module 10: Method, Observation, and Next Step

Current objective:
Explain method boundaries and cross-test complement positioning.

Existing backend sources:
- `batch_1r_a.methodology_boundary_card`
- `batch_1r_b.methodology_boundary_card`
- `batch_1r_c.methodology_boundary_card`
- `batch_1r_h.form_recommendation`

Agent task:
Position:
- Enneagram as motivation-pattern reflection,
- Big Five as trait structure,
- MBTI as preference and communication style,
- FC144 as a second lens only, not a more accurate replacement.

## Module 11: Public-Safe Share, PDF, History, Compare

Current objective:
Define surface-safe excerpts for share/PDF/history/compare.

Observed gap:
Share entry and related surfaces need explicit public/private contract mapping.

Allowed share content:
- type ranking labels,
- short public-safe tendency summary,
- boundary note,
- CTA.

Forbidden share/PDF/history/compare content:
- attempt id,
- raw score,
- score vector,
- dominance gap,
- entropy,
- release hash,
- schema version,
- private report payload,
- selector trace,
- internal metadata.

Agent task:
Draft surface-specific allowed fields and negative assertions before writing any content payload.

## Future PR Slicing

1. Close-call pair content workspace and scaffold removal.
2. Score-free ranking and confidence copy.
3. Primary type and zh-CN label depth.
4. Growth and relationship thickening.
5. Work, method, and cross-test CTA thickening.
6. Public-safe share/PDF/history/compare contract.

Each future PR must include source mapping, safety report, diff report, rollback notes, and targeted tests.
