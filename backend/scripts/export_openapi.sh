#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"
CALLER_DIR="$(pwd)"
DEFAULT_OUTPUT="${BACKEND_DIR}/docs/contracts/openapi.snapshot.json"

MODE="write"
OUTPUT_PATH="${DEFAULT_OUTPUT}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --check) MODE="check"; shift ;;
    --stdout) MODE="stdout"; shift ;;
    --output)
      if [[ $# -lt 2 ]]; then
        echo "[openapi][FAIL] --output requires a path argument" >&2
        exit 2
      fi
      OUTPUT_PATH="$2"
      shift 2
      ;;
    *)
      echo "[openapi][FAIL] unknown arg: $1" >&2
      exit 2
      ;;
  esac
done

if [[ "${OUTPUT_PATH}" != /* ]]; then
  OUTPUT_PATH="${CALLER_DIR}/${OUTPUT_PATH}"
fi

TMP_RAW="$(mktemp)"
TMP_CANON="$(mktemp)"

cleanup() {
  rm -f "$TMP_RAW" "$TMP_CANON"
}

trap cleanup EXIT

cd "${BACKEND_DIR}"
APP_ENV="${APP_ENV:-testing}" PAYMENTS_ALLOW_STUB="${PAYMENTS_ALLOW_STUB:-true}" php artisan route:list --json >"${TMP_RAW}"

php -r '
$rawPath = $argv[1] ?? "";
$outPath = $argv[2] ?? "";
$raw = json_decode((string) file_get_contents($rawPath), true);
if (!is_array($raw)) {
    fwrite(STDERR, "[openapi][FAIL] invalid route:list json\n");
    exit(1);
}

$paths = [];
foreach ($raw as $row) {
    if (!is_array($row)) {
        continue;
    }

    $uri = trim((string) ($row["uri"] ?? ""));
    if ($uri === "" || !str_starts_with($uri, "api/")) {
        continue;
    }

    $methodRaw = strtoupper(trim((string) ($row["method"] ?? "")));
    if ($methodRaw === "") {
        continue;
    }

    $methods = array_values(array_unique(array_filter(array_map("trim", explode("|", $methodRaw)), fn ($m) => $m !== "")));
    if (in_array("GET", $methods, true) && in_array("HEAD", $methods, true)) {
        $methods = array_values(array_filter($methods, fn ($m) => $m !== "HEAD"));
    }
    if ($methods === []) {
        continue;
    }

    $path = "/" . ltrim($uri, "/");
    $name = trim((string) ($row["name"] ?? ""));
    $action = trim((string) ($row["action"] ?? "Closure"));
    $middleware = array_values(array_map("strval", (array) ($row["middleware"] ?? [])));

    $segments = explode("/", $uri);
    $version = $segments[1] ?? "root";
    $tag = preg_match("/^v[0-9]+\\.[0-9]+$/", $version) ? $version : "root";

    foreach ($methods as $method) {
        $op = strtolower($method);
        $generatedOperationId = strtolower((string) preg_replace("/[^a-zA-Z0-9]+/", "_", $method . "_" . $uri));
        $operationId = $name !== "" ? $name : $generatedOperationId;
        $item = [
            "operationId" => $operationId,
            "tags" => ["api:" . $tag],
            "responses" => ["200" => ["description" => "OK"]],
            "x-laravel-action" => $action,
            "x-laravel-middleware" => $middleware,
        ];
        if ($name !== "") {
            $item["x-laravel-name"] = $name;
        }
        $paths[$path][$op] = $item;
    }
}

ksort($paths);
foreach ($paths as $p => $ops) {
    ksort($ops);
    $paths[$p] = $ops;
}

$doc = [
    "openapi" => "3.0.3",
    "info" => [
        "title" => "fap-api route contract snapshot",
        "version" => "route-list-v1",
    ],
    "paths" => $paths,
];

$encoded = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    fwrite(STDERR, "[openapi][FAIL] encode failed\n");
    exit(1);
}

file_put_contents($outPath, $encoded . PHP_EOL);
' "${TMP_RAW}" "${TMP_CANON}"

if [[ "${MODE}" == "stdout" ]]; then
  cat "${TMP_CANON}"
  exit 0
fi

if [[ "${MODE}" == "check" ]]; then
  if [[ ! -f "${OUTPUT_PATH}" ]]; then
    echo "[openapi][FAIL] snapshot missing: ${OUTPUT_PATH}" >&2
    exit 1
  fi

  if cmp -s "${OUTPUT_PATH}" "${TMP_CANON}"; then
    echo "[openapi] snapshot up-to-date"
    exit 0
  fi

  echo "[openapi][FAIL] snapshot drift detected: ${OUTPUT_PATH}" >&2
  git --no-pager -C "${REPO_DIR}" diff --no-index -- "${OUTPUT_PATH}" "${TMP_CANON}" || true
  exit 1
fi

mkdir -p "$(dirname "${OUTPUT_PATH}")"
cp "${TMP_CANON}" "${OUTPUT_PATH}"
echo "[openapi] snapshot exported: ${OUTPUT_PATH}"
