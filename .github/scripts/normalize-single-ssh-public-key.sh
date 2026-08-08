#!/usr/bin/env bash

set -euo pipefail

# Canonicalize ssh-agent public output to the two fields authorized_keys needs.
# Reject every malformed/unsupported line, collapse exact duplicates, and require
# one unique identity. Key material is emitted only to stdout for the caller's
# runner-local file; this script never logs fingerprints or comments.
input="$(mktemp "${TMPDIR:-/tmp}/fap-api-production-key-input.XXXXXX")"
normalized="$(mktemp "${TMPDIR:-/tmp}/fap-api-production-key.XXXXXX")"
cleanup() { rm -f -- "$input" "$normalized"; }
trap cleanup EXIT HUP INT TERM
chmod 600 "$input" "$normalized"
cat > "$input"
if LC_ALL=C tr -d '\11\12\40-\176' < "$input" | grep -q .; then
  exit 19
fi

awk '
  NF < 2 { exit 20 }
  $1 !~ /^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521))$/ { exit 21 }
  $2 !~ /^[A-Za-z0-9+\/=]+$/ || $0 ~ /[\r\000-\010\013\014\016-\037\177]/ { exit 22 }
  { print $1 " " $2 }
' "$input" | LC_ALL=C sort -u | awk '
  NR == 1 { key=$0; next }
  { exit 23 }
  END {
    if (NR != 1 || key == "") exit 24
    print key
  }
' > "$normalized"

ssh-keygen -lf "$normalized" -E sha256 >/dev/null 2>&1
cat "$normalized"
