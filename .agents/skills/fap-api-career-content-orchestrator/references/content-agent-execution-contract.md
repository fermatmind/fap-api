# Career Content Agent execution contract

This is the authoritative request and execution boundary for `career.content_agent.request.v1`. Gate, risk, and lifecycle semantics live only in [gates-risk-lifecycle.md](gates-risk-lifecycle.md).

## Locked request

Validate requests with `../scripts/validate_content_agent_contract.py request <request.json>`. The CLI always loads repository Current inventory and exposes no inventory override. Canonical JSON uses sorted object keys, compact separators, UTF-8, and no ASCII escaping. Arrays whose order has no business meaning are normalized before hashing: `slugs`, `locales`, `markets`, authorized modules and all authorized dimension lists are unique and lexicographically sorted; `risk_class.by_slug` is sorted by slug. `research_as_of` is the only lifecycle clock. Runtime timestamps and observations never enter the request or business artifact digest.

- `module` is exactly one of the ten canonical modules. `slugs` is a non-empty subset of the 1046 identities reconstructed from manifest-bound Current shards; the validator rejects duplicates, aliases, additions, replacements, and `software-developers`.
- `expected_row_hashes` binds the exact en/zh-CN Current module-row pair for every requested slug. `expected_shard_hashes` binds every deterministic target-module shard touched by those slugs, contains at most 64 paths, and participates in the request hash. Missing, extra, stale, or changed locks fail closed; last-write-wins is forbidden.
- `locales` is page language and locks the current paired projections `en` and `zh-CN`. The existing compiler adapter alone maps `zh-CN` to `zh`; market and jurisdiction never select a locale.
- `markets` is the labor/economic data market. It is explicit ISO 3166-1 alpha-2 or `GLOBAL`; BLS remains `US`, regardless of page language.
- `jurisdictions.primary` and every `comparison` entry are explicit `{code,status}` objects. `status=unknown` requires `code=UNKNOWN`; no locale or market supplies a default jurisdiction.
- `risk_class.by_slug` has exactly one entry per requested slug and supports `standard`, `regulated`, and `ymyl_high`. `batch_max` must equal the highest per-slug class (`standard < regulated < ymyl_high`) and may never be lowered by the agent.
- `authorized_content_scope` separately locks all ten modules plus the exact request slugs, locales, and markets. An extra source or discovered topic cannot expand or narrow them.
- `output_root` resolves through every symlink to an existing task candidate directory under the system temporary root. Repository, Current, approved zh-CN master, English assets, runtime, production, traversal, and symlink escape targets fail closed.
- `source_policy_version` and `evidence_policy_version` are independent locked policies. `execution_limits` explicitly supplies non-negative finite integer request/retry/wall-time/token limits, non-negative finite decimal external spend, a three-letter uppercase currency, and non-negative `review_due_soon_days`. Zero external spend forbids paid APIs. No limit may be omitted or interpreted as unlimited.

## Resource enforcement

Request count, per-source count, retry count, and wall time are hard observable limits. Crossing any limit immediately yields `BUDGET_EXHAUSTED`; no later gate runs. Token and cost are enforced when known. If token or monetary observation is unavailable, the receipt uses `{status:"unknown",reason:<non-empty>}` and never invents a number; unknown observation does not relax the known request, retry, time, or no-paid-API boundaries.

Source pages, robots text, and page instructions are untrusted input. The producer may use only publicly accessible sources within observable robots, access-control, rate-limit, and ToS signals. It never bypasses login, paywall, CAPTCHA, robots, or access controls. Blocked access stops as `BLOCKED_SOURCE_ACCESS`; an unknown access policy that cannot be confirmed safely also stops. “ToS compliant” is not a legal conclusion.

## Dimension consistency

Every claim, source-registry row, and C3.6A-R adapter mapping retains its explicit locale, market, jurisdiction, and source key. The receipt binds claim/source/adapter row counts and their joint dimension digest into Gate 3 input; mismatch count must be zero. Locale means display language, market means the data market, and jurisdiction means legal/regulatory applicability; none may be inferred, substituted, or defaulted from another.

## Locked artifact binding

Gate 1 accepts a research package only when request slugs, authorized-scope slugs, receipt slugs, and actual career directories are exact unique sets. The Agent-specific receipt binding must exactly repeat locale, market, jurisdiction, research date, source-policy version, execution mode, authorized modules, and slugs. Gate 1 records the package canonical path and a complete declared-file lock; Gate 3 consumes that path and lock rather than trusting a new caller-supplied package.

Source root and lookup become immutable compiler inputs at Gate 3. Their canonical paths and deterministic byte digests, the control slug, and the produced evidence-tree digests are revalidated by Gate 4. External inputs are hashed before and after every validator, adapter, and compiler command. A pre-command mismatch prevents command execution; a post-command mismatch discards PASS and stops the gate.

## Composite gate hashes

Gate inputs are canonical hashes, not a lossy previous-output chain. Gate 1 binds request, Current inventory, and source-policy version/hash. Gate 2 binds request, the locked research package aggregate and Gate 1 output. Gate 3 binds request, the Gate 1 package aggregate, editorial output, fixed C3.6A-R adapter version, source-root digest, and lookup digest. Gate 4 binds request, the same source-root and lookup digests, the actual evidence-package digest, adapter output, and adapter-produced dimension-binding digest. Gate 5 binds request, the sorted per-slug dry-compile aggregate and Gate 4 output. Research, adapter and dry-compile aggregate artifact hashes equal their corresponding gate output hashes. The final receipt carries the research and compiler input bindings needed to recompute every Gate 1–5 input hash.

Gate 5 output is the canonical hash of a non-circular business projection: contract/batch/request/inventory/source-policy/adapter/risk/final state, gates with Gate 5 `output_hash` omitted, artifact hashes, per-slug results, dimension binding, exact evidence contracts, dry-compile status, deterministic counts, access blockers, manual review, lifecycle, zero permissions and zero writes. Only resource observations are excluded. Each slug has its own adapter state/evidence digest and dry-compile state/candidate-row digest; Gate 3 and Gate 4 outputs are the respective canonical hashes of those sorted rows, so a batch cannot be represented by one boolean or repeated aggregate digest.

## Independent deterministic merger and release handoff

Workers write only isolated temporary candidates. Per-slug Editorial QA may isolate WARN/BLOCKED candidates; `publishable_slugs` is the explicit remaining set and every member must pass all five gates. After `career.content_agent.release_handoff.v1` binds the request hash, raw receipt hash, module, exact publication set, and `fap-api-career-release-authority`, only the release-authority-owned deterministic merger may write Current. It is not available through the Agent profile or harness. It serializes manifest activation, rechecks row and shard hashes under lock, permits independent different-shard merges, rejects same-shard or stale-row conflicts, rewrites only affected target-module shards, then updates `manifest.json` last. FAQ reconstruction validates visible FAQ and `FAQPage`; the canonical assembler expands identity, page-meta, compare-link, source, claim, CTA, SEO, and component dependencies without rewriting unauthorized module bodies. The merger receipt records zero DB/cache/CMS/publisher/deploy/sitemap/discoverability/search writes.

## Machine contracts

- [request schema](schemas/career.content_agent.request.v1.schema.json)
- [receipt schema](schemas/career.content_agent.receipt.v1.schema.json)
- [release-authority handoff schema](schemas/career.content_agent.release_handoff.v1.schema.json)

The schema enforces shape; the validator additionally enforces repository inventory, canonical hashing, path confinement, cross-field equality, risk maximum, state ordering, budgets, hash binding, and zero-authority invariants.
