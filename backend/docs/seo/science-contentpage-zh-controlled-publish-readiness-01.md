# Science zh content pages controlled publish readiness 01

This readiness item enables a fail-closed controlled publish runtime for the five missing Chinese science content pages:

- `/zh/science`
- `/zh/item-design-notes`
- `/zh/reliability-validity`
- `/zh/data-privacy`
- `/zh/common-misconceptions`

The runtime scope is `science-zh`. It publishes only existing CMS `content_pages` records imported from `science-contentpage-gpt55-review-draft-2026-06-08/pages/`; it does not create, upsert, or mutate out-of-scope records.

## Publish boundary

This PR does not execute production CMS writes. It only adds the controlled runtime, tests, and readiness artifact.

The production command remains gated by deployment plus explicit operator approval:

```bash
php artisan content-pages:publish-controlled --scope=science-zh --locale=zh-CN --keys=science,item-design-notes,reliability-validity,data-privacy,common-misconceptions --execute --json
```

## Safety decisions

- `method-boundaries` is protected and out of scope.
- All five target pages remain `is_indexable=false`.
- Sitemap, llms, footer, and search submission stay disabled in this backend PR.
- The command sets first-class public readiness fields before publish: `publish_allowed=true`, `review_state=approved`, `legal_review_required=false`, `science_review_required=false`, `claim_gate_status=passed`, `forbidden_claims=[]`, and `operator_approved_at`.
- The command blocks target content that references private result/order/share/pay/history URLs or sensitive query parameters.

## Follow-up

After backend deployment and production controlled publish execution, run a live 200 smoke for all five Chinese routes. Only then should fap-web restore and verify the Chinese footer links.
