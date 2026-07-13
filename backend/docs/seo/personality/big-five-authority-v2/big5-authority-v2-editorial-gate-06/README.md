# Big Five Authority V2 editorial gate

`BIG5-AUTHORITY-V2-EDITORIAL-GATE-06` adds a reusable, fail-closed backend QA gate for later bilingual Big Five draft packages. It validates schema shape, source/claim coverage, bilingual intent parity plus independent authorship, duplicate/template risk, private-flow leakage, framework boundaries, scenario/counterexample/tradeoff/action specificity, and manual-review state.

The workflow keeps four separate artifacts: raw draft, skeptical review, repaired draft, and final candidate. A repair never overwrites or hides raw failures. AI detectors are not used as factual or originality judgments; the gate relies on traceable structural and evidence checks plus accountable human review.

Passing automated QA means only `ready for human review`. The reviewer remains `Unknown` until a real actor is assigned. Publication, schema eligibility, CMS writes, indexability, sitemap/llms, search submission, and deployment remain closed.

Run from the repository root:

```bash
node generated/big-five-authority-v2/big5-authority-v2-editorial-gate-06/validate-package.mjs
cd backend
php artisan personality-big-five:authority-v2-editorial-gate --json
```

Repository rule impact: this is backend/CMS content-production governance, not a new runtime or publication authority. It introduces no frontend fallback and makes no content surface public.
