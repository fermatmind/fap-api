#!/usr/bin/env bash
set -euo pipefail

PR_NUM="${1:-}"
if [[ -z "$PR_NUM" ]]; then
  echo "Usage: sanitize_artifacts.sh <PR_NUM>" >&2
  exit 1
fi

ART_DIR="backend/artifacts/pr${PR_NUM}"
if [[ ! -d "$ART_DIR" ]]; then
  echo "No artifacts dir: $ART_DIR" >&2
  exit 0
fi

PYTHON_BIN="python"
if ! command -v "$PYTHON_BIN" >/dev/null 2>&1; then
  PYTHON_BIN="python3"
fi
if ! command -v "$PYTHON_BIN" >/dev/null 2>&1; then
  echo "python not found" >&2
  exit 1
fi

"$PYTHON_BIN" - <<'PY' "$ART_DIR"
import os, re, sys
base = sys.argv[1]
patterns = [
    (re.compile(r'/Users/[^\s"\']+'), '<REPO>'),
    (re.compile(r'\\/Users\\/[^\s"\']+'), '<REPO>'),
    (re.compile(r'/home/[^\s"\']+'), '<REPO>'),
    (re.compile(r'\\/home\\/[^\s"\']+'), '<REPO>'),
    (re.compile(r'C:\\Users\\[^\s"\']+'), '<REPO>'),
    (re.compile(r'C:\\\\Users\\\\[^\s"\']+'), '<REPO>'),
    (re.compile(r'Authorization:\s*Bearer\s+[A-Za-z0-9._\-]+'), 'Authorization: Bearer <REDACTED>'),
    (re.compile(r'FAP_ADMIN_TOKEN=\S+'), 'FAP_ADMIN_TOKEN=<REDACTED>'),
    (re.compile(r'DB_PASSWORD=\S+'), 'DB_PASSWORD=<REDACTED>'),
    (re.compile(r'password=\S+'), 'password=<REDACTED>'),
]

for root, _, files in os.walk(base):
    for name in files:
        path = os.path.join(root, name)
        try:
            data = open(path, 'rb').read()
        except Exception:
            continue
        try:
            text = data.decode('utf-8')
        except UnicodeDecodeError:
            continue
        new = text
        for rx, rep in patterns:
            new = rx.sub(rep, new)
        if new != text:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(new)
PY
