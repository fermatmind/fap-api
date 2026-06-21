# SEO Agent Auto Approval Policy

`seo-agent-auto-approval-policy.v1` defines the shared low-risk approval contract for the FermatMind SEO Agent L5-A path.

The policy does not write CMS rows, publish pages, enqueue Search Channel rows, submit IndexNow, call Google Indexing, start schedulers, or run queue workers. It only classifies sanitized opportunity candidates into `auto_approved` or `blocked`.

## Low-Risk Allow List

- Source families: `cms_tdk_gap`, `cms_faq_gap`.
- Target models: `article` and `content_page` for CMS draft write.
- Publish target: `content_page` only.
- Severities: `p1`, `p2`.
- Target fields: `seo_title`, `seo_description`, `canonical_path`, `is_indexable`, `faq_items`, `schema_enabled`.
- Compatibility aliases accepted by the policy: `canonical_url_or_path`, `is_indexable_or_robots`, `faq_schema_eligible`.

## Blocking Rules

- Runtime SEO QA candidates require technical review.
- GSC performance candidates require manual review in the L5-A auto-approval path.
- Article candidates can be auto-approved for draft write only; article auto-publish is blocked.
- Full URLs, raw query/url fields, raw body fields, credential fields, tokens, cookies, metadata payloads, and service-account material block approval.
- Diagnostic, cure, guarantee, official endorsement, clinically proven, hiring-fit, medical-advice, or treatment claims block approval.

## Output Contract

Every candidate decision includes:

- `approval_decision`
- `risk_tier`
- `allowed_next_actions`
- `blocked_actions`
- `reason_codes`
- `normalized_target_fields`

The first L5-A orchestration PRs must consume this contract before automatic draft write, automatic ContentPage canary publish, or automatic IndexNow submission.
