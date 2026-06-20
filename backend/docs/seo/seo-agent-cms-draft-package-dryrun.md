# SEO Agent CMS Draft Package Dry-run

Task: `SEO-AGENT-CMS-DRAFT-PACKAGE-PROPOSAL-01`

This command converts a `seo-agent-codex-review-verdict.v1` artifact into a CMS draft package dry-run artifact. It generates field-level proposal items only. It does not write CMS records, create revisions, generate final body copy, enqueue search actions, request indexing, or activate schedulers.

## Command

```bash
php artisan seo-agent:cms-draft-package-dry-run --verdict=<path> --artifact-dir=<dir> --json
```

## Output

The command writes `seo-agent-cms-draft-package-dry-run.v1` with `draft_briefs[]` and compatible `proposal_items[]`. Each proposal contains the target subject, safe path, gap codes, target model, target fields, deterministic proposed SEO fields, draft instructions, claim-gate requirement, and human-approval requirement.

Proposal fields may include:

- `proposed_seo_title`
- `proposed_seo_description`
- `proposed_faq_items`
- `proposed_canonical_path`
- `proposed_indexability`

The proposal text is deterministic and template-based. It is not final editorial body copy, and any later CMS draft write remains separately gated by package SHA, exact confirmation phrase, and human approval.
