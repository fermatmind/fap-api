#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

ORG_NAME="${1:-Local Default Org}"
ORG_DOMAIN="${OPS_BOOTSTRAP_ORG_DOMAIN:-}"
ORG_TIMEZONE="${OPS_BOOTSTRAP_ORG_TIMEZONE:-UTC}"
ORG_LOCALE="${OPS_BOOTSTRAP_ORG_LOCALE:-en-US}"

php -r '
$backendDir = rtrim((string) getenv("BACKEND_DIR"), "/");
require $backendDir . "/vendor/autoload.php";
$app = require $backendDir . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (!App\Support\SchemaBaseline::hasTable("organizations")) {
    fwrite(STDERR, "organizations table missing\n");
    exit(1);
}

$count = (int) Illuminate\Support\Facades\DB::table("organizations")->count();
if ($count > 0) {
    echo "organizations already exist, skip\n";
    exit(0);
}

$name = trim((string) getenv("ORG_NAME"));
if ($name === "") {
    $name = "Local Default Org";
}

$domain = trim((string) getenv("ORG_DOMAIN"));
$timezone = trim((string) getenv("ORG_TIMEZONE"));
$locale = trim((string) getenv("ORG_LOCALE"));

$id = Illuminate\Support\Facades\DB::table("organizations")->insertGetId([
    "name" => $name,
    "owner_user_id" => 0,
    "status" => "active",
    "domain" => $domain !== "" ? $domain : null,
    "timezone" => $timezone !== "" ? $timezone : "UTC",
    "locale" => $locale !== "" ? $locale : "en-US",
    "created_at" => now(),
    "updated_at" => now(),
]);

echo "created org id=$id name=$name\n";
' BACKEND_DIR="$BACKEND_DIR" ORG_NAME="$ORG_NAME" ORG_DOMAIN="$ORG_DOMAIN" ORG_TIMEZONE="$ORG_TIMEZONE" ORG_LOCALE="$ORG_LOCALE"
