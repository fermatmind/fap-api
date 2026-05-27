# Product-visible Career claim authority

Career public-resolution accounting and product-visible publication are separate claims.

For the `detail_ready_1048` program, detail-ready candidate accounting may prove that 1048 slugs are eligible for publication planning. It does not prove that all 1048 are visible in the public career directory, jobs API, sitemap, `llms`, or detail pages.

For the final `2786` Career program, partition accounting may prove that every source row has a governed resolution. It does not prove that every source row is visible in the public career directory or has a viewable detail page.

## Claim scopes

`partition_accounted_not_visible_detail` means:

- the 2786 source set has been accounted for through canonical rollout, CN proxy public-owner, manual-hold, or another governed partition;
- the artifact may describe assets as accounted or resolved;
- it must not describe the product as having 2786 visible directory entries, 2786 viewable detail pages, or 2786 detail-indexable pages.

`product_visible_detail_publication` means:

- public career directory `member_count` equals the target total;
- career jobs index item count equals the target total;
- detail-ready and `public_detail_indexable_count` equal the target total;
- published locale rows and release-gate pass rows equal `target_public_total * locale_count`.
- sitemap, `llms`, and `llms-full` have zero noindex, 404, or redirect-source URLs.

Only `product_visible_detail_publication` may support a product claim that the target occupations are visible with detail pages.

## Runtime artifact field

`CareerFullVisiblePublicationGate` emits:

```json
{
  "product_claim": {
    "claim_policy_version": "career_product_visible_claim.v1",
    "visible_detail_claim_allowed": false,
    "partition_accounting_claim_allowed": true,
    "safe_claim_scope": "partition_accounted_not_visible_detail",
    "claimable_counts": {
      "directory_member_count": 1122,
      "career_jobs_item_count": 1122,
      "detail_ready_count": 808,
      "public_detail_indexable_count": 808,
      "found_published_locale_rows": 2244,
      "release_gate_pass_count": 2244,
      "partition_accounting_total": 2786
    },
    "blocked_claims": [
      "2786_visible_directory_members",
      "2786_visible_detail_pages",
      "2786_detail_indexable_pages"
    ]
  }
}
```

Downstream closeout, release notes, and product copy should use `safe_claim_scope` and `claimable_counts` rather than inferring visible publication from `final_public_accounted_total`.

## Non-goals

This policy does not publish missing pages, generate occupation content, alter CN proxy policy, weaken software manual-hold policy, deploy, mutate production data, or move Career publication authority into fap-web.
