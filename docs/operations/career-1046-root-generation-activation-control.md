# Career 1046 atomic root generation activation control

## Scope

`Career 1046 Root Generation Activation Production Ops` is a protected, manual-dispatch-only two-phase control. This repository change defines the control; it does not authorize or execute preflight or apply.

The control promotes one already staged, immutable Task 5 generation by replacing only `career_generation_authority/active-generation.json`. It never switches projection, ledger, directory, detail, sitemap, llms, or cache pointers independently. Every product artifact is resolved through the one generation-native root pointer.

## Required evidence

Both phases require exact latest `main`, an exact active production release whose SHA equals that control plane, an exact successful Task 5 apply run and immutable artifact digest, the raw staging receipt SHA-256, all eight staged document hashes, the exact 1046/2092 candidate authority, and the expected 342/30 active generation pointer bytes.

The remote runner rechecks the active release and runner hash, absence of the deploy lock and competing authority/deploy/migration processes, the complete byte-identical rollback pointer under the previous immutable generation, closed sitemap/llms/Search state, and SELECT-only database authority with 1016 receipt-covered rows, 1016 exact matches, zero missing/mismatch, and zero outside target.

Apply additionally requires a fresh successful activation preflight receipt, its exact run/attempt and SHA-256, the preflight database-state SHA-256, and the generated approval phrase binding every identity. Any release, database, pointer, generation, document, receipt, process, or rollback drift fails closed.

## One-switch write boundary

Preflight is zero-write. Apply creates one adjacent no-clobber candidate containing the generation-native pointer, reads it back, rechecks the old root pointer immediately before commit, and performs exactly one same-directory atomic `rename` over `active-generation.json`. The new pointer binds all eight immutable documents, frozen authority hashes, 1046/2092 counts, database-state hash, Task 5 receipt/digest, activation preflight receipt, and the complete previous-generation rollback reference.

No DB/CMS/cache/generation document is changed. The control does not regenerate, warm, deploy, migrate, restart, publish, release sitemap/llms/Search state, or submit Search, IndexNow, GSC, sitemap, or URLs. It has no automatic retry, cleanup, or rollback. If apply transport or receipt validity is ambiguous, the runner-side receipt records an indeterminate write state; operators must not infer whether activation succeeded.

## Repository-only acceptance

This PR must not dispatch the workflow or establish an SSH session. Validate only the control plane:

```bash
cd backend
php artisan test tests/Sre/Career1046RootGenerationActivationWorkflowTest.php --no-ansi
vendor/bin/pint --test scripts/operations/career_1046_root_generation_activation.php tests/Sre/Career1046RootGenerationActivationWorkflowTest.php
php -l scripts/operations/career_1046_root_generation_activation.php
php -l tests/Sre/Career1046RootGenerationActivationWorkflowTest.php
```

```bash
actionlint .github/workflows/career-1046-root-generation-activation-production-ops.yml
php backend/scripts/operations/verify_career_1046_current_state_freeze.php
ruby -e "require 'yaml'; YAML.load_file('docs/codex/pr-train.yaml'); puts 'yaml ok'"
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
git diff --check
```
