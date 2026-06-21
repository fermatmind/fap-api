# Enneagram a9fd Phase8D3 Activation / Rollback Gate Evidence

Date: 2026-06-21

## Verdict

`PASS_FOR_MANUAL_ACTIVATION_DECISION`

This is local gate evidence only. It did not perform production activation, did not switch production runtime, did not write production data, did not create frontend changes, and did not generate or commit candidate payload artifacts.

## Scope

- Candidate baseline: `a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`
- Runtime registry manifest hash: `ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`
- Release id: `enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4`
- Payload count: `630`
- Launch scope: `1R-A` through `1R-H`
- Out of launch scope: `1R-I`, `1R-J`

## Evidence Commands

The gate was executed against a fresh local SQLite database:

```bash
rm -f /private/tmp/fm_enneagram_phase8d3_activation_gate_20260621.sqlite
touch /private/tmp/fm_enneagram_phase8d3_activation_gate_20260621.sqlite
APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=/private/tmp/fm_enneagram_phase8d3_activation_gate_20260621.sqlite php artisan migrate --force --no-ansi
```

Inactive import was replayed from the real a9fd candidate:

```bash
APP_ENV=local \
DB_CONNECTION=sqlite \
DB_DATABASE=/private/tmp/fm_enneagram_phase8d3_activation_gate_20260621.sqlite \
PHASE8B_CANDIDATE_DIR=/private/tmp/fm_enneagram_a9fd_renderable_20260619 \
PHASE8D2B_OUTPUT_DIR=/private/tmp/fm_enneagram_phase8d3_a9fd_gate_20260621/import \
php artisan enneagram:import-inactive-candidate-release --json
```

Activation dry-run:

```bash
APP_ENV=local \
DB_CONNECTION=sqlite \
DB_DATABASE=/private/tmp/fm_enneagram_phase8d3_activation_gate_20260621.sqlite \
PHASE8D3_OUTPUT_DIR=/private/tmp/fm_enneagram_phase8d3_a9fd_gate_20260621/activation_dry_run \
php artisan enneagram:activate-registry-release \
  --release-id=enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4 \
  --dry-run \
  --json
```

Controlled local SQLite activation simulation:

```bash
APP_ENV=local \
DB_CONNECTION=sqlite \
DB_DATABASE=/private/tmp/fm_enneagram_phase8d3_activation_gate_20260621.sqlite \
PHASE8D3_OUTPUT_DIR=/private/tmp/fm_enneagram_phase8d3_a9fd_gate_20260621/activation_simulation \
php artisan enneagram:activate-registry-release \
  --release-id=enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4 \
  --confirm-release-id=enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4 \
  --json
```

Controlled local SQLite rollback simulation:

```bash
APP_ENV=local \
DB_CONNECTION=sqlite \
DB_DATABASE=/private/tmp/fm_enneagram_phase8d3_activation_gate_20260621.sqlite \
PHASE8D3_OUTPUT_DIR=/private/tmp/fm_enneagram_phase8d3_a9fd_gate_20260621/rollback_simulation \
php artisan enneagram:rollback-registry-release \
  --scale=ENNEAGRAM \
  --pack-version=v2 \
  --json
```

## Results

### Inactive Import Replay

- Verdict: `PASS_FOR_PHASE_8D_3_ACTIVATION_ROLLBACK_GATE`
- Inactive release id: `enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4`
- Candidate manifest hash expected/actual: `a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`
- Runtime registry manifest hash expected/actual: `ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`
- Candidate payload count: `630`
- Runtime default repo fallback preserved: `true`
- Activation happened: `false`
- Production import happened: `false`
- Full replacement happened: `false`
- FC144 boundary violation count: `0`

### Activation Dry-Run

- Verdict: `PASS_FOR_MANUAL_ACTIVATION_DECISION`
- Mode: `dry_run`
- Release metadata source: `db_release_metadata`
- Activation happened: `false`
- Rollback happened: `false`
- Resolver before/after source: `repo_fallback`
- Candidate payload count: `630`
- FC144 boundary violation count: `0`
- Full replacement prevented: `true`
- Active repo registry overwrite: `false`
- Production activation happened: `false`
- Public launch happened: `false`
- Normal report regression: `PASS`
- Share PDF history regression: `PASS`

### Controlled SQLite Activation Simulation

- Verdict: `PASS_FOR_MANUAL_ACTIVATION_DECISION`
- Mode: `test_db_activation`
- Activation happened: `true`
- Production activation happened: `false`
- Public launch happened: `false`
- Resolver before source: `repo_fallback`
- Resolver after source: `active_release`
- Resolver after release id: `enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4`
- Candidate payload count: `630`
- FC144 boundary violation count: `0`
- Normal report regression: `PASS`
- Share PDF history regression: `PASS`

### Controlled SQLite Rollback Simulation

- Verdict: `PASS_FOR_MANUAL_ACTIVATION_DECISION`
- Mode: `test_db_rollback`
- Rollback happened: `true`
- Production activation happened: `false`
- Public launch happened: `false`
- Resolver before source: `active_release`
- Resolver after source: `repo_fallback`
- Rollback target release id: `null`
- Restored repo fallback: `true`
- Active repo registry overwrite: `false`
- Normal report regression: `PASS`
- Share PDF history regression: `PASS`

## Deferred Items

- No production activation.
- No production runtime switch.
- No production database writes.
- No frontend rendered QA rerun in this PR.
- No candidate package regeneration.
- No candidate payload artifacts committed to the repo.

## Manual Activation Boundary

This PR only records that the a9fd candidate passed local activation and rollback gate checks after strict validator, full candidate export evidence, web Phase8C rendered QA, and API Phase8D2B inactive import evidence had already passed. A real production activation still requires a separate explicit operator approval with the exact release id and rollback window.
