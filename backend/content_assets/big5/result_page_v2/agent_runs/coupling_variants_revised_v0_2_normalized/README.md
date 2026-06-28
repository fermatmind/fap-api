# Big Five Coupling Variants Revised v0.2 Normalized Candidates

Task: `BIG5-COUPLING-VARIANTS-CANDIDATE-NORMALIZE-01`

This artifact converts `大五人格-第四板块.zip` into backend agent-readable candidate JSONL for Big Five V2 coupling variants. It is a candidate artifact only. It does not import staging assets, generate a final `big5_result_page_v2` payload, change runtime, change production rollout, touch CMS/SEO, or add fap-web copy.

## Scope

- Runtime use: `staging_only`
- Production use allowed: `false`
- Ready for pilot/runtime/production: `false`
- Candidate rows: 50 content assets + 50 selector assets
- Coverage: 10 unordered trait-pair combinations with 5 variants each
- Risk repair: `关系一定顺利` -> `关系会自然顺利`

## Validation

Run a dry-run staging validation only:

```bash
APP_ENV=testing php artisan big5:result-page-v2-agent stage-candidates --run-id=coupling-variants-revised-v0-2-normalized-validation --artifact-dir=/tmp/big5-coupling-v02-normalized-validation --candidate-dir=content_assets/big5/result_page_v2/agent_runs/coupling_variants_revised_v0_2_normalized --json --no-ansi
```

The follow-up staging import must be a separate PR.
