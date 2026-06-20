# SEO Agent Codex Review Runner

Task: `SEO-AGENT-CODEX-REVIEW-RUNNER-01`

This command converts a sanitized `seo-agent-codex-review-handoff.v1` artifact into a deterministic review verdict. It does not call an external model, write CMS records, write databases, enqueue Search Channel records, submit indexing requests, or activate scheduler jobs.

## Command

```bash
php artisan seo-agent:codex-review-runner --handoff=<path> --artifact-dir=<dir> --json
```

## Output

The command writes `seo-agent-codex-review-verdict.v1` with:

- `candidate_verdicts[]`
- `worth_optimizing`
- `recommended_action`
- `source_family`
- `review_reason`
- `risk_flags`
- `needs_human_approval`
- `execution_permission=false`

Candidates with complete evidence and severity `p1` or `p2` are mapped by source family:

- `cms_tdk_gap`: `cms_draft_package_dry_run`
- `cms_faq_gap`: `cms_draft_package_dry_run`
- `runtime_seo_qa` canonical/noindex/robots/redirect/status issues: `technical_review_required`
- GSC-only candidates without an article/content page target: `defer`

Candidates with severity `p3`, incomplete evidence, or unsupported shape are deferred. Every verdict remains review-only and requires human approval before any later draft or write step.
