# Career Detail-Ready 1048 Publication Scan

`career:audit-detail-ready-1048-candidates` is a read-only scanner for the
Career detail-ready publication train.

The scan distinguishes product-visible detail-page publication from 2786
partition accounting. It does not publish jobs, mutate CMS content, run runtime
candidate preparation, run rollout, deploy, submit URLs, or generate pSEO pages.

## Authority Inputs

- Current runtime projection public detail slugs.
- Published public indexable `CareerJob` DOCX baseline rows with source evidence.
- Valid `career_job_display_assets` rows with required SOC/O*NET crosswalks.
- Compiled first-wave recommendation snapshot candidates.
- Full release ledger classification when available.

## Output Contract

The command emits `career_detail_ready_publication_candidates.v1` with:

- `current_public_30`
- `ready_not_public_1018`
- `sources.docx_ready`
- `sources.display_asset_ready`
- `sources.compiled_ready`
- `sources.union_detail_ready`
- `manual_hold`
- `ledger_classification`
- mutation guard fields: `writes_database=false`, `apply_allowed=false`,
  `rollout_allowed=false`, `deploy_allowed=false`

The output is an audit artifact only. Later PRs must define target authority,
candidate preparation, artifact refresh, rollout gate, and live acceptance before
any production apply or deploy.

## Example

```bash
php artisan career:audit-detail-ready-1048-candidates \
  --json \
  --output=/tmp/career-detail-ready-1048-scan.json
```
