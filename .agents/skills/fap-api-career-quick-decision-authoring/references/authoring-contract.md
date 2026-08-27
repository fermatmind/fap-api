# Quick-decision authoring contract

## Render and ownership map

The frontend `quick-decision` visual group combines two existing components; it does not define a backend authority boundary.

| Visible field | Reader job | Authority treatment |
|---|---|---|
| `fermat_decision_card.title` | Ask the occupation-specific fit question | Derived projection; assemble through the canonical builder from authorized module inputs |
| `fermat_decision_card.summary` | Give a direct, qualified answer based on the core work bargain | Derived projection; do not make it an independently edited authority |
| `fermat_decision_card.caveat` | State the non-deterministic decision boundary | Derived projection; keep consistent with fit/risk/page metadata |
| `fit_decision_checklist.suit` | Describe behaviors and conditions that make the work more sustainable | `fit-personality` owner |
| `fit_decision_checklist.boundary` | Describe tradeoffs and conditions that deserve caution | `fit-personality` owner; factual risk premises remain bound to their source modules |
| `fit_decision_checklist.how` | Let the reader test the work before making a high-cost decision | `fit-personality` owner; editorial work-sample guidance |

The repository ownership map remains `backend/docs/career/contracts/career-sharded-current-field-ownership.v1.json`. If the compiler's source fields and the Current projection differ, follow that map and the canonical builder; do not resolve the mismatch by editing another shard or duplicating copy.

## Semantic contract

### `title`

- Ask a natural question containing the canonical occupation name or an unambiguous reader-facing title.
- Match the page language. Do not stuff variants, salary, AI, location, credentials, or superlatives into the heading.
- The fixed visual-group label “费马快速判断 / Fermat Quick Fit” is UI structure, not a substitute for the occupation-specific question.

### `summary`

- Answer in the first sentence. State what the work repeatedly asks a person to do and what responsibility or tradeoff comes with it.
- Include at least two occupation-specific realities. Prefer tasks, decisions, work products, stakeholders, or conditions over adjectives.
- Keep uncertainty explicit: “更可能适合 / may fit better” is acceptable; “天生适合 / guaranteed fit” is not.
- Do not drift into salary, job outlook, AI impact, certification marketing, or a generic personality description.

### `suit`

- Describe observable behaviors, learned capabilities, preferred feedback loops, and tolerable work conditions.
- Connect each signal to a real task or context: for example, accuracy matters because the worker must reconcile evidence, not because “detail-oriented people are accountants.”
- Include meaningful differences within a combined occupation when the experience varies by specialty or setting.
- Do not use assessment types or scores as admission criteria.

### `boundary`

- Name the work conditions that may create sustained friction: pace, ambiguity, emotional load, physical exposure, repetition, conflict, liability, schedule, or regulated responsibility.
- Explain the condition and its consequence without insulting, diagnosing, or excluding the reader.
- Avoid labels such as “玻璃心”, “娇气”, “手笨”, “不聪明”, “不正常”, or equivalent English wording. Do not infer fitness from age, sex, disability, ethnicity, family status, or another protected characteristic.
- For regulated or YMYL work, state jurisdiction and avoid individualized legal, medical, financial, safety, or licensing advice.

### `how`

- Give one low-cost simulation that resembles a core task, normally achievable in 20 minutes to two hours without privileged systems, private data, unsafe equipment, or unlicensed practice.
- Specify the input, task, observable output, and two or three reflection questions. The reader should be able to distinguish “I can learn this but need practice” from “this work pattern repeatedly drains me.”
- Use synthetic or public-safe materials. Never ask the reader to handle real patient, client, financial, legal, or employer-confidential data.
- A degree list, certification list, career ladder, or “take our test” CTA is not a work-sample experiment.

### `caveat`

- State that this is a career-exploration aid, not a diagnosis, hiring screen, income forecast, qualification decision, or outcome promise.
- Keep the caveat concise and aligned with the actual risk level; do not bury unsupported claims behind a disclaimer.

## Locale and occupation boundaries

- Author each locale for natural reading while preserving the same evidence scope and claim strength. Do not translate a US fact into a China claim or infer market/jurisdiction from locale.
- Preserve exact, combined, parent, and proxy occupation scopes. When one page combines distinct roles, say where the work experience diverges.
- Unknown evidence remains unresolved or omitted. Never borrow a neighboring occupation's fit conclusion to complete a template.
