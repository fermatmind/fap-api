# Enneagram Result Page Content Batch Automation

This directory defines the small-batch automation harness for Enneagram result page content assets.

The harness evaluates a tiny run-scoped input against:

- a source ledger row,
- a target module,
- result type and scope,
- forbidden claim policy,
- public payload schema,
- source mapping,
- safety checks,
- diff and rollback reports.

It writes artifacts only under caller-provided artifact directories. It does not generate bulk content, import candidates, activate runtime, write production data, or touch frontend code.
