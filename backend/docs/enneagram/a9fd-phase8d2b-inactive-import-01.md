# Enneagram Phase8D2B A9FD Inactive Import Evidence

## Summary

- Scope: local inactive import evidence for the approved Enneagram `a9fd` candidate package.
- Candidate directory used locally: `/private/tmp/fm_enneagram_a9fd_renderable_20260619`
- Local database used: `/private/tmp/fm_enneagram_phase8d2b_inactive_import_20260621.sqlite`
- Output directory: `/private/tmp/fm_enneagram_phase8d2b_a9fd_inactive_import_20260621`
- Inactive release id: `enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4`
- Inactive storage path: `private/content_releases/ENNEAGRAM/v2/enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4`
- Verdict: `PASS_FOR_PHASE_8D_3_ACTIVATION_ROLLBACK_GATE`

This PR records inactive-import evidence only. It does not generate candidate payloads, does not activate runtime, does not switch runtime, does not write production, and does not change frontend behavior.

## Validation Commands

The local default MySQL connection was unavailable, so this dry run used a fresh SQLite database under `/private/tmp` to avoid any production or shared database writes:

```bash
rm -f /private/tmp/fm_enneagram_phase8d2b_inactive_import_20260621.sqlite
touch /private/tmp/fm_enneagram_phase8d2b_inactive_import_20260621.sqlite
APP_ENV=local \
DB_CONNECTION=sqlite \
DB_DATABASE=/private/tmp/fm_enneagram_phase8d2b_inactive_import_20260621.sqlite \
php artisan migrate --force --no-ansi
```

```bash
APP_ENV=local \
DB_CONNECTION=sqlite \
DB_DATABASE=/private/tmp/fm_enneagram_phase8d2b_inactive_import_20260621.sqlite \
PHASE8B_CANDIDATE_DIR=/private/tmp/fm_enneagram_a9fd_renderable_20260619 \
PHASE8D2B_OUTPUT_DIR=/private/tmp/fm_enneagram_phase8d2b_a9fd_inactive_import_20260621 \
php artisan enneagram:import-inactive-candidate-release --json
```

Independent verification:

```bash
APP_ENV=local \
DB_CONNECTION=sqlite \
DB_DATABASE=/private/tmp/fm_enneagram_phase8d2b_inactive_import_20260621.sqlite \
php artisan tinker --execute='echo json_encode(["activations"=>DB::table("content_pack_activations")->count(),"releases"=>DB::table("content_pack_releases")->where("id","enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4")->count(),"manifests"=>DB::table("content_release_manifests")->where("content_pack_release_id","enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4")->count()], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;'
```

Result:

```json
{
    "activations": 0,
    "releases": 1,
    "manifests": 1
}
```

Storage payload verification:

```text
candidate/candidate_payloads JSON count: 630
```

## Hash Verification

- Candidate manifest hash expected: `a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`
- Candidate manifest hash actual: `a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`
- Runtime registry manifest hash expected: `ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`
- Runtime registry manifest hash actual: `ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`
- Runtime registry release hash actual: `9f84088f4bf8151ba1b1dc350c16c9577dfd0523c169c6ba00e7af5283a1c4ee`

## Gate Results

- Candidate payload count: `630`
- Release metadata row created: `true`
- Content release manifest row created: `true`
- Activation row created: `false`
- Runtime fallback preserved: `true`
- Runtime source before import: `repo_fallback`
- Runtime source after import: `repo_fallback`
- Active release id before import: `null`
- Active release id after import: `null`
- Production import happened: `false`
- Full replacement happened: `false`
- Normal report unchanged before activation: `true`
- Share/PDF/history unchanged before activation: `true`
- FC144 boundary violation count: `0`
- Launch scope: `1R-A`, `1R-B`, `1R-C`, `1R-D`, `1R-E`, `1R-F`, `1R-G`, `1R-H`
- Out of launch scope: `1R-I`, `1R-J`

## Deferred

- No production activation.
- No runtime switch.
- No production writes.
- No frontend changes.
- No generated candidate payload artifacts committed.
