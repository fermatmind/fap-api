# Platform 12A-08: scoped evidence and Mission isolation

## Delivery boundary

This release delivers gate software, not A08 activation. Council pause, generation, Mission selection and existing staging mail acknowledgement are preserved. No Mission acceptance, natural receipt, notification drain, GSC synchronization or business write is performed by this gate classification. The 28-day clock remains NOT_STARTED. Model runtime, Tool Broker and post12 business permissions remain closed.

Catalog retains all three IDs:

- `seo.platform12.daily_gsc_core_runtime`
- `seo.platform12.daily_url_truth_reconciliation`
- `seo.platform12.daily_private_policy_evidence_drift`

Their Catalog times are planned times, not enabled schedules. Existing GSC, funnel, URL Truth, weekly decision and runtime-probe schedules remain unchanged.

## Activation file v2

The existing `seo_council.activation_receipt_path` and adjacent `.sha256` file carry `seo.platform12_a08_activation.v2`. V1 can be recognized as historical evidence but never grants authorization. Missing, corrupt, legacy, SHA, public-scope, version-vector, deployment and per-Mission gaps have separate hold codes. A manual pause is presented independently of public readiness.

The file separates:

1. `validation.public_checks`: `a08_scoped_checks`, exact SHA, scope ID/version, dependency fingerprint, completed JUnit digest and explicit required tests. It covers Policy, privacy, authentication/RBAC, transactional persistence, shared-cache control, lease/fencing, idempotency, pause and write guards.
2. `missions[formal ID].checks` and `.source_acceptance`: focused code tests never imply live wiring. Source acceptance is production `controlled_source_acceptance`, scoped to one Mission with SHA, receipt/artifact digest, fingerprint and version vector. Missing real source evidence stays pending.
3. `validation.ci`, `.staging`, `.production`: the candidate's successful CI and completed deployment jobs, verified artifact digests, smoke and read-only Operations/state checks. The running Deploy workflow is never represented as already completed.

Evidence installation writes only the existing activation file pair. It does not call runtime pause/resume or change shared-cache state. The final job in `deploy.yml` archives the installed bytes and before/after state in an immutable exact-SHA artifact.

## Admission stages

`seo:council-runtime status|pause|resume` remains the single operational command. Future `resume` requires repeated `--mission=<formal ID>` options specifying the **complete target set**. Empty, unknown, duplicate and wildcard values fail closed. All selected Missions must have scoped code readiness; the set updates atomically, or remains unchanged.

Selection with code readiness permits only explicitly requested controlled acceptance while unpaused. Natural scheduling additionally requires verified real source acceptance and an explicit resume that binds that source receipt. Installing newly passing evidence alone cannot promote a selected Mission into natural scheduling. The first natural enablement timestamp is saved per Mission when that explicit selection occurs. Pause retains the selection, source bindings, original timestamps and evidence.

Scheduler reservation/claim/recovery/terminal commit, Orchestrator admission, frozen generation, Council persistence and notification dispatch check the same boundary. Existing serial lease, fencing, transaction and replay controls remain in force. Each Mission advances its own cursor; pre-enable slots are excluded. Disabled or obsolete-generation deliveries are retained and excluded from active recovery selection.

## Dependency and Nightly policy

The explicit common/Mission path lists and fingerprint implementation live in `.github/trunk/seo-platform-12a08-activation.mjs`. Common framework, authentication, permissions, database/cache, Council and privacy changes require common revalidation. Mission evaluator/source changes require the affected Mission. Ordinary copy outside identity/authority/schema/contracts is observation data, not a blanket runtime dependency.

Inherited evidence requires actual Git ancestry, identical common and Mission fingerprints and the same runtime version vector. Every descendant still needs its own CI and deployment smoke. Evidence of source acceptance is carried only within that proven boundary. V1 cannot be carried into v2 authorization.

Nightly remains independent. `daily_operations`, `weekly_full_checks` and `a08_scoped_checks` remain distinct. Known full-regression failures are accepted only when the current candidate's completed focused tests cover the failed boundary. The old global prohibition of `runInBackground()` and old direct hook assertion are checked through the current per-command scheduling and intervening database guard contract. Unknown failure relevance or other unresolved high-risk domains remain a hold, never a silent exclusion. Absence of an historical full Nightly pass is not itself an activation prerequisite.

## Notifications and Operations

Outbox eligibility uses the existing `council:daily-terminal` evidence hash, the committed delivery and a verified terminal receipt to prove Mission ownership. Unknown historical ownership remains unsent without deletion. Unselected events do not consume the claim head or block eligible work. Dispatch rechecks selection and generation under the same control lock; ambiguous transport acknowledgements retain the existing no-blind-retry rule.

System Health separately shows public scoped readiness, pause, selection, acceptance/source readiness, effective run permission, missing evidence and the next step. Disabled schedules show “planned time; not enabled.” No execution button or new page is added; existing RBAC, sanitization and bounded queries are retained.

## Next task

Mission 1 independent read-only source wiring acceptance and trial operation. Supplement only its real source evidence, then explicitly select Mission 1 when authorized. Missions 2 and 3 remain closed. This software release does not declare A08 complete.
