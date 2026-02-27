#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

echo "[AUTH_GUEST_CONTRACT] verify route + middleware invariants"
php -r '
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$routes = app("router")->getRoutes();
$route = $routes->match(Illuminate\Http\Request::create("/api/v0.3/auth/guest", "POST"));
if (!$route) {
    fwrite(STDERR, "[AUTH_GUEST_CONTRACT][FAIL] route not found\n");
    exit(1);
}

$middlewares = $route->gatherMiddleware();
$required = ["throttle:api_auth", "App\\Http\\Middleware\\ResolveAnonId"];
$missing = [];
foreach ($required as $item) {
    if (!in_array($item, $middlewares, true)) {
        $missing[] = $item;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "[AUTH_GUEST_CONTRACT][FAIL] missing middleware: " . implode(", ", $missing) . "\n");
    exit(1);
}
'

echo "[AUTH_GUEST_CONTRACT] phpunit contract tests"
php artisan test --filter AuthGuestTokenTest
php artisan test --filter AuthGuestRouteWiringTest

echo "[AUTH_GUEST_CONTRACT] PASS"
