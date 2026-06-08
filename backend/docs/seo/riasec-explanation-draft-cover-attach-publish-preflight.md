# RIASEC Explanation V2 Draft Cover Attach and Publish Preflight

Task: `RIASEC-EXPLANATION-DRAFT-COVER-ATTACH-PUBLISH-PREFLIGHT-01`

Decision: **NO-GO: controlled publish preflight is still blocked by missing references.**

This task performed only the authorized CMS draft cover attachment and draft revision approval mutation, then ran controlled publish preflight in dry-run mode. It did not rewrite article body/title/H1/meta/FAQ/CTA, publish, submit search URLs, deploy, or access private result/order/share/pay/payment/history URLs.

## CMS Mutation

CMS media asset `6` was attached to both RIASEC draft articles:

| Locale | Article ID | Revision ID | Draft status | Revision status | Cover | Public | Indexable |
| --- | ---: | ---: | --- | --- | --- | --- | --- |
| zh | `40` | `45` | `draft` | `approved` | attached | `false` | `false` |
| en | `41` | `46` | `draft` | `approved` | attached | `false` | `false` |

Attached media:

- asset key: `article.riasec.explanation.cover.v1`
- cover URL: `https://api.fermatmind.com/storage/media-library/variants/articleriasecexplanationcoverv1/hero_1600x900.jpg`
- OG URL: `https://api.fermatmind.com/storage/media-library/variants/articleriasecexplanationcoverv1/og_1200x630.jpg`
- alt: `Abstract career-interest map with six work activity icons around a compass.`
- required variants present: `hero`, `card`, `thumbnail`, `og`, `preload`

The latest import gate media status for both articles is now `complete`.

## Publish Preflight

Command:

```bash
php artisan articles:publish-controlled --article=40 --article=41 --ack-claim-warning=40 --make-indexable --dry-run --json --no-ansi
```

Result: `ok=false`.

What passed in dry-run:

- zh/en working revisions are approved
- zh claim warning was acknowledged for dry-run
- media status is complete
- graph metadata is complete
- CTA slots are present
- FAQ items are present

Blocking issue:

| Article ID | Locale | Blocker |
| ---: | --- | --- |
| `40` | zh | `references_missing` |
| `41` | en | `references_missing` |

Both latest import gates still have `references_count=0`.

## Public Safety

Public article API postcheck still returns 404 for both draft articles. The articles remain non-public and non-indexable.

No sitemap, llms, search submission, or publish action was performed.

## Next Step

Do not publish yet. Reconcile accepted references into the latest import gates for articles `40` and `41`, then rerun controlled publish preflight. Controlled publish still requires a separate exact publish approval after preflight passes.
