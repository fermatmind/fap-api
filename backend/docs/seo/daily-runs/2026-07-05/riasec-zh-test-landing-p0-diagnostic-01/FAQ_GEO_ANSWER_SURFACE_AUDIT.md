# FAQ / GEO Answer-Surface Audit

Target: `/zh/tests/holland-career-interest-test-riasec`

This audit is read-only. It records answer-surface coverage and next-step prompts; it does not write FAQ, schema, metadata, or CMS content.

## Diagnostic Questions

### 1. Does the page clearly answer "霍兰德职业兴趣测试免费吗" above the fold or in FAQ?

Status: `partial`

Evidence:

- Title: `免费霍兰德职业兴趣测试：RIASEC 完整结果 | FermatMind`
- H1: `免费霍兰德职业兴趣测试：RIASEC完整结果`
- Meta description starts with `免费完成霍兰德职业兴趣测试`
- CTA text includes `开始免费霍兰德测试`
- Exact phrase `霍兰德职业兴趣测试免费吗` was not found in HTML.
- FAQPage questions do not include a direct free/pricing question.

Interpretation: the page answers free intent through title/H1/meta/CTA, but not as a direct FAQ/GEO question-answer block. This is a future authority dry-run candidate, not an immediate repair.

### 2. Does the page explain the difference between 60题标准版 and 140题增强版?

Status: `present`

Evidence:

- H2: `选择霍兰德职业兴趣版本`
- H3: `60题标准版`
- H3: `140题增强版`
- CTA routes differentiate `form=riasec_60` and `form=riasec_140`.
- Backend registry describes the default public form as 60 questions and the enhanced 140-question form as supported by the same scale.

Interpretation: the distinction is visible and backed by backend authority. A future dry-run could still improve answer-block clarity, but current evidence does not require urgent repair.

### 3. Does the page state RIASEC is an exploration signal, not a precise career or major recommendation?

Status: `present_with_room_to_strengthen`

Evidence:

- Meta description: `结果用于方向参考，不承诺专业或录取结果。`
- FAQPage question includes `这是诊断吗？`
- HTML includes `探索`.
- HTML did not include `精准` or `推荐`.

Interpretation: the boundary is present and avoids overclaiming. A future FAQ/GEO dry-run could make "not a precise career or major recommendation" more explicit, but the current page is not making a detected high-risk precise-prediction claim.

### 4. Does the page connect RIASEC result to course / job activity / major validation without overclaiming?

Status: `present`

Evidence:

- HTML contains `专业`, `课程`, and `工作活动`.
- Related article links include:
  - `录取专业不理想要不要复读？课程成本、转专业窗口和霍兰德兴趣决策清单`
  - `专业不对口怎么找工作？JD拆解、技能证据和实习验证清单`
  - `热门专业适合我吗？用课程、职业活动和霍兰德兴趣做高考专业选择清单`
  - `高考志愿选专业：霍兰德、MBTI和职业兴趣测试怎么用`
  - `不知道自己适合什么职业怎么办？从职业兴趣、性格偏好到现实验证`

Interpretation: the page routes RIASEC toward validation workflows without observed claims that it guarantees major, admission, hiring, salary, or career success outcomes.

### 5. Does visible FAQ match FAQPage JSON-LD?

Status: `unverified`

Evidence:

- FAQPage JSON-LD question count: `4`
- JSON-LD questions:
  - `需要多久？`
  - `每道题都要回答吗？`
  - `可以重复测试吗？`
  - `这是诊断吗？`
- Simple visible-heading extraction after the `FAQ` heading found:
  - `免责声明`
  - `准备开始？`

Interpretation: this diagnostic cannot prove visible FAQ and JSON-LD parity. It records a parity uncertainty and recommends a separate read-only DOM readback. This PR does not authorize schema or renderer repair.

## Recommended Follow-Up

1. First: `RIASEC-ZH-TEST-LANDING-FAQ-PARITY-READBACK-01`
2. Then, only if parity is understood: `RIASEC-ZH-TEST-LANDING-FAQ-GEO-AUTHORITY-DRYRUN-01`

No current evidence justifies direct mutation before the gated readbacks.
