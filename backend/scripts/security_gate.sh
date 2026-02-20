#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${BACKEND_DIR}"

ENV_CREATED=0
if [ ! -f ".env" ]; then
  if [ -f ".env.example" ]; then
    cp ".env.example" ".env"
  else
    : > ".env"
  fi
  ENV_CREATED=1
fi

cleanup() {
  if [ "${ENV_CREATED}" -eq 1 ] && [ -f ".env" ]; then
    rm -f ".env"
  fi
}
trap cleanup EXIT

echo "[SECURITY_GATE] start"

echo "[SECURITY_GATE] check 1/10: critical route auth invariants"
php -r '
$path = getcwd() . "/routes/api.php";
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] unable to read routes/api.php\n");
    exit(1);
}

$checks = [
    "v0.2 retired_prefix" => "/Route::prefix\\(\\s*\"v0\\.2\"\\s*\\)/s",
    "v0.2 retired_any_route" => "/Route::any\\(\\s*[\\x27\\x22]\\/\\{any\\?\\}[\\x27\\x22]\\s*,\\s*static\\s+function\\s*\\(\\s*\\)\\s*\\{/s",
    "v0.3 attempts_submit_auth" => "/Route::post\\(\\s*\"\\/attempts\\/submit\"\\s*,\\s*\\[\\s*AttemptWriteController::class\\s*,\\s*\"submit\"\\s*\\]\\s*\\)\\s*->middleware\\(\\s*\\\\App\\\\Http\\\\Middleware\\\\FmTokenAuth::class\\s*\\)\\s*;/s",
    "v0.3 auth_plus_ctx_group" => "/Route::middleware\\(\\s*\\[\\s*\\\\App\\\\Http\\\\Middleware\\\\FmTokenAuth::class\\s*,\\s*ResolveO[r]gContext::class\\s*\\]\\s*\\)\\s*->\\s*group\\s*\\(/s",
];

$missing = [];
foreach ($checks as $name => $regex) {
    if (preg_match($regex, $source) !== 1) {
        $missing[] = $name;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] missing route auth invariants: " . implode(", ", $missing) . "\n");
    exit(1);
}
'

echo "[SECURITY_GATE] check 2/10: no request->all() mass assignment sinks"
php -r '
$roots = [
    getcwd() . "/routes",
    getcwd() . "/app/Http",
    getcwd() . "/app/Services",
];

$patterns = [
    "direct_create_request_all" => "/(?:->|::)\\s*create\\s*\\(\\s*\\x24request->all\\(\\)\\s*\\)/",
    "direct_update_request_all" => "/(?:->|::)\\s*update\\s*\\(\\s*\\x24request->all\\(\\)\\s*\\)/",
    "direct_fill_request_all" => "/(?:->|::)\\s*fill\\s*\\(\\s*\\x24request->all\\(\\)\\s*\\)/",
    "request_helper_all" => "/(?:->|::)\\s*(?:create|update|fill)\\s*\\(\\s*request\\(\\)->all\\(\\)\\s*\\)/",
    "assigned_request_all_then_sink" => "/\\x24([A-Za-z_][A-Za-z0-9_]*)\\s*=\\s*\\x24request->all\\(\\)\\s*;[\\s\\S]{0,240}?(?:->|::)\\s*(?:create|update|fill)\\s*\\(\\s*\\x24\\1\\s*\\)/m",
];

$isIgnored = static function (string $source, int $line, string $label): bool {
    $lines = preg_split("/\\R/", $source);
    if (!is_array($lines)) {
        return false;
    }

    $start = max(1, $line - 2);
    $label = strtolower($label);
    for ($i = $start; $i <= $line; $i++) {
        $idx = $i - 1;
        $text = $lines[$idx] ?? "";
        if (preg_match("/security-gate:ignore(?:\\s+([A-Za-z0-9_,| -]+))?/i", $text, $m) !== 1) {
            continue;
        }

        $rawScopes = trim((string) ($m[1] ?? ""));
        if ($rawScopes === "") {
            return true;
        }

        $scopes = preg_split("/[\\s,|]+/", strtolower($rawScopes));
        if (!is_array($scopes)) {
            continue;
        }
        $scopes = array_values(array_filter($scopes, static fn ($v) => is_string($v) && $v !== ""));
        if (in_array("all", $scopes, true) || in_array($label, $scopes, true)) {
            return true;
        }
    }

    return false;
};

$violations = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== "php") {
            continue;
        }

        $path = $file->getPathname();
        $source = file_get_contents($path);
        if (!is_string($source)) {
            continue;
        }

        foreach ($patterns as $label => $regex) {
            if (preg_match($regex, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $line = 1 + substr_count(substr($source, 0, (int) $m[0][1]), "\n");
            if ($isIgnored($source, $line, $label)) {
                continue;
            }
            $relative = ltrim(str_replace(getcwd() . "/", "", $path), "/");
            $violations[] = "{$relative}:{$line} => {$label}";
        }
    }
}

sort($violations);
if ($violations !== []) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] request->all() mass assignment sinks found:\n" . implode("\n", $violations) . "\n");
    exit(1);
}
'

echo "[SECURITY_GATE] check 3/10: ownership 404 contract (no 403 leaks)"
php -r '
$paths = [
    "app/Http/Controllers/API/V0_3/AttemptReadController.php",
    "app/Http/Controllers/API/V0_3/AttemptProgressController.php",
    "app/Http/Controllers/API/V0_3/ShareController.php",
    "app/Http/Controllers/LookupController.php",
    "app/Services/Legacy/LegacyReportService.php",
    "app/Services/Legacy/LegacyShareService.php",
    "app/Services/Report/ReportGatekeeper.php",
];

$patterns = [
    "abort_403" => "/abort\\s*\\(\\s*403\\b/",
    "response_json_403" => "/response\\s*\\(\\s*\\)\\s*->\\s*json\\s*\\([^;]*,\\s*403\\s*\\)/s",
    "status_403" => "/[\\x27\\x22]status[\\x27\\x22]\\s*=>\\s*403\\b/",
    "error_code_forbidden" => "/[\\x27\\x22]error_code[\\x27\\x22]\\s*=>\\s*[\\x27\\x22]FORBIDDEN[\\x27\\x22]/",
];

$isIgnored = static function (string $source, int $line, string $label): bool {
    $lines = preg_split("/\\R/", $source);
    if (!is_array($lines)) {
        return false;
    }

    $start = max(1, $line - 2);
    $label = strtolower($label);
    for ($i = $start; $i <= $line; $i++) {
        $idx = $i - 1;
        $text = $lines[$idx] ?? "";
        if (preg_match("/security-gate:ignore(?:\\s+([A-Za-z0-9_,| -]+))?/i", $text, $m) !== 1) {
            continue;
        }

        $rawScopes = trim((string) ($m[1] ?? ""));
        if ($rawScopes === "") {
            return true;
        }

        $scopes = preg_split("/[\\s,|]+/", strtolower($rawScopes));
        if (!is_array($scopes)) {
            continue;
        }
        $scopes = array_values(array_filter($scopes, static fn ($v) => is_string($v) && $v !== ""));
        if (in_array("all", $scopes, true) || in_array($label, $scopes, true)) {
            return true;
        }
    }

    return false;
};

$violations = [];
foreach ($paths as $relative) {
    $absolute = getcwd() . "/" . $relative;
    if (!is_file($absolute)) {
        continue;
    }

    $source = file_get_contents($absolute);
    if (!is_string($source)) {
        continue;
    }

    foreach ($patterns as $label => $regex) {
        if (preg_match($regex, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }
        $line = 1 + substr_count(substr($source, 0, (int) $m[0][1]), "\n");
        if ($isIgnored($source, $line, $label)) {
            continue;
        }
        $violations[] = "{$relative}:{$line} => {$label}";
    }
}

sort($violations);
if ($violations !== []) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] ownership paths returning/encoding 403:\n" . implode("\n", $violations) . "\n");
    exit(1);
}
'

echo "[SECURITY_GATE] check 4/10: v0.3 attempt ownership resolver must not trust anon headers"
php -r '
$path = getcwd() . "/app/Http/Controllers/API/V0_3/Concerns/ResolvesAttemptOwnership.php";
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] unable to read {$path}\n");
    exit(1);
}

$forbidden = [
    "/header\\s*\\(\\s*[\\x27\\x22]X-Anon-Id[\\x27\\x22]/",
    "/header\\s*\\(\\s*[\\x27\\x22]X-Fm-Anon-Id[\\x27\\x22]/",
];

foreach ($forbidden as $regex) {
    if (preg_match($regex, $source) === 1) {
        fwrite(STDERR, "[SECURITY_GATE][FAIL] header-based anon ownership detected in ResolvesAttemptOwnership\n");
        exit(1);
    }
}
'

echo "[SECURITY_GATE] check 5/10: org context can hydrate anon identity from token payload"
php -r '
$path = getcwd() . "/app/Http/Middleware/ResolveOrgContext.php";
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] unable to read {$path}\n");
    exit(1);
}

$checks = [
    "resolveAnonIdFromToken method" => "/resolveAnonIdFromToken\\s*\\(/",
    "anon attr set" => "/attributes->set\\(\\s*[\\x27\\x22]anon_id[\\x27\\x22]/",
    "fm_anon attr set" => "/attributes->set\\(\\s*[\\x27\\x22]fm_anon_id[\\x27\\x22]/",
];

$missing = [];
foreach ($checks as $name => $regex) {
    if (preg_match($regex, $source) !== 1) {
        $missing[] = $name;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] resolve org context missing anon-from-token guards: " . implode(", ", $missing) . "\n");
    exit(1);
}
'

echo "[SECURITY_GATE] check 6/10: webhook signature verifier has no testing/local fail-open"
php -r '
$path = getcwd() . "/app/Http/Controllers/Webhooks/HandleProviderWebhook.php";
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] unable to read {$path}\n");
    exit(1);
}

if (preg_match("/app\\(\\)->environment\\s*\\(\\s*\\[[^\\]]*testing[^\\]]*\\]\\s*\\)/", $source) === 1) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] webhook verifier still allows environment-based unsigned bypass\n");
    exit(1);
}

if (preg_match("/allow_unsigned_without_secret/", $source) !== 1) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] webhook verifier missing explicit allow_unsigned_without_secret flag gate\n");
    exit(1);
}
'

echo "[SECURITY_GATE] check 7/10: fm token middlewares enforce revoked/expired checks"
php -r '
$paths = [
    "app/Http/Middleware/FmTokenAuth.php",
    "app/Http/Middleware/FmTokenOptional.php",
    "app/Http/Middleware/FmTokenOptionalAuth.php",
];

$missing = [];
foreach ($paths as $relative) {
    $absolute = getcwd() . "/" . $relative;
    $source = file_get_contents($absolute);
    if (!is_string($source)) {
        $missing[] = "{$relative}:unreadable";
        continue;
    }

    if (preg_match("/revoked_at/", $source) !== 1) {
        $missing[] = "{$relative}:revoked_at_check";
    }
    if (preg_match("/expires_at/", $source) !== 1) {
        $missing[] = "{$relative}:expires_at_check";
    }
}

if ($missing !== []) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] fm token middleware missing revoked/expired checks: " . implode(", ", $missing) . "\n");
    exit(1);
}
'

echo "[SECURITY_GATE] check 8/10: content pack API errors must not leak internal reason"
php -r '
$path = getcwd() . "/app/Support/ApiExceptionRenderer.php";
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] unable to read {$path}\n");
    exit(1);
}

if (preg_match("/getPrevious\\s*\\(\\)\\s*->\\s*getMessage\\s*\\(/", $source) === 1) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] ApiExceptionRenderer leaks previous exception message for content pack errors\n");
    exit(1);
}

if (preg_match("/[\\x27\\x22]reason[\\x27\\x22]\\s*=>/", $source) === 1) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] ApiExceptionRenderer leaks reason details for content pack errors\n");
    exit(1);
}
'

echo "[SECURITY_GATE] check 9/10: v0.2 public submit must not overwrite existing attempt id"
php -r '
$path = getcwd() . "/app/Services/Legacy/Mbti/Attempt/LegacyMbtiAttemptLifecycleService.php";
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] unable to read {$path}\n");
    exit(1);
}

$checks = [
    "public overwrite guard" => "/if\\s*\\(\\s*!\\s*\\x24isResultUpsertRoute\\s*\\)\\s*\\{\\s*throw new ApiProblemException\\(\\s*409\\s*,\\s*[\\x27\\x22]ATTEMPT_ALREADY_EXISTS[\\x27\\x22]/s",
    "actor user resolver" => "/resolveActorUserId\\s*\\(/",
];

$missing = [];
foreach ($checks as $name => $regex) {
    if (preg_match($regex, $source) !== 1) {
        $missing[] = $name;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "[SECURITY_GATE][FAIL] missing v0.2 overwrite guard(s): " . implode(", ", $missing) . "\n");
    exit(1);
}
'

echo "[SECURITY_GATE] check 10/10: unit guard test"
php artisan test --testsuite=Unit --filter=SecurityGuardrailsTest

echo "[SECURITY_GATE] PASS"
