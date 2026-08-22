# Career Content Agent deterministic harness

`scripts/run_career_content_agent.py` is the only state executor for the on-demand Agent. It does not generate or rewrite content. It validates and locks inputs, invokes the existing validators/adapter/compiler, checkpoints gate evidence, and emits a no-authority receipt.

## Commands

```text
init --request <request.json>
status --output-root <locked-root>
record-research --output-root <locked-root> --research-package <package> --observations <json>
record-editorial --output-root <locked-root> --result <qa-result.json>
run-evidence-adapter --output-root <locked-root> --research-package <package> --source-root <ten-block-root> --lookup <lookup.json> --control-slug <slug>
run-dry-compile --output-root <locked-root> --source-root <ten-block-root> --lookup <lookup.json>
finalize --output-root <locked-root>
resume --output-root <locked-root>
```

The observation file records bounded source access and resource use. The QA result records exactly one `PASS`, `WARN`, or `BLOCKED` decision and cannot request an automatic rewrite. Adapter and compile commands run slugs in lexical order and execute the same deterministic input twice before checkpointing PASS.

## Checkpoints and recovery

The locked root contains `request.locked.json`, `agent-state.json`, `gate-01-research.json`, `gate-02-editorial.json`, `gate-03-evidence.json`, `gate-04-dry-compile.json`, and finally `career-content-agent-receipt.json`. Writes use a same-directory temporary file, `fsync`, and atomic `os.replace`. `.career-content-agent.lock` is an exclusive non-blocking process lock; a concurrent writer fails closed.

Each checkpoint binds a command-input hash. Repeating the same gate with the same hash is a safe no-op. A changed input for an already recorded gate raises `gate_input_hash_conflict` without replacing prior evidence. `resume` and `status` are read-only and return the next legal command. Stop states are terminal; a corrected attempt requires a new request and `batch_id`.

All business hashes use canonical JSON and exclude wall time, token, cost, and other runtime observations. Outputs remain under the locked system-temporary `output_root`; the harness has no publisher, release, deployment, CMS, database, cache, sitemap, discoverability, search-submission, cron, queue, or automation call.
