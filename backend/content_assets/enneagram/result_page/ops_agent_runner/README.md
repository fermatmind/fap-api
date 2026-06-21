# Enneagram Result Page Ops Agent Runner

This directory defines the file-backed run-orchestrator scaffold for the Enneagram result page operations agent.

The runner prepares deterministic run plans for:

- scoped branch and worktree naming,
- local validation command plans,
- scope validation,
- pull request creation contracts,
- GitHub required-check polling,
- failure classification,
- dry-run sidecar issue payloads for external blockers.

This scaffold does not create production releases, import candidates, activate runtime, write production data, generate content, or touch frontend code.
