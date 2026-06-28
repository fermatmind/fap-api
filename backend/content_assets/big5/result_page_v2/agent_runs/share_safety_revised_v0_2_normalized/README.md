# Big Five Share Safety Candidate Normalize v0.2

This directory contains normalized candidate artifacts for `BIG5-SHARE-SAFETY-CANDIDATE-NORMALIZE-01`. It converts `/Users/rainie/Desktop/大五人格-第五板块.zip` into backend agent-readable candidate JSONL.

- Runtime use: `staging_only`
- Production use allowed: `false`
- Ready for pilot/runtime/production: `false`
- Staging import: deferred
- Frontend/CMS/SEO/runtime changes: none

Validation command:

```bash
APP_ENV=testing php artisan big5:result-page-v2-agent stage-candidates --run-id=share-safety-revised-v0-2-normalized-validation --artifact-dir=/tmp/big5-share-safety-v02-normalized-validation --candidate-dir=content_assets/big5/result_page_v2/agent_runs/share_safety_revised_v0_2_normalized --json --no-ansi
```
