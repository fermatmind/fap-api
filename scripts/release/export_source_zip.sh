#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
mkdir -p dist

OUT="dist/fap-api-source.zip"
git archive --format=zip --prefix=fap-api/ -o "$OUT" HEAD

echo "[export] $OUT"
