# CN Proxy Visible Detail Policy Authority

This document defines the backend gate for any future attempt to count CN proxy rows as product-visible Career detail pages.

## Current Authority

The reviewed CN proxy public-owner train authorizes a noindex, noncanonical owner surface only. It does not authorize:

- canonical Career job detail publication
- indexable detail pages
- sitemap URLs
- llms or llms-full entries
- generated display assets
- occupation or crosswalk creation
- inclusion in the product-visible 2786 detail-page claim

The current product-safe claim remains partition aware unless the full visible-detail policy gate passes.

## Command

Use the read-only planner:

```bash
php artisan career:plan-cn-proxy-visible-detail-policy-authority \
  --scope=/tmp/career_2786_cn_proxy_policy_scan_after_1434_slug_matrix.json \
  --public-owner-plan=/tmp/career_2786_cn_proxy_public_owner_plan.json \
  --visible-gap=/tmp/career_2786_cn_proxy_policy_scan_after_1434_summary.json \
  --decision=/tmp/career_2786_product_policy_decision_cn_proxy_visible_detail.json \
  --target-total=2786 \
  --json \
  --output=/tmp/career_2786_cn_proxy_visible_detail_policy_authority.json
```

The command never mutates the database and always reports:

- `read_only=true`
- `writes_database=false`
- `apply_allowed=false`
- `rollout_allowed=false`
- `candidate_prep_allowed=false`

## Decision Semantics

`KEEP_PARTITION_AWARE_PRODUCT_CLAIM` means CN proxy rows stay in the reviewed noindex public-owner partition. The planner may pass, but `visible_detail_publication_allowed=false` and `safe_claim_scope=partition_accounted_not_visible_detail`.

`PURSUE_CN_PROXY_VISIBLE_DETAIL_PUBLICATION` starts a separate visible-detail program. The planner must block until all CN-first publication prerequisites exist.

## Required Visible-Detail Preconditions

Visible detail publication for CN proxy rows requires all of:

- explicit product policy decision for CN proxy visible detail
- CN-first authority source evidence
- CN visible detail schema policy
- CN display asset pipeline
- CN directory inclusion gate
- CN visible live acceptance

Reviewed noindex public-owner evidence alone must never satisfy these conditions.

## Non-Goals

This gate does not:

- generate content
- create occupations
- run candidate prep
- run rollout dry-run or rollout apply
- change sitemap, llms, or frontend behavior
- publish CN proxy rows as US canonical jobs
