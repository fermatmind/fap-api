# Next Deploy And Preview QA Rerun Instructions

After this PR is merged:

1. Run backend deploy readiness for the merge commit.
2. Deploy the exact merged SHA only after explicit operator approval.
3. Do not publish or mutate CMS during deploy readiness or deploy.
4. After production deploy, rerun `SEO-OPS-NEW-BILINGUAL-ARTICLE-PAIR-PREVIEW-QA-00` for:
   - zh article_id: 46
   - en article_id: 47
5. Confirm production preview response now has `Cache-Control: no-store, private`.
6. Proceed to operator publish review only if preview QA returns `GO_FOR_OPERATOR_PUBLISH_REVIEW`.

Still forbidden before separate explicit approval:
- publish
- indexable
- sitemap eligible
- llms eligible
- schema/hreflang enablement
- GSC/Baidu/IndexNow/Search Channel submission
- ISR/revalidation/cache invalidation outside normal backend deploy behavior
