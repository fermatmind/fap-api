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
- `--cn-proxy-public-owner-plan=` optional reviewed CN proxy public-owner plan.

## Partitions

- `already_public_baseline`: slugs already accepted in the 800 closeout.
- `canonical_rollout_candidate`: non-baseline rows that are not CN proxy rows, not manual hold rows, and have occupation evidence when entity context is supplied.
- `occupation_missing_remediation`: rows that cannot enter candidate prep until occupation entity evidence exists.
- `cn_proxy_policy_asset`: CN proxy rows, including `cn-*`, `public_cn_proxy_page`, `public_cn_proxy_page_candidate`, `CN_proxy_hold`, or `blocked_until_CN_authority_policy`.
- `software_manual_hold`: `software-developers` manual hold.

## CN Proxy Public-Owner Authority

The final 2786 partition can consume a reviewed CN proxy public-owner plan. This
plan does not convert CN proxy rows into canonical rollout candidates. It only
lets the partition account for those rows as a separate noindex public-owner
partition when all of these guards pass:

- `status=validated`
- `dry_run=true`
- `did_write=false`
- reviewed trust manifest is complete
- public owner plan is ready
- route owner and public route exposure remain disabled
- public pages exposed count is zero
- noindex is the default
- index, sitemap, llms, and llms-full CN counts are zero
- blockers are empty

When accepted, the output includes:

- `cn_proxy_public_owner_plan_count`
- `cn_proxy_policy_asset_unresolved_count`
- `final_public_accounted_total`
- `final_public_shortfall`
- `final_public_can_reach_target`

If the plan resolves all CN proxy policy assets, the next actions no longer
include `CN_PROXY_AUTHORITY_POLICY_DECISION_1`. Other partitions, such as
`software_manual_hold`, remain independent blockers until they have their own
authority decision.

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
- `readiness_pass=false` unless final public accounting reaches 2786 with all
  partition evidence accepted.

`readiness_pass=false` remains the default while occupation, CN proxy, or
manual-hold partitions are unresolved. When final accounting reaches 2786 with a
reviewed public-owner partition, readiness can pass, but canonical candidate prep
still receives only canonical rollout candidates.
