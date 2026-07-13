# Big Five Authority V2 bilingual domain pages

`BIG5-AUTHORITY-V2-DOMAINS-08` prepares exactly ten backend-authoritative draft candidates: openness, conscientiousness, extraversion, agreeableness, and neuroticism in independently edited English and Chinese versions.

Each candidate includes a bounded definition, high/middle/low interpretation, six named facets, a concrete scenario, strengths and tradeoffs, a cross-domain combination, a low-risk observation experiment, misconceptions, method boundaries, and visible sources. Domain-specific examples remain `inference_requires_human_review`; the shared source ledger supports the broad five-domain and hierarchical model claims but does not independently validate FermatMind scores or every editorial example.

Run from the repository root:

```bash
node generated/big-five-authority-v2/big5-authority-v2-domains-08/validate-package.mjs
```

Raw, skeptical-review, repaired, and final artifacts stay separate. Passing automated QA means only `ready for human review`; CMS writes, publication, schema eligibility, indexability, sitemap/llms, search submission, and deployment remain closed.

Repository rule impact: this is a backend/CMS content-production draft package, not runtime authority. It changes no `backend/app` code, frontend fallback, existing range page, or facet-detail page.
