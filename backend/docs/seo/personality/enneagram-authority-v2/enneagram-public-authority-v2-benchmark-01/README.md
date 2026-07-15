# Enneagram Public Authority V2 Benchmark 01

This packet freezes the read-only production and competitor benchmark captured at `2026-07-15T01:12:16.926Z`.

## Scope

- 58 Enneagram identities and 116 bilingual public pages:
  - hub: 2
  - centers: 6
  - core types: 18
  - wings: 36
  - instinctual subtypes: 54
- The test landing, articles, private results/reports/attempts/orders/payments/checkout, Tritype, wing × instinct combinations, and compatibility pSEO are excluded.
- Production reads used anonymous HTTP `GET` requests against the public site, public CMS API, sitemap, `llms.txt`, and `llms-full.txt` only.
- No CMS, database, cache, pointer, indexability, Search Queue, IndexNow, media, deploy, or production write occurred.

## Frozen production truth

- `production-scorecard.json` contains exactly 116 page rows and 58 unique identities.
- All 116 captured routes returned HTTP 200 without the benchmark's soft-404 markers.
- Canonical, hreflang, robots, visible depth, FAQ, internal-link, evidence, media/OG, JSON-LD, review state, revision exposure, sitemap/llms presence, and private-boundary fields are recorded per page.
- Public V1 authority returned `agent_promoted_content_ready` for 26 hub/center/core pages and `published_no_llms` for 90 wing/subtype pages.
- The public response exposes no named reviewer. Every row is therefore frozen with `reviewer = null` and `human_review_completed = false`; model/agent state is not treated as human review.
- Visible evidence was detected on 13/116 pages; 103/116 lacked a visible evidence heading at capture time.
- Media Library authority mappings were empty on 116/116 pages at capture time.

## Competitor registry boundary

`truity-url-registry.json` contains 28 canonical-deduplicated metadata-only rows discovered by a deterministic rule:

1. keep root-sitemap URLs containing `enneagram`;
2. keep the exact Enneagram guide and topic roots from both declared blog sitemap children;
3. validate the nine exact `/blog/enneagram-type/type-{one..nine}` profile candidates as the fixed search-discovery set;
4. deduplicate by canonical URL.

The registry stores only URL, title, lastmod, page/intent classification, numeric depth metrics, and claim-risk classification. It stores no competitor body corpus. Every competitor row is classified `competitor/editorial` and is ineligible as empirical evidence.

## Integrity hashes

- production scorecard SHA256: `321f7d5f42462e41cd32bd1972f6e634fbd1f2f7b44ba091e8ca05e7dd449101`
- competitor URL registry SHA256: `6cee73f9e9b51c454a2719db9ad607b4b295676706427fe788341b3928aef145`

The machine-readable values are locked in `checksums.json` and verified by the focused PHPUnit contract.
