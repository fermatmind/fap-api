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
- `risk_flags`
- `needs_human_approval`
- `execution_permission=false`

Candidates with complete evidence and severity `p1` or `p2` may be recommended for `cms_draft_package_dry_run`. Candidates with severity `p3`, incomplete evidence, or unsupported shape are deferred.
