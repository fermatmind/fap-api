# MBTI64 Internal Link Graph

## Summary
- Status: `pass`
- Total MBTI64 pages: 96
- Variant pages: 64
- Comparison pages: 32
- Pilot URLs: 8
- Recommended edges: 208
- Blocked edges: 96
- Low-inbound or missing static graph candidates: 96

## Scope Boundary
- Artifact-only; no CMS writes, no frontend runtime changes, no publish/index/search release.
- Same-locale body links only; hreflang alternates are not counted as body internal links.
- Existing runtime outgoing links are not crawled in this generator.

## Edge Rules
- Variant A/T sibling pages should link bidirectionally.
- Variant pages should link to the matching A-vs-T comparison page.
- Comparison pages should link back to both A and T variant pages.
- V2.1 related-test links are allowed only when the route is URL-Truth present and marked safe public.
- Private result/order/payment/account routes are blocked.

## Blockers
- None.

## Next Recommended Task
- `MBTI64-CMS-INTERNAL-LINK-DRAFT-01`: convert this graph into CMS draft/revision internal_links only after explicit approval.
