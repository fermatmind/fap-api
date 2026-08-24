# SEO-PLATFORM-02 Page Family Policy Closeout

## Result

`seo-page-family-policy.v1` is the shared, versioned, fail-closed registry for detector,
CMS lifecycle, Agent and authenticated SEO Operations reads. Its deterministic policy
hash is `c4c8b6109b6e6bad19c5898cc0daf63f8d18d7ba5d1622f9025b5df37fd4bea9`.

The 2026-08-24 current backend/CMS/public-authority snapshot contains 2,645 localized
public authorities. Exactly 2,645 match one formal family; zero are unclassified and zero
are ambiguous. The sitemap count is an equal consumer-consistency observation and did
not create authority.

| Family | zh-CN | en | Total |
| --- | ---: | ---: | ---: |
| Tests | 9 | 9 | 18 |
| Articles / Topics | 94 | 27 | 121 |
| Career | 1,070 | 1,050 | 2,120 |
| Personality | 182 | 175 | 357 |
| Trust / Method / Help | 10 | 15 | 25 |
| Other Public | 2 | 2 | 4 |

Authority sources are Career runtime publish projection 2,092, backend CMS 515, exact
registered backend public surfaces 26, and scale catalog 12. `other_public` accepts only
registered authority types and is never a fallback.

## Fail-closed boundaries

The classifier runs the private negative-set before public matching. All 36 private path,
private entity-type and non-public-state probes are permanently excluded; public-family
leakage is zero. Zero-match and multi-match authorities enter the L0 read-only
`unclassified` queue and cannot publish, submit to search, enter a canary or expand.

Family risk ceilings are Tests L2, Articles/Topics L3, Career L3, Personality L2,
Trust/Method/Help L2 and Other Public L1. Unclassified is L0 and Private Excluded forbids
Agent operations. These ceilings only tighten the existing claim, review, CMS publication,
search-submission and `AutoApprovalPolicy` boundaries.

Locale query ownership is independent for zh-CN and en. A missing translation holds that
locale surface; it never creates a translation or hreflang target. The registry also fixes
canonical, indexability, structured-data, sitemap, llms eligibility, visible-module,
internal-link, funnel, review-cycle and canary/rollback policy per family.

## Career and URL Truth handoff

Career classification reads `CareerDirectoryAuthorityService` and the runtime publish
projection. The current localized detail authority is 2,092: 1,046 zh-CN and 1,046 en,
under `career.directory_authority.v1`. This is 26 below the 2,118 observation baseline;
the live authority changed, while no count is encoded in classification. Sitemap remains
a consumer consistency check.

The latest authenticated production closeout still reports 17 current authority-qualified
URL Truth gaps. The pre-policy closeout schema did not retain a family/locale breakdown,
so this document does not invent one. The new authenticated policy output computes that
breakdown from the shared classifier. The 17-item cohort is a read-only
`SEO-PLATFORM-05` handoff; no URL Truth, GSC, CMS or search write or repair occurred.

## Compatibility and scope

The existing authenticated URL Truth response retains all prior fields and adds a
sanitized `page_family_policy` object. The existing SEO Intelligence Ops page consumes the
same read model and exposes policy version/hash, family and locale coverage, source
distribution, Career revision, risk ceilings and the aggregated unclassified queue. It
does not expose queries, raw URLs, URL hashes, private path examples or identity data.

This task does not implement detector task #4, lifecycle task #10, expansion task #11,
`SEO-PLATFORM-03` or `SEO-PLATFORM-05`.
