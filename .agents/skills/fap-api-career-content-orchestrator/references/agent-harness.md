# Career Content Agent deterministic harness

`scripts/run_career_content_agent.py` is the only state executor for the on-demand Agent. It does not generate or rewrite content. It validates and locks inputs, invokes the existing validators/adapter/compiler, checkpoints gate evidence, and emits a no-authority receipt.

## Commands

```text
init --request <request.json>
status --output-root <locked-root>
record-research --output-root <locked-root> --research-package <package> --observations <json>
record-editorial --output-root <locked-root> --result <qa-result.json>
run-evidence-adapter --output-root <locked-root> --research-package <Gate-1-package> --source-root <ten-block-root> --lookup <lookup.json> --control-slug <slug>
run-dry-compile --output-root <locked-root> --source-root <ten-block-root> --lookup <lookup.json>
finalize --output-root <locked-root>
resume --output-root <locked-root>
```

The observation file records bounded source access and resource use. The QA result records exactly one `PASS`, `WARN`, or `BLOCKED` decision and cannot request an automatic rewrite. Adapter and compile commands run slugs in lexical order and execute the same deterministic input twice before checkpointing PASS.

`--research-package` is retained only for CLI compatibility. Gate 3 authority is the canonical package path locked by Gate 1; a different path, an output-root escape, or any package-byte drift stops as `BLOCKED_EVIDENCE` with `research_package_binding_mismatch` before the adapter runs.

## Artifact locks

Gate 1 requires exact equality among request slugs, authorized-scope slugs, receipt slugs, and the actual `careers/` directory set. The receipt's `content_agent_binding` extension also repeats the Agent mode, ten modules, locales, markets, jurisdictions, and slugs because the producer receipt's `authorized_content_scope` remains the independent C3.6A producer authorization mode. Any missing, extra, duplicate, or different value stops as `BLOCKED_RESEARCH` with `research_authorized_scope_mismatch`.

The Gate 1 checkpoint atomically stores the package canonical path; the package aggregate, candidate-tree, receipt, registry, bindings, and careers-tree SHA-256 values; the complete sorted entry manifest; validated slugs; and validator version. The package aggregate updates SHA-256 with `UTF-8(relative_path) + NUL + raw bytes + NUL` for every sorted declared file. Symlinks, non-regular files, undeclared entries, traversal, and output-root escape fail closed. The package lock is recomputed before, between, and after the two validator runs.

Gate 3 locks the source-root canonical path and deterministic tree digest, lookup canonical path and raw digest, and control slug. Every adapter invocation revalidates the Gate 1 package plus these compiler inputs before and after the subprocess. Gate 4 accepts only those locked paths and digests, revalidates every evidence-package tree, and repeats the checks before and after each dry compiler invocation. Drift stops as `BLOCKED_COMPILE` with `compiler_input_binding_mismatch`; the compiler is not invoked after a failed pre-check.

## Checkpoints and recovery

The locked root contains `request.locked.json`, `agent-state.json`, `gate-01-research.json`, `gate-02-editorial.json`, `gate-03-evidence.json`, `gate-04-dry-compile.json`, and finally `career-content-agent-receipt.json`. Writes use a same-directory temporary file, `fsync`, and atomic `os.replace`. `.career-content-agent.lock` is an exclusive non-blocking process lock; a concurrent writer fails closed.

Each checkpoint binds a command-input hash. Repeating the same gate with the same locked artifacts is a safe no-op. If a previously locked research or compiler input changes, the affected PASS is replaced by its fail-closed gate result and cannot be reused. Other changed command inputs raise `gate_input_hash_conflict` without replacing prior evidence. `resume` and `status` are read-only and return the next legal command. Stop states are terminal; a corrected attempt requires a new request and `batch_id`.

All business hashes use canonical JSON and exclude wall time, token, cost, and other runtime observations. Workers and Gate checkpoints remain under the locked system-temporary `output_root`. The harness has no Current merger, publisher, deployment, CMS, database, cache, sitemap, discoverability, search-submission, cron, queue, or automation call. A release handoff is consumed only by the independent `fap-api-career-release-authority` deterministic merger.
