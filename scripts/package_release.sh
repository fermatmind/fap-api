#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

OUT="release.zip"
git archive --format=zip --output "$OUT" HEAD

echo "[package_release] created $ROOT/$OUT"
