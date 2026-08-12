# Career current-generation pointer bootstrap staging control

This staging-only control reuses the frozen `career-current-342-30-bootstrap-v1` runner after a latest-main `Deploy Application` push fails because the environment-local generation pointer is absent. It never selects authority by mtime or latest directory.

Preflight is SELECT-only. It binds the exact failed push run, active staging revision and hashed release identity, validates the fixed 342/684 authority and 30/60 published cohort, and reports per-locale totals as 342 EN plus 342 zh-CN rows and 30 published rows per locale. Byte-identical source candidates are ordered by `relative_path_bytewise_ascending_first_v1`; the receipt exposes only candidate counts, the rule, and selected path SHA-256 values.

Apply requires the immutable preflight receipt and artifact digest, exact active revision, release-name hash, and both selected path hashes. The runner re-enumerates and revalidates the candidate sets before each rename, then may create only the immutable and root pointer documents. Projection and ledger bytes, DB, CMS, cache, publication, sitemap, llms, Search, migration, and process state remain unchanged.

Only a verified apply may request one `rerun-failed-jobs` operation for the receipt-bound `Deploy Application` push run. This preserves the push event required by automatic production promotion; the control never dispatches a new deployment workflow. Pointer apply has no automatic retry, cleanup, or rollback.
