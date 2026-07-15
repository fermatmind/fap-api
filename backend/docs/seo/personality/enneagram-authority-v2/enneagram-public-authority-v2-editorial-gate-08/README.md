# Enneagram Public Authority V2 editorial gate

`ENNEAGRAM-PUBLIC-AUTHORITY-V2-EDITORIAL-GATE-08` is the reusable, fail-closed, read-only QA gate for the 116 bilingual draft assets authored in PR09–PR18. It consumes the PR07 source registry and 116 page claim maps; it does not create content authority or mutate CMS, database, revision pointers, public content, indexability, sitemap, llms, search submission, or deployment state.

The aggregate candidate must contain exactly 116 locale assets: 2 hub pages, 6 center pages, 18 core-type pages, 36 wing pages, and 54 instinctual-subtype pages. The report always emits exactly 116 QA rows keyed by `locale|identity_key`. Each row records the following ten gates:

1. schema and frozen-target coverage;
2. independently authored EN and zh-CN content with non-identical outlines; rendered EN fields must contain at least 120 Latin characters and a 60% Latin share of letters, while rendered zh-CN fields must contain at least 80 Han characters and a 25% Han share of letters;
3. page-specific information gain;
4. locale-calibrated sentence, paragraph, and type/page-marker-substitution duplicate/template risk, normalizing type labels, frozen identity slugs, UUIDs, and hexadecimal page markers (EN 80/50 characters; zh-CN 30/24);
5. page-specific FAQ depth and non-repeated answers;
6. specific observable exercises rather than a generic seven-day prompt;
7. visible GEO answerability, with every declared question mapped through `question_answers` only to a substantive `answer_first`, `sections.N.body`, or `faqs.N.answer` field;
8. visible evidence and limitations;
9. truthful pending manual-review and closed release state;
10. source-ledger claim safety, including unsupported science, centers-as-biological/diagnostic-system claims, one-fixed-type or universal-nine-factor claims, predictive outcomes, guaranteed search/traffic/AI-citation outcomes, and competitor-language blocks.

The required negative fixtures cover sentence/paragraph duplication, numeric, singular-spelled, plural-spelled, identity-slug, and hash-marker substitution, identical EN/zh-CN outlines, repeated FAQ answers, a generic seven-day exercise, unsupported science claims, centers presented as biological or diagnostic systems, one fixed type per person or universal nine-factor recovery, career or relationship prediction, guaranteed search/traffic/AI-citation outcomes, model QA or approval wording presented as completed human review, hidden evidence, and copied competitor language.

Run after PR09–PR18 have assembled the aggregate candidate:

```bash
cd backend
php artisan personality:enneagram-authority-v2-integrity-gate \
  --editorial-source=/absolute/path/to/aggregate-candidate.json \
  --json
```

A clean automated report has status `ready_for_human_review`; it is not a human approval or release decision. The service and command hard-code `human_review_completed=false`, `human_review_passed=false`, and `publish_eligible=false`. A model, agent, test, or automated reviewer may never be recorded as the named human reviewer.

Repository rule impact: this establishes a backend/CMS content-production QA contract only. The content remains backend-authoritative, draft-only, and `pending_manual_review`; there is no frontend editorial fallback and no production action.
