#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ART_DIR="$ROOT_DIR/artifacts/prA_ci_demo_seed_toggle"
mkdir -p "$ART_DIR"

cd "$ROOT_DIR"

php artisan migrate:fresh --force >/dev/null

echo "[check] seed with demo enabled (default)"
FAP_CI_INCLUDE_DEMO_SCALES=true php artisan db:seed --class="Database\\Seeders\\CiScalesRegistrySeeder" --force --no-interaction >/dev/null

php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$mbti=(int)\Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","MBTI")->where("is_active",1)->count();
$demo=(int)\Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","DEMO_ANSWERS")->where("is_active",1)->count();
$simple=(int)\Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","SIMPLE_SCORE_DEMO")->where("is_active",1)->count();
if($mbti!==1 || $demo!==1 || $simple!==1){fwrite(STDERR,"[FAIL] enabled seed counts mismatch\n"); exit(1);}
echo "[OK] enabled seed counts: MBTI={$mbti}, DEMO_ANSWERS={$demo}, SIMPLE_SCORE_DEMO={$simple}\n";
' | tee "$ART_DIR/enabled_check.txt" >/dev/null

echo "[check] seed with demo disabled"
FAP_CI_INCLUDE_DEMO_SCALES=false php artisan db:seed --class="Database\\Seeders\\CiScalesRegistrySeeder" --force --no-interaction >/dev/null

php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$mbti=(int)\Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","MBTI")->where("is_active",1)->count();
$demo=(int)\Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","DEMO_ANSWERS")->where("is_active",1)->count();
$simple=(int)\Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","SIMPLE_SCORE_DEMO")->where("is_active",1)->count();
$demoTotal=(int)\Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","DEMO_ANSWERS")->count();
$simpleTotal=(int)\Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","SIMPLE_SCORE_DEMO")->count();
if($mbti!==1){fwrite(STDERR,"[FAIL] MBTI should remain active\n"); exit(1);}
if($demo!==0 || $simple!==0){fwrite(STDERR,"[FAIL] demo rows should be inactive\n"); exit(1);}
if($demoTotal<1 || $simpleTotal<1){fwrite(STDERR,"[FAIL] demo rows should remain for rollback safety\n"); exit(1);}
echo "[OK] disabled seed counts: MBTI={$mbti}, DEMO_ANSWERS_ACTIVE={$demo}, SIMPLE_SCORE_DEMO_ACTIVE={$simple}, DEMO_ROWS={$demoTotal}, SIMPLE_ROWS={$simpleTotal}\n";
' | tee "$ART_DIR/disabled_check.txt" >/dev/null

cat > "$ART_DIR/summary.txt" <<'TXT'
PR-A CI demo seed toggle summary

Checks:
- migrate:fresh --force: OK
- FAP_CI_INCLUDE_DEMO_SCALES=true seed: MBTI/DEMO rows active as expected
- FAP_CI_INCLUDE_DEMO_SCALES=false seed: DEMO rows inactive, rows retained, MBTI unaffected

Artifacts:
- backend/artifacts/prA_ci_demo_seed_toggle/enabled_check.txt
- backend/artifacts/prA_ci_demo_seed_toggle/disabled_check.txt
- backend/artifacts/prA_ci_demo_seed_toggle/summary.txt
TXT

echo "[OK] CI demo seed toggle verification complete."
