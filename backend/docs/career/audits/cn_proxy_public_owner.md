# CN Proxy Public Owner Gate

The CN proxy public owner gate is a read-only validation step for the non-canonical CN proxy partition in the Career 2786 release.

CN proxy rows are not canonical rollout candidates. They must not enter candidate preparation, canonical rollout, sitemap generation, llms, llms-full, or US canonical job schema paths.

## Inputs

- Phase 2C CN proxy scope artifact.
- Optional reviewed CN proxy trust manifest artifact.

The reviewed trust manifest must include one claim per CN proxy row with evidence, reviewer, reviewed timestamp, disclaimer, rollback condition, and non-indexable policy fields.

## Output

`career:validate-cn-proxy-public-owner` emits a JSON validation payload. With no reviewed manifest it preserves the disabled owner state:

- `public_owner_plan_ready=false`
- `route_owner_enabled=false`
- `public_route_allowed=false`
- `public_pages_exposed=0`
- `noindex_default=true`

With a complete reviewed manifest it may mark the separate owner plan ready:

- `reviewed_trust_manifest_complete=true`
- `public_owner_plan_ready=true`
- `guarded_public_owner_state=reviewed_noindex_public_cn_proxy_page_ready_for_separate_owner_train`

The command still keeps public route exposure disabled and reports zero sitemap, llms, llms-full, occupation, crosswalk, and display-asset deltas.

## Blockers

The gate blocks when:

- the CN proxy row count is not 1663,
- non-CN rows are present,
- the source scope already marks CN proxy rows as public candidates,
- the reviewed manifest is incomplete,
- reviewer or reviewed timestamp is missing,
- evidence, disclaimer, or rollback condition is missing,
- any claim is public eligible, indexable, sitemap eligible, llms eligible, or llms-full eligible.

## Non-goals

- No DB mutation.
- No deploy.
- No rollout or rollout dry-run.
- No candidate prep.
- No public route exposure.
- No canonical job schema for CN proxy rows.
- No sitemap, llms, or llms-full eligibility.
