# PR Train Sidecar Issues

## BIG5-CMS-IMPORT-DRYRUN-01 local worktree freeze-test interference

- repo: fap-api
- PR id / branch: BIG5-CMS-IMPORT-DRYRUN-01 / codex/big5-cms-import-dryrun-01
- blocker type: local worktree / branch-diff interference during full local `scripts/ci_verify_mbti.sh`
- evidence: after the CI-scope test fix, focused tests and desktop package dry-run passed, but local full `cd backend && bash scripts/ci_verify_mbti.sh` failed in `BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_paths_have_no_uncommitted_diff` with IQ method files: `backend/app/Console/Commands/ArticleImportIqMethodPagesDraft.php`, `backend/app/Models/TopicProfileEntry.php`, and `backend/app/Services/Cms/IqMethodPages/IqMethodPagesDraftImporter.php`.
- why not current PR scope: `git diff --name-only origin/main...HEAD` in the isolated PR worktree contains only the Big Five CMS import dry-run command, planner, tests, Kernel registration, and PR train metadata. It does not contain the IQ method files.
- whether required checks are affected: local full-check reproduction was affected; GitHub required checks run in a clean checkout and the current PR fix targets the actual GitHub failure, which was the hardcoded desktop package path in the test.
- recommended follow-up: keep IQ method page work isolated in its own branch/PR and avoid sharing dirty local worktree state with PR-train verification worktrees.

## BIG5-CMS-FAQ-DEDUPE-02 local worktree freeze-test interference

- repo: fap-api
- PR id / branch: BIG5-CMS-FAQ-DEDUPE-02 / codex/big5-cms-faq-dedupe-02
- blocker type: local worktree / branch-diff interference during full local `scripts/ci_verify_mbti.sh`
- evidence: PR2 focused syntax, PHPUnit, desktop package dry-run, Pint, YAML/JSON parse, and diff checks passed, but local full `cd backend && bash scripts/ci_verify_mbti.sh` failed in `BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_paths_have_no_uncommitted_diff` with the same IQ method files: `backend/app/Console/Commands/ArticleImportIqMethodPagesDraft.php`, `backend/app/Models/TopicProfileEntry.php`, and `backend/app/Services/Cms/IqMethodPages/IqMethodPagesDraftImporter.php`.
- why not current PR scope: PR2 only changes `BigFiveCmsImportDraftDryRunPlanner.php`, `PersonalityBigFiveCmsImportDraftDryRunCommandTest.php`, PR train metadata, and this sidecar record. It does not add or modify the IQ method files.
- whether required checks are affected: local full-check reproduction was affected. GitHub required checks run in a clean checkout and should validate the actual PR2 scope without the local IQ branch-diff interference.
- recommended follow-up: isolate or merge/close the IQ method branch separately; do not use that local branch state as a blocker for Big Five CMS import readiness PRs.
