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

No workflow dispatch, SSH session, production/staging access, deployment, or
production mutation occurred while adding this control plane.

Solo-owner efficiency impact: one PR; zero required operator interactions for
this code-only change. A separate protected invocation is required later for
the intentionally controlled production action.
