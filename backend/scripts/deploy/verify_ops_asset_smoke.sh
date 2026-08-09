#!/usr/bin/env bash
set -euo pipefail

remote_script="${1:-}"
receipt_path="${2:-}"
ssh_bin="${SSH_BIN:-ssh}"

if [ ! -r "$remote_script" ] || [ -z "$receipt_path" ]; then
  echo "ops asset smoke: remote script and receipt path are required" >&2
  exit 64
fi
if [[ ! "${DEPLOY_USER:-}" =~ ^[A-Za-z0-9_][A-Za-z0-9_-]{0,31}$ ]]; then
  echo "ops asset smoke: invalid deploy user" >&2
  exit 64
fi
if [[ ! "${DEPLOY_PORT:-}" =~ ^[0-9]{1,5}$ ]] || [ "$DEPLOY_PORT" -lt 1 ] || [ "$DEPLOY_PORT" -gt 65535 ]; then
  echo "ops asset smoke: invalid deploy port" >&2
  exit 64
fi
if [[ ! "${DEPLOY_HOST:-}" =~ ^[A-Za-z0-9.-]+$ || "$DEPLOY_HOST" == *".."* ]]; then
  echo "ops asset smoke: invalid deploy host" >&2
  exit 64
fi
if [[ ! "${OPS_HOST:-}" =~ ^[A-Za-z0-9.-]+$ || "$OPS_HOST" == *".."* ]]; then
  echo "ops asset smoke: invalid Ops host" >&2
  exit 64
fi

set +e
receipt="$(
  "$ssh_bin" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=yes \
    -o ConnectTimeout=10 \
    -p "$DEPLOY_PORT" \
    "$DEPLOY_USER@$DEPLOY_HOST" \
    bash -s -- "$OPS_HOST" < "$remote_script"
)"
remote_status=$?
set -e

if [ "$remote_status" -eq 255 ]; then
  echo "ops asset smoke: SSH transport failure" >&2
  exit 255
fi
if [ -z "$receipt" ]; then
  echo "ops asset smoke: remote batch returned no JSON receipt" >&2
  exit 22
fi
if ! jq -e '
  .schema_version == "fermatmind.ops-asset-smoke.v1"
  and (.result == "success" or .result == "failure")
  and (.assets | type == "array" and length == 6)
  and all(
    .assets[];
    (.path | type == "string")
    and (.requirement == "required" or .requirement == "optional")
    and (.http_status == null or (.http_status | type == "number"))
    and (.latency_ms | type == "number")
    and (
      .result == "success"
      or .result == "failure"
      or .result == "skipped"
      or .result == "transport_failure"
    )
  )
' <<< "$receipt" >/dev/null; then
  echo "ops asset smoke: remote batch returned an invalid JSON receipt" >&2
  exit 22
fi

mkdir -p "$(dirname "$receipt_path")"
temporary_receipt="${receipt_path}.tmp"
printf '%s\n' "$receipt" > "$temporary_receipt"
mv "$temporary_receipt" "$receipt_path"
jq -r '
  .assets[]
  | select(.warning != null)
  | "::warning::Ops asset \(.path): \(.warning)"
' "$receipt_path"
jq -c . "$receipt_path"

case "$remote_status" in
  0)
    if ! jq -e '.result == "success"' "$receipt_path" >/dev/null; then
      echo "ops asset smoke: success status disagrees with receipt" >&2
      exit 22
    fi
    ;;
  20)
    echo "ops asset smoke: asset HTTP transport failure" >&2
    exit 20
    ;;
  21)
    echo "ops asset smoke: asset business failure" >&2
    exit 21
    ;;
  *)
    echo "ops asset smoke: remote batch execution failure" >&2
    exit "$remote_status"
    ;;
esac
