#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

BASE_REF="${BASE_REF:-origin/main}"
HEAD_REF="${HEAD_REF:-HEAD}"
FORMAT="${FORMAT:-json}"
WRITE_OUTPUT="${WRITE_GITHUB_OUTPUT:-1}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base)
      BASE_REF="${2:-origin/main}"
      shift 2
      ;;
    --head)
      HEAD_REF="${2:-HEAD}"
      shift 2
      ;;
    --format)
      FORMAT="${2:-json}"
      shift 2
      ;;
    --no-github-output)
      WRITE_OUTPUT="0"
      shift
      ;;
    *)
      echo "[scale-impact][FAIL] unknown argument: $1" >&2
      exit 2
      ;;
  esac
done

cd "$BACKEND_DIR"

cmd=(php artisan ci:scale-impact --base="$BASE_REF" --head="$HEAD_REF" --format="$FORMAT")
if [[ "$WRITE_OUTPUT" == "1" ]]; then
  cmd+=(--write-github-output)
fi

"${cmd[@]}"
