# Career delivery: targeted failure diagnosis

Read only the section matching an observed failure. Locate current code and tests with rg; historical names below identify the boundary, not a requirement to run every test on every release.

## Receipt transport or package scale

If jq/process startup reports Argument list too long, inspect argv transport before changing content or package limits. Large JSON inventories should be read from a file/stdin, for example jq --slurpfile or --rawfile with appropriate parsing. Preserve the receipt schema, full changed_pages, assertions, exact-SHA binding and fail-closed behavior. Verify with the real-size input and malformed/mismatched input. Do not split the complete content release to evade an argv bug.

For GitHub job-output limits, inspect the actual classification step and payload separately from argv errors. In the 1046-page release, `853a6b63` bounded transported classification outputs while retaining the exhaustive changed-page set in the immutable receipt. Do not omit selected pages or split a release merely to fit an output channel; validate the real-size transport and complete receipt using the existing workflow boundary.

## Source structure and consumer bindings

Trace the failing source through current `CareerContentV3Contract`, `CareerAuthoringStructure` and `CareerPageDisplayResolver`, then the frontend consumer. A source-contract assertion may stop at the first failure: counts by first error are file counts, not all defects or editorial-error counts. After a justified correction, inspect the newly exposed error instead of mechanically repeating the same repair.

- Array shrink/reorder, row-shape or delimiter changes must update affected mapped slots, parts and derived inventory against the intended replacement. A mapped address is not a visible consumer. Use `unfilled` only for an actually absent, legitimately unfilled position; restore new prose's valid display binding when it exists. Recomputing inventory alone does not repair an invalid reference.
- Trace raw recursive `$item`, `$fact`, `fact_ref`, `$link`, `$source`, `source_refs`, native and FAQ consumers, including protected Hero and unselected modules. Check reference type, block/copy-key, link entry/field and actual source details. Same URL does not establish the same claim or purpose; unused and duplicate consumption are different failures.
- An orphan may become `internal` only under applicable authorization and evidence of explicit historical replacement or internal purpose, with a visible current replacement and no remaining public consumers. Preserve text, facts, IDs and the replacement/history evidence. Unknown-purpose items remain unresolved; preserved bytes do not prove preserved display. Do not delete or blanket-internalize items to satisfy the contract.
- Invalid stable IDs and unsupported URLs are not implicit permission to rename IDs or change evidence. Explicitly authorized ID migration needs a collision-free mapping and all recursive references updated. Preserve pre-repair bytes and operation hashes; partially resolved files remain blocked. Do not rewrite the original frozen selection to conceal changed input.

Historical evidence lives in the asset project's `work/career-1046-live-delivery-2026-10-12/`: `source-contract-diagnostics.json` (306 first-failing files: 275 authoring, 23 body, 8 display), `recursive-consumer-and-history-audit.json`, `approved-internal-candidate-report.json`, and `blocked-source-items.json` distinguish proved replacements from rejected blanket-internal candidates. `remaining43-2026-10-13/authorized-ids-url/result.json` records the explicitly authorized 23-ID/one-URL repair; `remaining15-deep-history/resolution-result.json` under that directory and `last-body-evidence.json` record the final 18-link/five-body decisions. These are technical repair precedents, not reusable editorial PASS or new source-edit authorization.

## Contract-valid frontend variants

Source validity, frontend parsing and actual visible rendering are separate checks. Read the current source contract before tightening frontend row counts, nullability or smoke expectations. Test the affected legal variant and malformed inputs; preserve real data gaps rather than inventing values to pass a parser. Use declared display bindings and component order, not the presence of obsolete body copy-keys, to decide whether a valid component renders.

The release's fap-web `e1c9e7da` corrected legal component variants; `4d429539` subsequently restored exact additional-URL limits rather than whole-host permission. `243f950d` admitted the precise reviewed 0160 legal URL. Verify normalized HTTPS URLs, credential rejection and negative cases; null or a legal non-link label must not become a fabricated href. An allowed URL still does not prove the claim is supported.

Backend `9557d836` and frontend `c7c5490d` accepted contract-valid Hero AI `availability=missing` without fabricated scores. Frontend `53d22a4e` made fit rendering follow valid display bindings after obsolete body items became internal; its `tests/contracts/career-published-variants.contract.test.tsx` verifies visible React output. Locate current implementations before reusing these cases. Do not restore replaced prose or weaken source validation to accommodate a reader bug. A compatibility repair belongs in the authorized frontend/backend scope; independent editorial review stays paused when requested.

## Frozen fixtures and shared-input hashes

Read the failed assertion and the baseline's input set, then compare Git history. Article15 projection hashes, MBTI public-copy fingerprints and frontend PDF input hashes represent different contracts.

- A shared file such as fap-web/proxy.ts can change a PDF input digest without changing PDF copy. Inspect the actual affected routes and behavior before computing a new baseline.
- Update a frozen expected hash only after validating the intended source/implementation and preserving the original protection assertions. Never regenerate a baseline solely to turn a failing test green.
- Current fixed slugs remain independent. An old test that treats one fixed career as another career's alias must use a real historical URL outside the inventory. Preserve real legacy identity-only redirects and reject malformed Current descriptors.
- Repair a necessary same-scope failure under existing authorization. For an unrelated product boundary, describe the exact minimal repair and obtain genuinely missing scope authorization once; retain authorization and user pauses across resumes.

## Public body, SEO and URL mismatch

For Current detail, inspect CareerFilePageReader, fetchCareerJobBundle, the page metadata loader and deployed response headers. The same validated response must bind requested/canonical identity, locale, page/source hash, SEO fingerprint and canonical policy. Independent cached SEO must not override a valid Current body.

A page recovering after reload proves neither a permanent fix nor the cause of every historical request. Save the first failing API/HTML/headers and classify whether it is stale authority, an invalid contract, legitimate noindex, a hold/absence, or a rendering failure. Do not remove holds or force index. Source byte digest and API semantic digest may differ; use the actual contract.

For whole-page render errors, inspect the frontend adapter's real rejected field. External source-link allowlists must support the authoritative package's intended references without allowing arbitrary schemes/hosts. Validate both the affected pages and rejected unsafe input; do not rewrite correct editorial JSON to work around a reader bug.

## Environment and deployed smoke

Trace the artifact's actual build-time/runtime API and site configuration plus the smoke's comparison source. Distinguish transport origin, canonical site authority and staging anti-index policy; they need not have the same hostname. Reuse the application site contract rather than inferring canonical from the staging request hostname. Backend sitemap candidate generation and validation must use the same environment policy while rejecting foreign hosts.

Use current repository fixtures with the actual envelope and nested locale/identity/hash fields. When touching streaming checks, include completed React stream patches, hidden/props-only content and damaged streams, not only flat synthetic HTML. Scripts containing text are not visible-body proof.

Existing smoke must identify a sanitized failure category (environment, API contract, stream, SEO authority, robots, canonical, renderer, visible body) before discarding evidence. Preserve bounded timeouts and negative assertions. For failed restoration, distinguish pointer/process/HTTP recovery from a smoke assertion failure; verify the actual old release and its applicable acceptance rules. Production automatic LKG failure is a real incident, not permission to bypass recovery rules.

Useful frontend locations: lib/site.ts, scripts/ops/verify-career-renderer.mjs, scripts/deploy_web_pm2.sh and .github/trunk/career-renderer-smoke.test.mjs. Verify their existence and current behavior before use.

## Reuse acceptance without losing evidence

Use the existing public reader/parser if available; locate it in the task evidence or maintained repository tooling. Match its hash algorithm and HTML streaming behavior to the current contract before reusing it. Do not build a new checker for each resume or embed a stale SHA/path that depends on a deleted worktree.

Record source inventory/manifest identity and actually activated backend/frontend versions. Read the immutable accepted source tree. Do one bounded pass across the selected locale URLs, preserving each first response and missing coverage; use low concurrency appropriate to live load. Browser checks cover representative layouts and known affected paths, rather than manually opening all 1046 pages.

If an unchanged version has transient failures, bounded diagnostic rechecks may isolate them, but retain the first failures and do not declare them resolved merely from a later success. After a corrective release, bind new evidence to that version. If deployments change during a pass, identify and repeat affected coverage rather than discarding valid unchanged evidence automatically. A docs-only deploy-skip or unrelated evidence closeout is not a new runtime activation; verify the actual operations and relevant SHA before waiting or restarting.

Report installed files, activated versions, publicly rendered body and indexability separately. A legitimate public noindex is different from 404; correctness follows the authorized publication contract. Declare a batch complete only from independent results for every selected locale/page, unchanged unselected locales and the accepted complete package.

## Candidate sitemap and active scheduler share a cache

When a successful candidate sitemap warm is followed by a smaller post-sitemap inventory, inspect the actual cache store, key identity and writer schedule. A successful warm does not prove its result survived a later full reader pass. Distinguish workflow-printed rollback source code from executed tasks and activation evidence; a pre-symlink failure leaves the prior release active.

The public projection router scopes sitemap v2 fresh/stale/fingerprint and its existing default-store mutex to the canonical immutable release root. CLI, FPM and scheduler paths through `current` must resolve to the same generation; old literal keys and other release writers cannot replace it. Lifecycle inventory recognizes only the declared data keys, never mutexes. Keep unknown keys and other locks unchanged. Verify both serving and mirror modes, logical-key batch results, invalidation, old writers and symlink identity with small fixtures. Every release mode that activates a new runtime root must warm that generation before activation, including body-only, first-publish packages and code-only restoration; do not rely on the static fallback or a later scheduler tick. Preserve complete post-sitemap comparison and all existing bounds. Compare the fresh TTL with the full guard duration and the first scheduler tick: the standard path retains warm then full post-sitemap validation, and all activating modes refresh the same verified generation immediately before symlink, after the existing preactivation guards. Reuse the bounded refresh-if-changed helper; do not extend TTL, bypass validation or rely on the old scheduler to renew candidate freshness.

If a corrective frontend release reuses an already accepted API readback, verify unchanged backend activation, manifest/source identities and evidence hashes, then fetch affected HTML against the new frontend version. Label reused API evidence and fresh HTML separately; do not describe cached response files as new HTTP requests. The 2026-10-12/13 release paired accepted backend `9557d836` API evidence with fresh frontend `53d22a4e` HTML coverage. Changed inputs invalidate affected evidence; configuration-only deploy-skip does not require a new 1046-page runtime acceptance.
