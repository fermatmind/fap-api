# Big Five Content Asset Benchmark Rubric v0.1

Status: pass

This backend-only package defines the first-pass content thickening standard for Big Five V2 result-page assets. It uses 123test and Truity as structure and quality references only; it does not copy their wording, questions, report text, or proprietary layouts.

This package is not runtime content. It does not generate candidate assets, stage content, build a final result contract, write CMS, modify fap-web, trigger SEO/search, or change runtime/production gates.

## What It Standardizes

- Commercial-report depth expectations for domain bands, facets, couplings, profiles, scenarios, safe surfaces, and edge states.
- Safety boundaries for non-diagnostic, non-hiring, non-clinical, non-deterministic Big Five explanations.
- SEO/GEO readability requirements: clear definitions, answerable sections, method boundaries, and AI-search-safe summaries.
- Rendered hygiene expectations for future candidate/staging PRs.

## Future PRs

The train sequence after this rubric is:

1. BIG5-DOMAIN-BANDS-CONTENT-THICKENING-01
2. BIG5-FACET-CONTENT-THICKENING-01
3. BIG5-COUPLING-CONTENT-THICKENING-01
4. BIG5-CANONICAL-PROFILE-CONTENT-THICKENING-01
5. BIG5-SCENARIO-ACTION-CONTENT-THICKENING-01
6. BIG5-SHARE-PDF-HISTORY-COMPARE-SAFE-CONTENT-01
7. BIG5-NORM-LOW-QUALITY-EDGE-STATE-CONTENT-01

All future content PRs must remain backend-authoritative and keep `production_use_allowed=false` until a separate reviewed production path is authorized.
