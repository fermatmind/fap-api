# Next English Preview QA Instructions

## Use this preview URL after deploy

Draft ID 42:

```text
https://ops.fermatmind.com/ops/article-preview/42
```

This URL is authenticated under Ops. It is not the public candidate URL.

## Rerun task

After this PR is merged and deployed to the Ops backend, rerun:

```text
SEO-OPS-ENGLISH-PREVIEW-QA-00
```

## QA checks to perform

1. Open `https://ops.fermatmind.com/ops/article-preview/42` while logged into Ops.
2. Confirm HTTP response header includes `X-Robots-Tag: noindex, noarchive, nosnippet`.
3. Confirm `Cache-Control` includes `no-store`.
4. Confirm page meta robots is `noindex,noarchive,nosnippet`.
5. Confirm no canonical link tag is emitted.
6. Confirm no hreflang/alternate link tag is emitted.
7. Confirm no `application/ld+json` is emitted.
8. Confirm body renders from the CMS working revision.
9. Confirm visible FAQ/body content can be manually reviewed.
10. Confirm CTA/internal links do not expose private result/order/payment/take URLs.
11. Confirm public candidate remains 404 until publish:

```text
https://fermatmind.com/en/articles/why-mbti-and-holland-code-results-dont-match
```

## Decision gate

If the preview page is accessible and the safety checks pass, proceed to operator publish review.

Do not publish, index, submit, or revalidate from this task.
