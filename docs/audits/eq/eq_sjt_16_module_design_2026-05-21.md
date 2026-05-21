# EQ-SJT 16 Module Design

## 1. Purpose

EQ-SJT 16 is a future scenario-based emotional judgment module for FermatMind EQ.

Its job is to complement the existing EQ-60 self-report core. EQ-60 explains how users perceive their own emotional and relational patterns. EQ-SJT 16 will add structured evidence about how users say they would choose responses in emotional, interpersonal, and workplace-like scenarios.

Boundary statements:

- EQ-SJT 16 is not MSCEIT.
- EQ-SJT 16 is not an ability-certified emotional intelligence test.
- EQ-SJT 16 is not a clinical diagnostic instrument.
- EQ-SJT 16 is not a hiring, screening, or selection tool.
- EQ-SJT 16 should be described as applied scenario-based emotional judgment, not as a measure of true emotional ability.

The commercial product value is not a standalone "higher EQ score." The value is an integrated interpretation layer:

```text
EQ-60 self-perception
+ EQ-SJT scenario judgment
= self-perception x applied response pattern
```

## 2. Why Separate from EQ-60

EQ-60 and EQ-SJT answer different product and measurement questions.

EQ-60 answers:

- How does the user describe their own emotional awareness?
- How does the user describe their own emotion regulation?
- How does the user describe empathy and relational management?
- What emotional and relational pattern can be formulated from self-report signals?

EQ-SJT answers:

- What response does the user say they are most likely to choose in a specific emotional situation?
- Does the user tend to pause, repair, set boundaries, de-escalate, empathize, or influence constructively?
- Where does the user's applied response pattern align with, or diverge from, their EQ-60 self-perception?

The two should not be collapsed into one opaque total score. A single mixed score would hide the most valuable signal: the gap between self-perception and scenario judgment.

The future integrated report should preserve two layers:

- EQ-60: self-report trait and mixed emotional-relational pattern.
- EQ-SJT 16: scenario-based applied emotional judgment pattern.

## 3. 16-Item Structure

Version 1 should use 8 scenario domains with 2 items per domain.

| Domain ID | Domain | What It Samples |
| --- | --- | --- |
| `emotion_cue_reading` | Emotion cue reading | Noticing emotional signals without over-interpreting them. |
| `pressure_pause` | Pressure pause | Creating a response gap under stress or provocation. |
| `feedback_response` | Feedback response | Separating useful information from emotional activation. |
| `conflict_deescalation` | Conflict de-escalation | Reducing escalation while keeping the issue visible. |
| `empathic_response` | Empathic response | Acknowledging others without absorbing or rescuing. |
| `boundary_setting` | Boundary setting | Staying warm while defining limits and capacity. |
| `relationship_repair` | Relationship repair | Restoring trust after friction, misread cues, or impact. |
| `constructive_influence` | Constructive influence | Moving people toward a better next step without coercion. |

Design target:

- 16 total items.
- 4 response options per item.
- One "most likely response" selection per item in v1.
- 0-3 partial credit per option.
- Strategy tags attached to each option.
- Locale and risk notes attached to each item.

## 4. Item Format

Each item should be stored as structured content, not hardcoded in frontend code.

Proposed item fields:

```json
{
  "item_id": "eq_sjt.feedback_response.01",
  "domain": "feedback_response",
  "locale": "zh-CN",
  "stem": "Illustrative draft only.",
  "prompt": "What would you most likely do first?",
  "options": [
    {
      "option_id": "A",
      "text": "Illustrative draft only.",
      "strategy_tag": "PAUSE",
      "partial_credit": 2,
      "risk_tag": "may_delay_clarity"
    }
  ],
  "locale_notes": [],
  "risk_notes": [],
  "status": "draft_illustrative_only"
}
```

Illustrative draft example 1, not final item bank:

- Domain: `feedback_response`
- Scenario: A teammate says your work created extra rework for them during a deadline week.
- Prompt: What would you most likely do first?
- Option pattern:
  - Ask for the concrete example and acknowledge the impact before defending intent.
  - Explain immediately why the deadline pressure made the mistake understandable.
  - Apologize broadly and take all responsibility without clarifying the facts.
  - Withdraw from the conversation and revisit it only if they bring it up again.

Illustrative draft example 2, not final item bank:

- Domain: `boundary_setting`
- Scenario: A colleague repeatedly sends urgent requests late at night and frames them as relationship loyalty.
- Prompt: What would you most likely do first?
- Option pattern:
  - Name the constraint and offer a clear next available time.
  - Reply immediately so the relationship does not feel damaged.
  - Ignore the message and hope the pattern fades.
  - Respond with irritation so the boundary is obvious.

These examples are structural only. They must be rewritten, reviewed, localized, and calibrated before becoming production items. They are not copied from competitors and should not be treated as official EQ-SJT content.

## 5. Scoring Draft

Version 1 should use expert-rubric partial credit.

| Credit | Meaning | Response Quality |
| --- | --- | --- |
| 0 | High-risk response | Escalates, avoids, over-accommodates, attacks, or breaks the relational task. |
| 1 | Low-effectiveness response | Has a partial intention but misses timing, emotional impact, or boundary clarity. |
| 2 | Partially effective response | Addresses part of the emotional task but leaves a meaningful risk unmanaged. |
| 3 | High-quality response | Balances emotion recognition, regulation, relationship impact, and next action. |

Scoring rules:

- Every option receives a 0-3 partial credit score.
- Every option receives at least one strategy tag.
- Domain scores are aggregated from the two items in that domain.
- Applied strategy scores are aggregated from option tags, not just item domain.
- No item should rely on a single culturally narrow "perfect response."
- Low-score options should be plausible, not cartoonishly bad.

Recommended scoring artifacts:

- `items.json`
- `options.json`
- `rubric.json`
- `strategy_tags.json`
- `golden_cases.json`
- `locale_review_notes.json`

## 6. Applied Strategy Scores

EQ-SJT 16 should produce six applied strategy scores.

| Code | Label | Meaning | Primary Inputs |
| --- | --- | --- | --- |
| `CUE` | Cue Reading | Notices emotional and relational signals without overclaiming. | `emotion_cue_reading`, `feedback_response`, `empathic_response` |
| `PAUSE` | Pause and Regulation | Creates enough space to respond rather than react. | `pressure_pause`, `feedback_response`, `conflict_deescalation` |
| `EMP` | Empathic Response | Acknowledges feelings and impact while preserving clarity. | `empathic_response`, `emotion_cue_reading`, `relationship_repair` |
| `BND` | Boundary Setting | Defines limits without unnecessary coldness or escalation. | `boundary_setting`, `pressure_pause`, `constructive_influence` |
| `REPAIR` | Relationship Repair | Restores trust after conflict, impact, or misalignment. | `relationship_repair`, `conflict_deescalation`, `feedback_response` |
| `INFL` | Constructive Influence | Helps move a person or group toward a better next step. | `constructive_influence`, `conflict_deescalation`, `boundary_setting` |

Limitations:

- These scores are applied strategy indicators, not certified ability scores.
- A high strategy score does not prove real-world execution under pressure.
- A low strategy score should be framed as a development priority, not as a fixed deficit.

## 7. v1 Decision

Version 1 should ask only for likely response:

> What would you most likely do first?

Do not include a best-vs-likely split in v1.

Reason:

- Likely response is easier for users to understand and complete.
- It reduces item complexity and reporting complexity.
- It avoids overstating a gap between "knowing the best answer" and "doing it in real life" before the rubric is validated.
- It lets the team validate item clarity, option plausibility, and scoring stability first.

Version 2 can consider a split:

- Best response: what the user thinks is most effective.
- Likely response: what the user would probably do.

That split should wait until there is stronger anti-idealization handling and reporting logic.

## 8. Quality and Risk Controls

Key risks:

- Idealized answering: users may choose the response that sounds mature rather than the one they would actually choose.
- Cultural bias: directness, apology, hierarchy, and boundary norms vary by locale.
- Workplace hierarchy: responses differ when the other person is a manager, peer, direct report, client, or family member.
- Localization drift: translated options can change emotional intensity or politeness level.
- Rubric overconfidence: expert-scored options may look precise before validation supports that precision.
- Overclaiming: scenario judgment is not proof of real-world behavior.
- Self-report mismatch: gaps between EQ-60 and EQ-SJT can be meaningful but should not be framed as deception or inconsistency by default.

Controls:

- Keep claim boundaries visible in report methodology.
- Include locale review for every item.
- Use golden cases for scoring regression.
- Run item-level analysis before adding percentile claims.
- Avoid hiring, clinical, and certified ability language.
- Use confidence labels when item coverage is limited.
- Review scenarios for hierarchy, gender, culture, and workplace power assumptions.

## 9. Integrated EQ Report Draft

The integrated report should appear only after both EQ-60 and EQ-SJT 16 are completed.

Proposed report sections:

1. Integrated Core Insight
   - A concise formulation of self-perception and scenario judgment together.
2. EQ-60 Self-Report Recap
   - Summary of self-awareness, emotion regulation, empathy, and relationship management.
3. EQ-SJT Applied Judgment Dashboard
   - Domain scores and applied strategy scores.
4. Alignment / Gap Map
   - Where self-perception and scenario judgment align or diverge.
5. Pressure Pattern
   - How responses shift under feedback, conflict, stress, and boundary pressure.
6. Scenario Scripts
   - Short applied scripts based on the main development priority.
7. Updated Career Environment Lens
   - Environment variables that may amplify strengths or strain.
8. 14-Day Action Path
   - A short practice plan based on the combined pattern.
9. Scientific Boundary
   - Self-report, scenario judgment, non-clinical, non-hiring, non-certified statements.

Potential gap types:

| Gap Type | Meaning |
| --- | --- |
| `aligned_strength` | Self-report signal is strong and scenario judgment is also strong. |
| `overestimated_capacity` | Self-report is stronger than scenario judgment. |
| `underestimated_capacity` | Scenario judgment is stronger than self-report. |
| `development_priority` | Both self-report and scenario judgment point to a growth area. |
| `knowledge_execution_gap` | The user may know a strategy but still need support applying it. |
| `boundary_gap` | Empathy or relationship intent is high, but limit-setting strategy is weaker. |

## 10. Backend Architecture Draft

Proposed backend content pack:

```text
backend/content_packs/EQ_SJT_16/v1/
  raw/
    manifest.json
    items.json
    options.json
    rubric.json
    strategy_tags.json
    report_assets/
      scientific_contract.json
      score_system.json
      domain_interpretations.json
      strategy_interpretations.json
      gap_map.json
      action_paths.json
  compiled/
```

Proposed backend services:

- `EqSjt16Driver`
- `EqSjt16Scorer`
- `EqSjt16PackLoader`
- `EqIntegratedReportComposer`

Required backend tests:

- content gate test for item structure and locale coverage.
- scorer golden cases.
- submit contract test.
- integrated report payload contract test.
- no-overclaiming content guard.
- no-paywall regression guard if EQ-SJT remains free.

This document does not implement the content pack, scorer, driver, routes, or integrated composer.

## 11. Frontend Architecture Draft

Future frontend components:

- `EQSJTIntro`
- `EQSJTScenarioCard`
- `EQSJTResponseOption`
- `EQSJTProgress`
- `EQSJTSubmitReview`
- `EQIntegratedResult`
- `EQAlignmentGapMap`
- `EQAppliedJudgmentDashboard`
- `EQScenarioScripts`

Frontend principles:

- Response options should be clear, comparable, and accessible.
- Do not show option scores during the test.
- Do not imply there is a single universally correct emotional response.
- Keep all official item text and report interpretation backend-authoritative.
- Do not hardcode report claims in frontend components.
- Do not enable the SJT route until backend item, scorer, and submit contracts exist.

This document does not implement frontend routes, take flow, or integrated report rendering.

## 12. Future PR Train

Recommended future sequence:

1. `PR-EQ-SJT-01` - content pack skeleton
   - Add `EQ_SJT_16/v1` manifest, item schema, rubric schema, and non-final placeholder fixtures.
   - No public route.
2. `PR-EQ-SJT-02` - scorer and golden cases
   - Add partial-credit scorer, strategy aggregation, golden cases, and claim-boundary tests.
   - No frontend route.
3. `PR-EQ-SJT-03` - frontend take flow
   - Add scenario card UI and submit flow against backend contract.
   - No integrated report until backend composer exists.
4. `PR-EQ-SJT-04` - integrated report composer
   - Combine EQ-60 and EQ-SJT signals into an integrated report payload.
   - Add alignment/gap map and action path assets.
5. `PR-EQ-SJT-05` - validation, telemetry, and QA
   - Add item analytics, completion metrics, smoke QA, localization review, and report claim audit.

Dependencies:

- EQ-60 v5 all-free contract remains stable.
- EQ-60 v5 content assets remain backend-authoritative.
- EQ-60 v5 frontend renderer remains the base self-report result.
- EQ-SJT should not be marked available until take, submit, scoring, and report contracts are ready.

## 13. Claim Boundary

Forbidden claims:

- "Measures true emotional ability."
- "MSCEIT-like."
- "Certified emotional intelligence."
- "Suitable for hiring."
- "Clinical assessment."
- "Predicts job performance."
- "Objective emotional intelligence score."
- "Real EQ ability."

Allowed framing:

- "Scenario-based emotional judgment."
- "Applied response pattern."
- "Complements self-report."
- "Shows how you say you would respond in emotional and relational situations."
- "Useful for reflection, coaching, and growth planning."
- "Not intended for diagnosis, hiring, certification, or high-stakes decisions."

The safest product language is:

> EQ-SJT 16 helps compare how you see your emotional and relational patterns with how you say you would respond in specific scenarios. It is a reflection and development tool, not a clinical, hiring, certification, or ability-test instrument.
