#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ROOT_DIR="$(cd "$BACKEND_DIR/.." && pwd)"

INPUT_REL="research/whitepaper-2026-norms/index.html"
DATA_NORMS_REL="research/whitepaper-2026-norms/data/norms.json"
DATA_SCHEMA_REL="research/whitepaper-2026-norms/data/schema.json"
OUT_REL="research/whitepaper-2026-norms/whitepaper.pdf"

INPUT="$ROOT_DIR/$INPUT_REL"
DATA_NORMS="$ROOT_DIR/$DATA_NORMS_REL"
DATA_SCHEMA="$ROOT_DIR/$DATA_SCHEMA_REL"
OUT="$ROOT_DIR/$OUT_REL"

if [[ "${WHITEPAPER_ENABLED:-0}" != "1" ]]; then
  echo "[WHITEPAPER] skip (disabled). Set WHITEPAPER_ENABLED=1 to build."
  exit 0
fi

require_cmd() {
  local cmd="$1"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "[FAIL] missing required command: $cmd" >&2
    exit 1
  fi
}

missing=0
for f in "$INPUT" "$DATA_NORMS" "$DATA_SCHEMA"; do
  if [[ ! -f "$f" ]]; then
    echo "[FAIL] missing required file: $f" >&2
    missing=1
  fi
done
if [[ "$missing" -ne 0 ]]; then
  echo "[FAIL] 请先完成 9B/9C" >&2
  exit 2
fi

pick_chrome() {
  local candidate
  for candidate in \
    "${CHROME_BIN:-}" \
    "$(command -v google-chrome || true)" \
    "$(command -v chromium || true)" \
    "$(command -v chromium-browser || true)" \
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
  do
    if [[ -n "$candidate" && -x "$candidate" ]]; then
      echo "$candidate"
      return 0
    fi
  done
  return 1
}

build_with_chrome() {
  local bin="$1"
  rm -f "$OUT"
  "$bin" \
    --headless \
    --disable-gpu \
    --no-sandbox \
    --print-to-pdf="$OUT" \
    "file://$INPUT"
}

build_with_wkhtmltopdf() {
  rm -f "$OUT"
  wkhtmltopdf "file://$INPUT" "$OUT"
}

TOOL_USED=""
if chrome_bin="$(pick_chrome)"; then
  build_with_chrome "$chrome_bin"
  TOOL_USED="chrome"
elif command -v wkhtmltopdf >/dev/null 2>&1; then
  build_with_wkhtmltopdf
  TOOL_USED="wkhtmltopdf"
else
  echo "[FAIL] no headless PDF tool found." >&2
  echo "[HINT] macOS: brew install --cask google-chrome" >&2
  echo "[HINT] macOS: brew install chromium" >&2
  echo "[HINT] wkhtmltopdf: brew install wkhtmltopdf" >&2
  exit 3
fi

if [[ ! -s "$OUT" ]]; then
  echo "[FAIL] PDF not generated or empty: $OUT" >&2
  exit 4
fi

require_cmd rg
rg -n '"@type"\s*:\s*"Article"' "$INPUT" -S >/dev/null
rg -n '"@type"\s*:\s*"Dataset"' "$INPUT" -S >/dev/null

file_size() {
  local file="$1"
  if stat -f%z "$file" >/dev/null 2>&1; then
    stat -f%z "$file"
  else
    stat -c%s "$file"
  fi
}

echo "[WHITEPAPER] ok ($TOOL_USED) size=$(file_size "$OUT") bytes at $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "[WHITEPAPER] output: $OUT_REL"
