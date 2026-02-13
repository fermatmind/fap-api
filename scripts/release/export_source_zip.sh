#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
mkdir -p dist

OUT_CANONICAL="dist/source_clean.zip"
OUT_LEGACY="dist/fap-api-source.zip"

git archive --format=zip --prefix=fap-api/ -o "$OUT_CANONICAL" HEAD
cp "$OUT_CANONICAL" "$OUT_LEGACY"

echo "[export] $OUT_CANONICAL"
echo "[export][compat] $OUT_LEGACY"
