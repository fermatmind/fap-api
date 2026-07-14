# BIG5-AUTHORITY-V2-SEO-GEO-AUTHORITY-36

This package implements a backend/CMS-authoritative, per-candidate SEO/GEO eligibility contract for the exact 231-route PR35 inventory.

- Metadata, canonical, real hreflang, robots, schema, sitemap, `llms.txt`, and `llms-full.txt` decisions are recorded per candidate.
- Schema eligibility requires matching visible evidence and claim-boundary evidence; JSON-LD is not treated as graph proof, citation proof, or authority by itself.
- PR34 found no eligible Big Five media, and every candidate still lacks complete author/reviewer/date approval evidence. All 231 candidates therefore remain `WITHHOLD_FAIL_CLOSED` and `noindex,nofollow`.
- This PR executes zero CMS/database writes, public metadata/schema releases, sitemap/llms additions, indexability changes, search submissions, or deployments.

Repository rule impact: public Big Five content and discoverability remain CMS/backend-authoritative. This is an implementation-and-validation package, not a production publish or frontend fallback.
