# Big Five Authority V2 bilingual hubs

`BIG5-AUTHORITY-V2-HUB-07` prepares exactly two backend-authoritative draft candidates:

- `/en/personality/big-five`
- `/zh/personality/big-five`

The English and Chinese hubs are independently edited around the same reader intents: a direct answer, OCEAN overview, dimensional interpretation, result reading, facet navigation, use cases, misconceptions, a concrete scenario, a counterexample, a tradeoff, a low-risk action, method boundaries, visible evidence, and locale-safe internal links.

The package keeps `raw-draft.json`, `skeptical-review.json`, `repaired-draft.json`, and `final-package.json` separately. Raw failures remain observable; the validator requires the skeptical review to account for them before repaired and final candidates may pass automated QA. AI detectors are not used.

Run from the repository root:

```bash
node generated/big-five-authority-v2/big5-authority-v2-hub-07/validate-package.mjs
```

Passing this gate means only `ready for human review`. Reviewer identity, approval date, publication, CMS writes, schema eligibility, indexability, sitemap/llms inclusion, search submission, and deployment remain closed.

Repository rule impact: these files are a backend/CMS content-production draft package, not runtime or publication authority. This PR does not change `backend/app`, does not add a frontend fallback, and does not modify any existing public page.
