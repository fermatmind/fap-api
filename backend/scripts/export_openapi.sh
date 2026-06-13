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

$personalityAssetSchemaRef = ["\$ref" => "#/components/schemas/PersonalityPublicContentAsset"];
$personalitySchemas = [
    "PersonalityPublicContentAssetListResponse" => [
        "type" => "object",
        "required" => ["ok", "items", "pagination"],
        "properties" => [
            "ok" => ["type" => "boolean"],
            "items" => ["type" => "array", "items" => $personalityAssetSchemaRef],
            "pagination" => [
                "type" => "object",
                "required" => ["current_page", "per_page", "total", "last_page"],
                "properties" => [
                    "current_page" => ["type" => "integer"],
                    "per_page" => ["type" => "integer"],
                    "total" => ["type" => "integer"],
                    "last_page" => ["type" => "integer"],
                ],
            ],
        ],
    ],
    "PersonalityPublicContentAssetItemResponse" => [
        "type" => "object",
        "required" => ["ok", "asset", "personality_public_content_asset_v1"],
        "properties" => [
            "ok" => ["type" => "boolean"],
            "asset" => $personalityAssetSchemaRef,
            "personality_public_content_asset_v1" => $personalityAssetSchemaRef,
        ],
    ],
    "PersonalityPublicContentAsset" => [
        "type" => "object",
        "required" => [
            "id", "org_id", "contract_version", "framework", "entity_type", "code", "entity_key",
            "slug", "locale", "title", "sections", "content_sections", "seo", "robots", "canonical_path",
            "canonical", "hreflang", "faq", "media", "schema", "method_boundary", "evidence_notes",
            "internal_links", "is_public", "index_eligible", "sitemap_eligible", "llms_eligible", "launch_state",
        ],
        "properties" => [
            "id" => ["type" => "integer"],
            "org_id" => ["type" => "integer"],
            "contract_version" => ["type" => "string"],
            "framework" => ["type" => "string", "enum" => ["big_five", "enneagram"]],
            "entity_type" => ["type" => "string", "enum" => ["hub", "domain", "polarity", "facet_hub", "facet", "center", "core_type", "wing", "instinctual_subtype"]],
            "code" => ["type" => "string"],
            "entity_key" => ["type" => "string"],
            "slug" => ["type" => "string"],
            "locale" => ["type" => "string", "enum" => ["en", "zh-CN"]],
            "title" => ["type" => "string"],
            "summary" => ["type" => "string", "nullable" => true],
            "sections" => ["type" => "array", "items" => ["\$ref" => "#/components/schemas/PersonalityContentSection"]],
            "content_sections" => ["type" => "array", "items" => ["\$ref" => "#/components/schemas/PersonalityContentSection"]],
            "seo" => ["\$ref" => "#/components/schemas/PersonalitySeo"],
            "robots" => ["type" => "string", "enum" => ["index,follow", "noindex,follow", "noindex,nofollow"]],
            "canonical_path" => ["type" => "string"],
            "canonical" => ["type" => "object", "additionalProperties" => true],
            "hreflang" => ["type" => "object", "additionalProperties" => ["type" => "string"]],
            "faq" => ["type" => "array", "items" => ["\$ref" => "#/components/schemas/PersonalityFaq"]],
            "media" => ["\$ref" => "#/components/schemas/PersonalityMedia"],
            "schema" => ["type" => "object", "additionalProperties" => true],
            "method_boundary" => ["\$ref" => "#/components/schemas/PersonalityMethodBoundary"],
            "evidence_notes" => ["type" => "array", "items" => ["\$ref" => "#/components/schemas/PersonalityEvidenceNote"]],
            "internal_links" => ["type" => "array", "items" => ["\$ref" => "#/components/schemas/PersonalityInternalLink"]],
            "is_public" => ["type" => "boolean"],
            "index_eligible" => ["type" => "boolean"],
            "sitemap_eligible" => ["type" => "boolean"],
            "llms_eligible" => ["type" => "boolean"],
            "launch_state" => ["type" => "string", "enum" => ["draft", "review", "approved", "content_ready", "content_stub", "published", "archived"]],
            "review_state" => ["type" => "string"],
            "source_package" => ["type" => "string", "nullable" => true],
            "source_hash" => ["type" => "string", "nullable" => true],
            "published_at" => ["type" => "string", "nullable" => true, "format" => "date-time"],
            "last_reviewed_at" => ["type" => "string", "nullable" => true, "format" => "date-time"],
            "updated_at" => ["type" => "string", "nullable" => true, "format" => "date-time"],
        ],
    ],
    "PersonalitySeo" => [
        "type" => "object",
        "required" => ["title", "description"],
        "properties" => [
            "title" => ["type" => "string"],
            "description" => ["type" => "string"],
        ],
        "additionalProperties" => true,
    ],
    "PersonalityContentSection" => [
        "type" => "object",
        "required" => ["key"],
        "properties" => [
            "key" => ["type" => "string"],
            "title" => ["type" => "string", "nullable" => true],
            "body_md" => ["type" => "string", "nullable" => true],
        ],
        "additionalProperties" => true,
    ],
    "PersonalityFaq" => [
        "type" => "object",
        "properties" => [
            "question" => ["type" => "string"],
            "answer" => ["type" => "string"],
        ],
        "additionalProperties" => true,
    ],
    "PersonalityMedia" => [
        "type" => "object",
        "properties" => [
            "status" => ["type" => "string"],
            "hero_image_asset_key" => ["type" => "string", "nullable" => true],
            "alt" => ["type" => "string", "nullable" => true],
        ],
        "additionalProperties" => true,
    ],
    "PersonalityMethodBoundary" => [
        "type" => "object",
        "properties" => [
            "summary" => ["type" => "string"],
            "not_for" => ["type" => "array", "items" => ["type" => "string"]],
        ],
        "additionalProperties" => true,
    ],
    "PersonalityEvidenceNote" => [
        "type" => "object",
        "properties" => [
            "source_type" => ["type" => "string"],
            "note" => ["type" => "string"],
        ],
        "additionalProperties" => true,
    ],
    "PersonalityInternalLink" => [
        "type" => "object",
        "properties" => [
            "label" => ["type" => "string"],
            "target_code" => ["type" => "string"],
            "relationship" => ["type" => "string"],
            "href" => ["type" => "string"],
        ],
        "additionalProperties" => true,
    ],
];

foreach ($paths as $pathKey => &$ops) {
    if (!str_starts_with($pathKey, "/api/v0.5/personality-content-assets")) {
        continue;
    }

    foreach ($ops as $method => &$operation) {
        if ($method !== "get") {
            continue;
        }

        $schemaRef = $pathKey === "/api/v0.5/personality-content-assets"
            ? "#/components/schemas/PersonalityPublicContentAssetListResponse"
            : "#/components/schemas/PersonalityPublicContentAssetItemResponse";
        $operation["responses"]["200"] = [
            "description" => "OK",
            "content" => [
                "application/json" => [
                    "schema" => ["\$ref" => $schemaRef],
                ],
            ],
        ];
    }
    unset($operation);
}
unset($ops);

$doc = [
    "openapi" => "3.0.3",
    "info" => [
        "title" => "fap-api route contract snapshot",
        "version" => "route-list-v1",
    ],
    "paths" => $paths,
    "components" => [
        "schemas" => $personalitySchemas,
    ],
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
