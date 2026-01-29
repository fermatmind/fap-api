# Content Pack Universal Questions Schema (PR16)

## Purpose
Define a universal, asset-ready `questions.json` shape so Raven-style graphical questions can be served with ready-to-use asset URLs. This PR only guarantees **assets schema + URL mapping**. Question-type expansion is handled in PR21.

## questions.json (minimum shape)
Supported top-level shapes:
- Array: `[{...}, {...}]`
- Object: `{ "items": [...] }`
- Object: `{ "questions": [...] }`
- Object: `{ "data": [...] }`

Recommended fields per question (generic):
- `question_id` (string)
- `order` (number)
- `type` (string, optional)
- `stem` (object, optional)
- `options` (array of objects, >= 2)

## Assets placement (PR16 scope)
The assets mapping layer scans and maps only the following locations:
- `question.assets.*`
- `question.stem.assets.*` (when `stem` is an object)
- `question.options[].assets.*`

Each `assets.*` value must be a **string relative path**. Example: `assets/images/raven_opt_a.png`.

## Raven example (minimal)
```json
{
  "schema": "fap.questions.v1",
  "schema_version": 1,
  "items": [
    {
      "question_id": "RAVEN_DEMO_1",
      "order": 1,
      "type": "raven_single",
      "assets": { "image": "assets/images/raven_question_1.png" },
      "stem": {
        "text": "请选择能够补全矩阵的图形。",
        "assets": { "image": "assets/images/raven_stem_1.png" }
      },
      "options": [
        { "code": "A", "assets": { "image": "assets/images/raven_opt_a.png" } },
        { "code": "B", "assets": { "image": "assets/images/raven_opt_b.png" } },
        { "code": "C", "assets": { "image": "assets/images/raven_opt_c.png" } },
        { "code": "D", "assets": { "image": "assets/images/raven_opt_d.png" } }
      ]
    }
  ]
}
```

## Compatibility
- Existing MBTI questions are unchanged.
- If a question has no `assets` field, output is unchanged.
- Invalid asset paths are blocked by `fap:self-check --strict-assets` and by runtime mapping.
