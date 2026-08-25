# Five gates, risk, and evidence lifecycle

This file is the sole authority for Career Content Agent gate, risk, and lifecycle decisions.

## State machine

The ordered success states are `REQUEST_LOCKED`, `RESEARCH_PASS`, `EDITORIAL_PASS`, `EVIDENCE_ADAPTER_PASS`, `DRY_COMPILE_PASS`, and `ORCHESTRATED`. The five executed gates are research producer, editorial QA, evidence adapter, canonical builder dry compile, and orchestrator receipt. A request is locked before Gate 1.

Stop states are `BLOCKED_INPUT`, `BLOCKED_SOURCE_ACCESS`, `BLOCKED_RESEARCH`, `WARN_EDITORIAL`, `BLOCKED_EDITORIAL`, `BLOCKED_EVIDENCE`, `BLOCKED_COMPILE`, `MANUAL_REVIEW_REQUIRED`, and `BUDGET_EXHAUSTED`. A stop is terminal for that execution: do not skip, automatically roll back, continue after WARN, lower risk, expand scope/source access, or rewrite-loop until PASS. A new attempt requires a new explicit locked request or explicit correction outside the stopped execution.

## Gate 1 — research producer

Input is the locked request, current 1046 inventory and hash, source policy version/hash, and existing producer. Request, authorized scope, receipt, and actual career-directory slugs must be exact unique sets; Agent mode/modules/locales/markets/jurisdictions/date/policy must also match exactly. PASS requires: every slug in inventory; exactly 10/10 modules per slug; a stable declared-file package aggregate across validator execution; producer errors 0; expired sources 0; unresolved 0 for C3.6C/C3.6D mode; safe public source access; budgets within limits; and identical research candidate hash for the same locked business input. Scope mismatch yields `BLOCKED_RESEARCH` / `research_authorized_scope_mismatch`. Blocked/unsafe/indeterminate access yields `BLOCKED_SOURCE_ACCESS`; other failures yield `BLOCKED_RESEARCH`; exceeded resources yield `BUDGET_EXHAUSTED`.

## Gate 2 — editorial QA

Use only `fermatmind-career-editorial-qa`. QA is recorded per slug. A mixed batch may isolate WARN/BLOCKED slugs and continue only its explicit non-empty `publishable_slugs`; isolated slugs remain `NOT_RUN` in later gates. A batch-level `WARN`/`BLOCKED`, or an empty publication set, stops. Never rewrite-loop, weaken a WARN, hide missing evidence with style edits, or modify the approved 1046 zh-CN master. For `ymyl_high`, QA PASS stops as `MANUAL_REVIEW_REQUIRED`; adapter and later gates must be absent.

## Gate 3 — C3.6A-R evidence adapter

Consume only the canonical research package and complete byte lock recorded by Gate 1. PASS requires the exact six existing compiler evidence contract versions, consistent locale/market/jurisdiction/source keys, 100% required compiler-claim coverage, loader cohort PASS, loader single-slug PASS for every requested slug, expired 0, and explicit unresolved/unmapped counts and hashes. The existing single-target adapter runs independently per requested slug and the receipt aggregates per-slug results; no batch boolean substitutes for them. Preserve salary, AI, market-signal, and every unsupported claim as `not_compiler_mapped`; never force it into another field. A different package path, output-root escape, or byte drift yields `BLOCKED_EVIDENCE` / `research_package_binding_mismatch` without running the adapter.

## Gate 4 — canonical builder

Invoke the real existing single-slug dry compile without `--write-current`. It must consume the exact Gate 3 source-root path/tree digest, lookup path/raw digest, and evidence-package digest. PASS requires `PASS_TEN_BLOCK_DRY_COMPILE`, 10 source files, 2 locale projections, 26 components per page, blockers 0, candidate row and digest present, deterministic recompilation PASS, and zero Current/runtime/API/database/cache/CMS/sitemap/discoverability/search writes. Expired or superseded evidence cannot enter this gate. Input drift yields `BLOCKED_COMPILE` / `compiler_input_binding_mismatch` without running the compiler; other failures are `BLOCKED_COMPILE`.

## Gate 5 — orchestrator receipt

Emit and validate `career.content_agent.receipt.v1`. Bind request and inventory hashes; source-policy version/hash; the composite gate hashes defined in the execution contract; research candidate, evidence package, and per-slug candidate-row digests; known/unknown observations; unresolved/unmapped counts; requests/retries/wall time/token/cost; access blockers; manual-review state; and lifecycle summary. Gate 5 hashes the documented non-circular business projection, excluding its own output field and runtime observations. The receipt must set all four authority booleans false and all repository/Current/master/English/runtime/API/CMS/database/cache/publisher/deploy/discoverability/search/automation write counts to zero. It contains no permit, approval, release, or production-authority field.

## Risk rules

- `standard`: may advance automatically only while every gate is PASS.
- `regulated`: licensing, practice, regulation, qualification, safety, and compliance claims require Tier 1/2 evidence; otherwise `BLOCKED_RESEARCH` or `BLOCKED_EVIDENCE`.
- `ymyl_high`: claim-level medical, legal, financial, child, safety, and scope-of-practice claims require Tier 1/2 evidence. Research, candidate generation, producer validation, and editorial QA may run, but QA PASS always stops as `MANUAL_REVIEW_REQUIRED`. No adapter, compile, Current, publication, discoverability, or search action follows. This contract defines no approval system or token.

Claims retain their own risk marker; an occupation's highest claim risk sets its automatic execution ceiling without relabeling every ordinary descriptive claim as YMYL.

## Evidence lifecycle

Compute lifecycle only against request `research_as_of`, with `review_due_soon_days` bound into the request hash:

- `valid`: not expired, not due soon, and not superseded.
- `review_due_soon`: not expired and the review/expiry date is within the inclusive configured threshold; retain it and add it to receipt/review queue.
- `expired`: `research_as_of` is after the valid-through date; block canonical dry compile.
- `source_version_superseded`: the source registry explicitly binds a higher-version authoritative snapshot; page update text or model inference is insufficient. Add to review queue and do not automatically overwrite.

A newer source never automatically overwrites old evidence. Lifecycle processing only produces review work; it never hides, deletes, unpublishes, noindexes, or mutates already published content. Replacement requires a later explicit research batch.

Business digests cover only normalized locked input and deterministic candidate/evidence/dry-compile artifacts. Timestamps, latency, wall time, token and cost observations stay outside them. Equal locked input and business bytes must reproduce candidate, evidence-package, and candidate-row hashes; receipt bytes may differ because observations may differ.
