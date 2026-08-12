# Career 1046 discoverability release control

`CAREER-1046-DISCOVERABILITY-RELEASE-CONTROL-11B` is the only protected
two-phase control that may release the exact active Career 1046 generation to
the backend sitemap and llms enumerators. It is manual-dispatch-only and is not
executed by this change.

The immutable generation pointer remains discoverability-closed. Apply creates
one no-clobber `discoverability-releases/<generation>/release.json` permit.
The runtime accepts that permit only when it still binds the active raw pointer,
byte-identical immutable pointer, the five generation product document hashes,
the exact 1046 slug / 2092 bilingual-row sets, and the same generation id. A
missing, malformed, stale, cross-generation, pointer-drift, document-drift, or
set-drift permit fails closed and exposes no Career 1046 URL through sitemap or
llms.

Preflight is production zero-write. It accepts exactly one successful,
unexpired Task 7A `Career 1046 Public Product Verify Only` artifact, binding
its run/attempt/digest/receipt hash, latest control-plane SHA, exact active
release SHA/name, generation id and active-pointer hash. It also recomputes the
Task 3B SELECT-only database authority state. Any receipt, generation, pointer,
document, database, count, or set drift stops the control.

Apply additionally consumes a fresh successful preflight receipt and the exact
workflow-generated phrase. It can write only the same-generation permit. It
does not change a root pointer, database, CMS, cache, sitemap cache, llms cache,
candidate, generation document, deployment, migration, process, or warm state.
The permit releases exactly 2092 bilingual Career sitemap URLs and 1046 Career
llms slugs. Search, IndexNow, GSC, URL Inspection, and all active submission
remain disabled.

`CAREER-1046-DISCOVERABILITY-RELEASE-ACCEPTANCE-REPAIR-11C` strengthens this
control without executing it. The runner records the verified preflight receipt
hash in apply evidence, marks write execution before its first filesystem
mutation, and distinguishes committed from ambiguous write state. The workflow
keeps its initialized sanitized receipt until a same-directory temporary runner
receipt has passed the complete contract and lineage checks, then replaces it
atomically.

Sitemap, `llms.txt`, and `llms-full.txt` retain their existing body cache keys
and TTLs. A companion identity binds each cached body to the current active
generation, permit, frozen cohort, and validated document state. Hold-to-release,
release-to-hold, malformed authority, and cross-generation drift therefore miss
and rebuild without an apply-time cache mutation. Each build validates one
authority snapshot and then performs constant-time in-memory locale membership
checks. Only the frozen 1046 cohort is withheld; unrelated Career authority and
all other public content keep their existing behavior.

No workflow dispatch, SSH session, production/staging access, deployment, or
production mutation occurred while adding this control plane.

Solo-owner efficiency impact: one acceptance-repair PR; zero required operator
interactions for this code-only change. It batches the runner, workflow, runtime
gate, cache-transition, and public-contract corrections because no shorter safe
repair path preserves their shared lineage boundary. A separate protected
invocation is required later for the intentionally controlled production action.
