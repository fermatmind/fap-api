# Enneagram Report + Sidecar Issue Harness

This directory defines the Enneagram result page operations report and sidecar issue payload contract.

The harness classifies current-PR failures versus external blockers, writes release readiness summaries, and prepares sidecar issue payloads for follow-up.

It does not create GitHub issues directly, merge PRs, run production activation, run rollback, switch runtime, write production, or change frontend code.
