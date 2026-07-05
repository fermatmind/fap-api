# IQ Method Pages Production Authorization Request

Packet: `IQ-METHOD-PAGES-PRODUCTION-AUTHORIZATION-REQUEST-2026-07-05`

Created at: `2026-07-05T14:20:55+08:00`

Status: `blocked_until_exact_deployed_sha_and_operator_approval`

## Current SHA Observation

GitHub was checked before creating this packet.

| Field | Value |
|---|---|
| latest `origin/main` SHA | `594b5894deb3f58c1ae8f2eac2d5566f3b5488e1` |
| latest observed production deployment SHA | `d73366e9467fab5ad3dcf2fb16e3d50520ed38ca` |
| latest production deployment timestamp | `2026-07-05T05:52:00Z` |
| latest main deploy workflow observed | `Deploy Application` in progress for `594b5894deb3f58c1ae8f2eac2d5566f3b5488e1` |
| deployed SHA matches latest main | `false` |

Decision: production writes are blocked at this time.

## Why This Is Blocked

- The newest review packet from PR #2727 is on latest main, but production was observed at an older SHA.
- IQ-specific publish gate, review approval, publish, post-publish readback, and SEO/GEO activation workflows are registered in the train, but not yet implemented.
- Codex cannot grant production write authorization on behalf of the operator.

## Current Deployed SHA Safe Scope

Exact observed production SHA:

```text
d73366e9467fab5ad3dcf2fb16e3d50520ed38ca
```

Allowed without additional operator write approval:

```bash
git -C /var/www/fap-api/current rev-parse HEAD
cd /var/www/fap-api/current/backend && php artisan list articles --no-ansi | rg 'import-iq-method-pages-draft|iq-method-pages-readback|publish-controlled|discoverability-release'
```

Dry-run only:

```bash
cd /var/www/fap-api/current/backend && php artisan articles:import-iq-method-pages-draft --package=/path/to/approved/iq-method-pages-zh-cn-v0.2 --dry-run --json
```

Readback only, after draft rows already exist:

```bash
cd /var/www/fap-api/current/backend && php artisan articles:iq-method-pages-readback --package=/path/to/approved/iq-method-pages-zh-cn-v0.2 --artifact-dir=/path/to/artifacts --json
```

These commands are no-write or read-only. They do not approve production mutation.

## Explicitly Not Authorized

- production draft import execute
- review approval write
- article publish
- indexability flip
- sitemap eligibility release
- `llms.txt` / `llms-full.txt` eligibility release
- search submission
- staging deploy wait
- production deploy

## Future Exact Authorization Phrases

Use only one phase at a time.

### Phase 1: Production Draft Import Execute

Allowed only after:

- production deployed SHA is rechecked
- the deployed SHA exactly matches the operator-approved SHA
- package path and hash are approved
- production dry-run passes on the same deployed SHA

Template:

```text
I explicitly authorize IQ method pages production draft import execute on fap-api production SHA <DEPLOYED_SHA> using package <PACKAGE_PATH_OR_HASH>; scope is draft-only CMS Article/topic/landing block writes, no publish, no indexability, no sitemap, no llms, no search, no deploy.
```

Command shape:

```bash
cd /var/www/fap-api/current/backend && php artisan articles:import-iq-method-pages-draft --package=<APPROVED_PACKAGE_PATH> --json
```

### Phase 2: Review Approval Execute

Blocked until `IQ-METHOD-PAGES-ZH-CN-CMS-REVIEW-APPROVAL-01` is implemented, merged, deployed, and dry-run passes.

Template:

```text
I explicitly authorize IQ method pages review approval execute on fap-api production SHA <DEPLOYED_SHA> for locked article ids <IDS> and revision ids <REVISION_IDS> using review packet <PACKET_ID>; no publish, no indexability, no sitemap, no llms, no search, no deploy.
```

### Phase 3: Publish Execute

Blocked until review approval is written and read back.

If the generic controlled publish command is used, the command shape is:

```bash
cd /var/www/fap-api/current/backend && php artisan articles:publish-controlled --article=<ID> --article=<ID> --confirm='<EXACT_CONFIRMATION>' --json
```

Do not pass `--make-indexable` for IQ method pages during publish. Sitemap and llms eligibility must remain disabled until the SEO/GEO activation train.

### Phase 4: SEO/GEO Activation Execute

Blocked until:

- controlled publish completes
- post-publish readback passes
- SEO/GEO activation gate passes
- exact deployed SHA is rechecked

If the generic discoverability command is used, the command shape is one article at a time:

```bash
cd /var/www/fap-api/current/backend && php artisan articles:discoverability-release --article-id=<ID> --expected-slug=<SLUG> --execute --no-content-change --no-publish --no-search --no-schema-hreflang --no-revalidation --confirm='<EXACT_CONFIRMATION>' --json
```

## Global Holds

- Do not run any production write against a SHA that does not exactly match the operator-approved deployed SHA.
- Do not run production import execute before dry-run passes on the same deployed SHA.
- Do not approve revisions before the review approval workflow exists and locks article/revision IDs.
- Do not publish before review approval has been written and read back.
- Do not make articles indexable during publish.
- Do not activate sitemap or llms until post-publish readback and SEO/GEO activation gate pass.
- Do not run search submission in the same operation.
- Do not trigger production deploy from this authorization request.

## Operator Action Required

Before any production write, the operator must provide an exact authorization phrase containing:

- `environment=production`
- exact deployed SHA
- phase
- allowed command
- source package path/hash or article/revision locks
- explicit no-publish/no-index/no-sitemap/no-llms/no-search/no-deploy holds where applicable
