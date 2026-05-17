#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

TARGET="$REPO_DIR"

usage() {
  cat <<'USAGE'
Usage:
  assert_docs_shell_safety.sh [--target <repo-or-artifact-root>]
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --target)
      TARGET="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[SEC-DOCS-SHELL][FAIL] unknown arg: $1" >&2
      usage
      exit 2
      ;;
  esac
done

if [[ -z "$TARGET" || ! -d "$TARGET" ]]; then
  echo "[SEC-DOCS-SHELL][FAIL] target_not_found: ${TARGET}" >&2
  exit 2
fi

TARGET="$(cd "$TARGET" && pwd)"
DOCS_DIR="${TARGET}/docs"
fail_count=0

fail() {
  local file="$1"
  local line="$2"
  local reason="$3"
  echo "[SEC-DOCS-SHELL][FAIL] reason=${reason} path=${file}:${line}" >&2
  fail_count=$((fail_count + 1))
}

rel() {
  local p="$1"
  p="${p#${TARGET}/}"
  echo "$p"
}

check_line() {
  local file="$1"
  local line_no="$2"
  local line="$3"
  local printable
  printable="$(rel "$file")"

  if [[ "$line" =~ (^|[^\<])\<\<-?EOF([^A-Za-z0-9_]|$) ]]; then
    fail "$printable" "$line_no" "unquoted_heredoc_eof"
  fi

  if [[ "$line" =~ (^|[[:space:];|&])(sudo[[:space:]]+)?rm[[:space:]]+-[^[:space:]]*r[^[:space:]]*[[:space:]]+.*backend/storage/app/private/content_releases(/|[[:space:]]|$) ]]; then
    fail "$printable" "$line_no" "content_releases_whole_tree_delete_example"
  fi

  if [[ "$line" =~ (^|[[:space:];|&])find[[:space:]]+.*backend/storage/app/private/content_releases.*[[:space:]]-delete([[:space:]]|$) ]]; then
    fail "$printable" "$line_no" "content_releases_find_delete_example"
  fi

  if [[ "$line" =~ (^|[[:space:];|&])(sudo[[:space:]]+)?rm[[:space:]]+-[^[:space:]]*r[^[:space:]]*[[:space:]]+.*(source_pack|previous_pack)(/|[[:space:]]|$) ]]; then
    fail "$printable" "$line_no" "release_pack_bulk_delete_example"
  fi

  if [[ "$line" =~ (^|[[:space:];|&])find[[:space:]]+.*(-name[[:space:]]+)?(source_pack|previous_pack).*(-delete|-exec[[:space:]]+(sudo[[:space:]]+)?rm)([[:space:]]|$) ]]; then
    fail "$printable" "$line_no" "release_pack_find_delete_example"
  fi
}

if [[ ! -d "$DOCS_DIR" ]]; then
  echo "[SEC-DOCS-SHELL] docs shell safety check PASS (target=$TARGET docs=missing)"
  exit 0
fi

while IFS= read -r -d '' file; do
  line_no=0
  while IFS= read -r line || [[ -n "$line" ]]; do
    line_no=$((line_no + 1))
    check_line "$file" "$line_no" "$line"
  done < "$file"
done < <(find "$DOCS_DIR" -type f -name '*.md' -print0)

if [[ "$fail_count" -gt 0 ]]; then
  echo "[SEC-DOCS-SHELL] docs shell safety check failed with ${fail_count} issue(s)." >&2
  exit 1
fi

echo "[SEC-DOCS-SHELL] docs shell safety check PASS (target=$TARGET)"
