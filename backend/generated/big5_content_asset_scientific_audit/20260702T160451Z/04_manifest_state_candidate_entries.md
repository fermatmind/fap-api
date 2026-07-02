# Candidate PR train manifest/state entries (do not write without authorization)

```yaml
- id: BIG5-CONTENT-ASSET-SCIENTIFIC-AUDIT-MAPPING-01
  title: Big Five content asset scientific audit mapping
  scope: generated/big5_content_asset_scientific_audit/** only
  depends_on: none

- id: BIG5-SELECTOR-PUBLIC-PAYLOAD-HYGIENE-01
  title: Big Five selector public payload hygiene
  scope: backend/content_assets/big5/result_page_v2/agent_runs/**/selector_asset_candidates.jsonl and related tests
  depends_on: BIG5-CONTENT-ASSET-SCIENTIFIC-AUDIT-MAPPING-01

- id: BIG5-METHOD-BOUNDARY-SCIENTIFIC-REPAIR-01
  title: Big Five method boundary scientific repair
  scope: method_boundary agent/staging artifacts and method/privacy tests
  depends_on: BIG5-SELECTOR-PUBLIC-PAYLOAD-HYGIENE-01

- id: BIG5-DOMAIN-BANDS-SCIENTIFIC-REPAIR-01
  title: Big Five domain bands scientific repair
  scope: domain_bands agent/staging artifacts and tests
  depends_on: BIG5-METHOD-BOUNDARY-SCIENTIFIC-REPAIR-01

- id: BIG5-FACETS-30-SCIENTIFIC-REPAIR-01
  title: Big Five 30 facets scientific repair
  scope: facets_30 agent/staging artifacts and tests
  depends_on: BIG5-DOMAIN-BANDS-SCIENTIFIC-REPAIR-01

- id: BIG5-COUPLING-VARIANTS-SCIENTIFIC-REPAIR-01
  title: Big Five coupling variants scientific repair
  scope: coupling_variants agent/staging artifacts and tests
  depends_on: BIG5-FACETS-30-SCIENTIFIC-REPAIR-01

- id: BIG5-SHARE-SAFETY-SCIENTIFIC-REPAIR-01
  title: Big Five share safety scientific repair
  scope: share_safety agent/staging artifacts and tests
  depends_on: BIG5-COUPLING-VARIANTS-SCIENTIFIC-REPAIR-01

- id: BIG5-LOW-QUALITY-SCIENTIFIC-REPAIR-01
  title: Big Five low quality scientific repair
  scope: low_quality agent/staging artifacts and tests
  depends_on: BIG5-SHARE-SAFETY-SCIENTIFIC-REPAIR-01

- id: BIG5-NORM-UNAVAILABLE-SCIENTIFIC-REPAIR-01
  title: Big Five norm unavailable scientific repair
  scope: norm_unavailable agent/staging artifacts and tests
  depends_on: BIG5-LOW-QUALITY-SCIENTIFIC-REPAIR-01

- id: BIG5-RENDERED-SURFACE-QA-CONTENT-REPAIR-01
  title: Big Five rendered surface QA content repair
  scope: rendered_surface_qa backend artifacts and rendered QA tests
  depends_on: BIG5-NORM-UNAVAILABLE-SCIENTIFIC-REPAIR-01

- id: BIG5-CANONICAL-PROFILES-SCIENTIFIC-REPAIR-01
  title: Big Five canonical profiles scientific repair
  scope: canonical_profiles agent/staging artifacts and tests
  depends_on: BIG5-RENDERED-SURFACE-QA-CONTENT-REPAIR-01

- id: BIG5-SCENARIO-ACTION-SCIENTIFIC-REPAIR-01
  title: Big Five scenario action scientific repair
  scope: scenario_action agent/staging artifacts and tests
  depends_on: BIG5-CANONICAL-PROFILES-SCIENTIFIC-REPAIR-01

- id: BIG5-CONTENT-ASSET-SCIENTIFIC-REPAIR-FULL-QA-01
  title: Big Five content asset scientific repair full QA
  scope: qa artifacts/tests only
  depends_on: all repair PRs above
```

## fap-web follow-up entry

```yaml
- id: BIG5-RESULT-PAGE-RENDERER-HYGIENE-FOLLOWUP-01
  repo: fap-web
  title: BIG5-RESULT-PAGE-RENDERER-HYGIENE-FOLLOWUP-01
  scope: renderer hygiene only, no frontend editorial copy
  depends_on: BIG5-CONTENT-ASSET-SCIENTIFIC-REPAIR-FULL-QA-01
```
