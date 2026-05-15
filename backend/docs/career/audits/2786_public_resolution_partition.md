# Career 2786 Public Resolution Partition

`career:plan-canonical-2786-public-resolution-partition` is a read-only planner for the final 800 to 2786 phase.

The command does not treat every remaining source row as a canonical rollout candidate. It partitions the complete 2786 source plan against an accepted 800 closeout baseline and optional entity context.

## Inputs

- `--source-plan=` complete 2786 public-resolution source plan.
- `--closeout=` accepted 800 closeout artifact.
- `--current-total=800`
- `--target-total=2786`
- `--locales=en,zh`
- `--entity-context=` optional entity context with `occupation_exists=true` evidence.

## Partitions

- `already_public_baseline`: slugs already accepted in the 800 closeout.
- `canonical_rollout_candidate`: non-baseline rows that are not CN proxy rows, not manual hold rows, and have occupation evidence when entity context is supplied.
- `occupation_missing_remediation`: rows that cannot enter candidate prep until occupation entity evidence exists.
- `cn_proxy_policy_asset`: CN proxy rows, including `cn-*`, `public_cn_proxy_page`, `public_cn_proxy_page_candidate`, `CN_proxy_hold`, or `blocked_until_CN_authority_policy`.
- `software_manual_hold`: `software-developers` manual hold.

## Non-goals

- No DB mutation.
- No candidate prep apply.
- No rollout dry-run.
- No rollout apply.
- No deploy.
- No live crawl.
- No weakening of CN proxy or manual-hold rollout executor gates.

The top-level output always sets `writes_database=false`, `apply_allowed=false`, `rollout_allowed=false`, and `candidate_prep_allowed=false`.

## Output

The output schema is `career_2786_public_resolution_partition.v1`. A successful partition has:

- `status=pass`
- `partition_pass=true`
- `partition_status=partitioned`
- `readiness_pass=false`

`readiness_pass=false` is intentional. The partition is evidence for the next remediation and policy work, not permission to run final 2786 candidate prep or rollout.
