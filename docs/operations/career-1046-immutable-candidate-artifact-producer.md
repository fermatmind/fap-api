# Career 1046 immutable candidate artifact producer

This is a protected, manual-dispatch-only control-plane workflow. It first validates one successful Task 3B apply receipt, its GitHub artifact digest, exact control-plane SHA, active release identity, 1016 receipt-covered matching publication/index rows, and the immutable database-state hash. It then runs the checked-in producer against the exact active release through a MySQL read-only transaction.

The producer reuses `Career1046ImmutableCandidateGenerator`; it emits exactly one runner-side file, `career-1046-immutable-candidate.json`, uploaded as `career-1046-immutable-candidate`. The file contains one deterministic 1046-slug/2092-locale-row generation, excludes both frozen forbidden slugs, keeps sitemap/llms/Search closed, and carries the Task 3B binding in the candidate receipt. Task 5 validates this exact one-file artifact contract.

No workflow is triggered by push, schedule, or another workflow. The control does not deploy, migrate, write database/CMS/cache/artifact-tree/pointer state, warm, publish, alter sitemap/llms, or submit URLs. Merging it does not authorize dispatch or production execution.
