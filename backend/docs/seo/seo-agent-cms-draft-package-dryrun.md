# SEO Agent CMS Draft Package Dry-run

Task: `SEO-AGENT-CMS-DRAFT-PACKAGE-DRYRUN-01`

This command converts a `seo-agent-codex-review-verdict.v1` artifact into a CMS draft package dry-run artifact. It generates field-level draft briefs only. It does not write CMS records, create revisions, generate final copy, enqueue search actions, request indexing, or activate schedulers.

## Command

```bash
php artisan seo-agent:cms-draft-package-dry-run --verdict=<path> --artifact-dir=<dir> --json
```

## Output

The command writes `seo-agent-cms-draft-package-dry-run.v1` with `draft_briefs[]`. Each brief contains the target subject, safe path, gap codes, target fields, draft instructions, claim-gate requirement, and human-approval requirement.
