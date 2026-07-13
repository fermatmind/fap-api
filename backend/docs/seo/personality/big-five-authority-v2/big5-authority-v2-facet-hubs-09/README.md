# Big Five Authority V2 bilingual facet hubs

`BIG5-AUTHORITY-V2-FACET-HUBS-09` prepares exactly two backend-authoritative draft candidates for `/en/personality/big-five/facets` and `/zh/personality/big-five/facets`.

Each independently edited hub separates broad domains from narrower facets, groups the frozen 30-facet inventory under the five domains, provides locale-safe navigation for every facet, explains responsible use, addresses common misreadings, states method boundaries, and exposes approved sources.

Run from the repository root:

```bash
node generated/big-five-authority-v2/big5-authority-v2-facet-hubs-09/validate-package.mjs
```

Raw, skeptical-review, repaired, and final artifacts remain separate. Automated QA only makes the package ready for human review. It does not write CMS data, approve or rewrite any facet-detail page, change runtime routing, open indexability, submit search URLs, or deploy.

Repository rule impact: this is a backend/CMS content-production draft package. CMS/backend remains authoritative; no content ownership, runtime fallback, publishing workflow, media, or indexability rule changes are introduced.
