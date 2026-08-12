# Career 1046 atomic root generation activation control

## Scope

`Career 1046 Root Generation Activation Production Ops` is a protected, manual-dispatch-only two-phase control. This repository change defines the control; it does not authorize or execute preflight or apply.

The control promotes one already staged, immutable Task 5 generation by replacing only `career_generation_authority/active-generation.json`. It never switches projection, ledger, directory, detail, sitemap, llms, or cache pointers independently. Every product artifact is resolved through the one generation-native root pointer.

## Required evidence

Both phases require exact latest `main`, an exact active production release whose SHA equals that control plane, an exact successful Task 5 apply run and immutable artifact digest, the raw staging receipt SHA-256, all eight staged document hashes, the exact 1046/2092 candidate authority, and the expected 342/30 active generation pointer bytes.

The workflow refetches `origin/main` after all evidence preparation and immediately before the only command that can reach production. The remote runner rechecks the active release and runner hash, absence of the deploy lock and competing authority/deploy/migration processes, the complete byte-identical rollback pointer under the previous immutable generation, and strict loader readability of that pointer plus its bound projection and ledger artifacts. Every running `php[version] artisan career:*` process is treated as conflicting, including concrete database writers such as `career:import-authority-wave`. It also requires closed sitemap/llms/Search state and SELECT-only database authority with 1016 receipt-covered rows, 1016 exact matches, zero missing/mismatch, and zero outside target.

Before creating or committing either pointer candidate, apply acquires one bounded, server-enforced MySQL `READ` table lock over `occupations` and `index_states` on the same connection used by the Eloquent authority queries. This lock blocks every other session's ordinary writes to either authority table without requiring writer-process cooperation. Apply first completes the final 1016/1016 read under that lock, then validates the in-memory candidate pointer and its hash-bound projection, ledger, authority identity and previous-generation lineage through the exact private methods used by `CareerGenerationAuthorityLoader::loadStrict()`. Only then does it create either candidate. It holds the lock across both pointer candidate writes, the immutable pointer commit/readback, the last process and active-pointer drift guards, the root `rename`, and activated-pointer readback before releasing it in `finally`. Unsupported drivers, timeout, acquisition failure, final authority drift, runtime-schema failure, or release failure fail closed before pointer creation where applicable.

Apply additionally requires a fresh successful activation preflight receipt, its exact run/attempt and SHA-256, the preflight database-state SHA-256, and the generated approval phrase binding every identity. Any release, database, pointer, generation, document, receipt, process, or rollback drift fails closed.

## One-switch write boundary

Preflight is zero-write. Apply first rechecks the active release, all eight generation documents, deploy lock and competing processes without writing a candidate. It next takes the MySQL authority-table exclusion lock, repeats the complete SELECT-only 1016/1016 authority read with unchanged state hash, and runs the exact runtime loader pointer/descriptor/artifact/projection/ledger/authority/lineage validators against the candidate bytes. Only after both locked validations pass does it create/read back and commit immutable `generations/<generation>/generation-pointer.json`, create the adjacent root candidate, repeat the release/document/process/active-pointer guards, and perform exactly one same-directory atomic `rename` over `active-generation.json`; the lock remains held across both commits through final readback. Both new pointer files are byte-identical. The workflow input binds the previous root pointer's exact raw bytes for drift detection, while the new lineage and rollback fields store the previous parsed pointer's canonical JSON SHA-256 required by strict loader lineage validation. The pointer also uses the loader's exact projection/ledger descriptors, timestamps, activation identity, frozen authority hashes, 1046/2092 counts, database-state hash, Task 5 receipt/digest, and complete previous-generation rollback reference.

No DB/CMS/cache or staged product document is changed; only the required immutable generation pointer and the root pointer candidate/switch are written. The control does not regenerate, warm, deploy, migrate, restart, publish, release sitemap/llms/Search state, or submit Search, IndexNow, GSC, sitemap, or URLs. It has no automatic retry, cleanup, or rollback. If apply transport or receipt validity is ambiguous, the runner-side receipt records an indeterminate write state; operators must not infer whether activation succeeded.

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
