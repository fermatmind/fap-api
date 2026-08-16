# AGENTS.md — Repository + Codex Working Contract (MUST FOLLOW)

> This file is binding for any agent or Codex work in this repository.

## Trunk-based zero-touch delivery

- Start ordinary work in a clean isolated worktree created from the latest `origin/main`; never edit, clean, switch, or reuse the operator's existing workspace or uncommitted files.
- Deliver one small, demonstrable, reversible loop at a time. Run focused validation, commit intentionally, and publish with `git push origin HEAD:main`. Do not create an ordinary branch, pull request, approval phrase, operation-specific workflow, or sidecar.
- If a concurrent main update rejects the push, fetch and rebase onto the new `origin/main`, rerun the affected checks, and push again. Force-push and non-fast-forward updates are forbidden.
- Follow the pushed exact SHA through `ci.yml` and `deploy.yml`. A commit is complete only when its applicable exact-SHA CI receipt and deployment outcome are known.
- A failed SHA stays out of production. Diagnose it and publish a new corrective commit; do not rerun or mutate the failed SHA in place.
- The repository has four permanent workflow entrypoints: `ci.yml`, `deploy.yml`, `nightly.yml`, and `recovery.yml`. `recovery.yml` is the only manual entrypoint.
- New delivery behavior must extend the path classifier and the existing four-workflow control plane. Task-specific or manual delivery workflows are prohibited.
- Main protection must continue to prohibit deletion and non-fast-forward updates. Ordinary push admission is not a deployment verdict; exact-SHA CI, staging, production activation, and smoke evidence decide production eligibility.
- Record push, CI, staging, production, and smoke timing when those phases apply so solo-owner lead time remains observable.
- Historical branch, PR-train, required-check, approval, and retired workflow records are ignored for ordinary work unless the task explicitly asks for historical audit.

## Solo-owner maximum efficiency

- FermatMind is developed and operated by one person. Choose the shortest safe end-to-end path and minimize branches, approvals, handoffs, duplicate artifacts, repeated validation, waiting, and operator interruptions.
- Combine steps that form one coherent, reversible, in-scope loop. Do not expand a small task into platform work, a broad refactor, a general control plane, or adjacent cleanup.
- Preserve security, production data, secrets, permissions, destructive-operation, discoverability, and recovery boundaries. These boundaries constrain the risky action only; they do not add ceremony to unrelated work.
- Begin by reading the applicable rules, real callers, existing implementation, and minimum acceptance condition, then execute. Prefer focused tests, lint/static checks, formatting, classifier checks, and `git diff --check`; reserve complete heavy suites for `nightly.yml` or a genuinely affected high-risk boundary.

## Delivery risk lanes

- **Fast lane:** documentation, rules, tests-only, dependency updates, and small low-risk fixes. Run focused CI. Documentation/rules/tests-only commits must produce a deploy-skip receipt and must not enter staging or production.
- **Product lane:** user-visible APIs and non-payment product flows. Deliver one end-to-end loop; require focused tests, static checks, build or contract checks selected by the classifier, then automatic staging and production.
- **Controlled lane:** migrations, payments, security, permissions, content authority, cache activation, and SEO/discoverability. Add the classifier-selected fail-closed checks and receipts to the same commit flow. Do not spread these controls to unrelated paths.

## Working contract

### Scope discipline

- Advance exactly one demonstrable loop per commit and keep unrelated files out of the diff.
- Do not break `backend/scripts/ci_verify_mbti.sh` coverage. Run it locally only when the changed runtime boundary, a high-risk rule, or an explicit request requires it; the heavy complete chain otherwise belongs to Nightly.
- Rules-only, documentation-only, generated-contract-only, and read-only work must not fabricate runtime steps.

### Goal execution authorization

- FermatMind is a solo-developed project. At all times, treat a concrete end-to-end execution goal as continuous authorization for safe, reversible, in-scope work. This authorization does not depend on time of day or unattended execution.
- Continue through isolated worktree creation, scoped edits, focused checks, commit, direct push, exact-SHA CI/deploy tracking, same-scope corrective commits, and cleanup without returning ordinary coordination to the operator.
- Stop only when scope or authority is materially ambiguous, user changes cannot be isolated, external permission is unavailable, or the task requires a separately controlled destructive action, secret/permission change, production data mutation that cannot prove compatibility, discoverability write outside the automatic lane, or manual recovery.
- Infrastructure or product failures proven unrelated to the current scope are evidence to report, not authorization to create an adjacent repair.

### Fixed change order when applicable

When the current implementation touches these runtime layers, change only the touched layers and preserve this order:

1. `backend/routes/api.php`
2. `backend/database/migrations/*`
3. `backend/app/Http/Middleware/FmTokenAuth.php` — database lookup and `fm_user_id` injection remain mandatory
4. Controller and service layers
5. Scripts or CI changes last, only when needed

Provide a relevant focused verification command for each touched layer. Do not invent commands for untouched layers.

### Verification and reporting

- Run focused PHPUnit coverage for changed behavior, Pint or the relevant static check for touched files, classifier/workflow contract checks when delivery rules change, and `git diff --check`.
- Run `php artisan route:list` only for route wiring, a fresh SQLite migration only for schema work, and HTTP acceptance only for an affected HTTP surface.
- Before commit and push, verify the diff contains only the declared scope. After push, bind all status checks and receipts to the exact commit SHA.
- When files change, report Added and Modified paths separately and list only checks actually run. State when runtime commands are not applicable.

## Exact-SHA CI and deployment contract

- `ci.yml` handles only `main` pushes. It classifies the exact `github.event.before` to `github.sha` range, refuses an indeterminate or non-forward baseline, runs the union of checks for mixed scopes, and emits an immutable exact-SHA receipt.
- `deploy.yml` consumes only a successful CI result for the same SHA. It serializes staging, staging smoke, production activation, and production smoke without allowing a newer commit to overtake an activating release.
- Documentation/rules/tests-only commits stop after a successful deploy-skip receipt. They do not deploy application code.
- Staging failure leaves production unchanged. Pre-activation failure leaves the current release active. Post-activation smoke failure atomically restores the previous healthy release and process state.
- Production always follows the latest successfully verified and accepted SHA, not necessarily the newest commit on `main`.
- Backward-compatible expand migrations may run automatically only after compatibility proof. Destructive or unprovably compatible schema changes fail before apply and must be rewritten as expand/contract; old structures remain for at least seven days and two successful production versions before automated cleanup.
- Payment changes must pass contract, idempotency, signature, failure-path, sandbox, and bounded production smoke checks. Cache changes use changed-key candidate creation, validation, and atomic activation; failure retains LKG.
- SEO/discoverability actions require an actual URL-set or metadata-surface change, exact diff validation, post-deploy acceptance, and only then the allowed search submission. Body-only changes must not trigger search submission.
- `nightly.yml` owns complete heavy tests, security scans, full content consistency, dependency, and performance checks. Its failure provides diagnostics but does not roll back or block a healthy production release.

## Recovery only

- `recovery.yml` is reserved for a real production incident after automatic LKG restoration has failed. It may switch to LKG, restore an exact known SHA, or run the minimum necessary diagnosis.
- Recovery credentials live only in the recovery Environment. Ordinary CI and deployment must not use them.
- Recovery is not a daily release, content, migration, payment, SEO, cache-refresh, verification, or retry path. Never use it to bypass an exact-SHA failure.

## Repository context

- `backend/` contains the Laravel API. Important areas are `app/`, `routes/`, `config/`, `database/`, `resources/`, and `tests/`.
- `content_packages/` holds MBTI content assets; `default/` is active and `_deprecated/` is archival.
- `docs/`, `00-plan/`, `01-api-design/`, `02-db-design/`, `03-env-config/`, and `04-analytics/` hold specifications and operational evidence. `tools/` contains helper scripts.
- PHP follows PSR-12 and Laravel conventions. Use four-space indentation, LF, trailing-whitespace cleanup, and Pint for changed PHP files.
- Commit messages use `type(scope): summary`.

## Runtime and authority boundaries

### Health, smoke, and release evidence

- Keep protected health surfaces distinct: target-node loopback `/api/healthz` requires `200` with `ok=true`; non-allowlisted public `/api/healthz` must remain exactly `404`.
- Public reachability is proven through the flags API and the zh-CN Big Five Hub Personality API. The Personality response must expose a valid 64-character `source_hash`.
- Public scale lookup smoke may retry only through `backend/scripts/deploy/verify_scale_lookup.sh` with repository-bounded attempts and timeouts. Persistent transport failure, malformed JSON, `ok != true`, or a mismatched `primary_slug` fails closed.
- Deployment evidence must be immutable, sanitized, bound to the exact repository/SHA/run/attempt, and must never expose private topology, raw response bodies, secrets, or remote paths.

### Cache and projection consistency

- Derived caches are not content authority. Publish cache candidates through validate-then-atomic-activate semantics and retain a readable LKG.
- Sitemap and Career warming are bounded derived-cache operations. Their failure must not corrupt active pointers, expose raw output, or silently replace authoritative validation.
- Anonymous org-0 Big Five and Enneagram collection reads must verify stable fence, pointer, payload schema, and current projection discriminator. Valid active reads are lock-free; only publication, invalidation, and fence rotation may take write locks.
- Database or projection failure may use only a stable, current-discriminator LKG payload. Otherwise fail closed.

### Database, payments, and permissions

- Never auto-reverse a migration. Automatic schema evolution is expand-first and must preserve application rollback compatibility.
- Payment, order, subscription, receipt, refund, entitlement, webhook, signature, and idempotency invariants are security boundaries and require their focused contract coverage.
- Secrets, SSH identities, sudo policy, repository permissions, Environment credentials, and production routing values must remain least-privilege, masked, and outside repository output.
- Production MFA and approval records remain tenant-bound operational authority. Generic code deployment must not fabricate, bypass, or mutate those records.

### CMS and content authority

- Backend CMS/database authority, repository content packages, public projections, and derived caches are distinct. A deploy or cache warm must not silently become publication authority.
- Exact-package content promotion must validate manifest, digests, schema, locale, readback, public QA, and rollback compatibility. Transaction failure rolls back the whole write and leaves the cache pointer unchanged.
- Generic content publication is prohibited. Only the explicitly scoped, classifier-governed content path may write the exact validated package; it must not broaden into discoverability actions unless the URL or search surface actually changed.
- Big Five and Enneagram description-only edits are text-only authority changes: preserve identity, score topology, assignments, relations, slugs, aliases, and public structure. Historical aliases are redirect-only and must never appear in canonical URLs, sitemap inventory, or alternate-link output.
- `public_topic_edges` is the sole runtime authority for public topic-to-hub edges. Publication must validate endpoint eligibility, normalized locale, relation type, sort order, and the exact approved scope before commit.
- DailyGiving share-proof work must keep proof tokens non-PII and non-secret, use the documented hash identity, keep proof routes noindex, and avoid implying that proof is content publication or discoverability authority.
- Chinese private RIASEC result copy has one logical repository authority: the source set declared by `backend/content_assets/riasec/private_result_authority_manifest.json`. Runtime payloads, report/PDF/history/compare/share surfaces, and tests must consume that source set or `compiled/private_result.compiled.json`; PHP/TypeScript literals, QA freezes, snapshots, and fixtures are never competing copy authority. Compilation must fail closed on missing files, schema/locale/fallback violations, or incomplete R/I/A/S/E/C, pair, Top3, 60Q/140Q, quality, and secondary-surface coverage. Generated manifest/artifact files are compiler-owned and must not be edited manually.

## Historical only — not ordinary delivery

- PR-train ledgers, branch names, review records, required-status receipts, approval phrases, retired workflow names, exact one-off SHA exceptions, and old content-package credentials are audit history only.
- They apply only when a task explicitly asks to investigate or preserve that historical event. They never authorize an ordinary branch, PR, manual dispatch, production mutation, retry, rollback, or new exception.
- Historical details remain available in Git history and repository evidence. Do not copy them into active delivery rules or revive them as a control plane.
