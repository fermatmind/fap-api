#!/usr/bin/env bash
set -euo pipefail

capture="${1:-}"
mode="${2:-methods}"

emit() {
    printf '%s\n' "$1"
    exit 0
}

if [ -z "$capture" ] || [ ! -f "$capture" ] || [ -L "$capture" ] || [ ! -r "$capture" ]; then
    emit FAIL_PROTOCOL
fi

if [ "$mode" != methods ] && [ "$mode" != password-result ]; then
    emit FAIL_PROTOCOL
fi

if grep -Eq 'REMOTE HOST IDENTIFICATION HAS CHANGED|Host key verification failed|No (ED25519|ECDSA|RSA) host key is known|Offending .* key' "$capture"; then
    emit FAIL_HOST_KEY
fi

if grep -Eq 'Connection (timed out|refused|reset by peer|closed by)|No route to host|Network is unreachable|Could not resolve hostname|Operation timed out|kex_exchange_identification:|ssh: connect to host' "$capture"; then
    emit FAIL_TRANSPORT
fi

if [ "$mode" = password-result ]; then
    if grep -Eq 'Permission denied|Authentication failed' "$capture"; then
        emit PASSWORD_REJECTED
    fi
    emit FAIL_PROTOCOL
fi

methods_lines="$(sed -n 's/^debug[0-9]*: Authentications that can continue: //p' "$capture")"
if [ -z "$methods_lines" ]; then
    emit FAIL_PROTOCOL
fi

canonical=""
password_offered=false
while IFS= read -r methods; do
    if [[ ! "$methods" =~ ^[A-Za-z0-9@._+-]+(,[A-Za-z0-9@._+-]+)*$ ]]; then
        emit FAIL_PROTOCOL
    fi
    if [ -n "$canonical" ] && [ "$methods" != "$canonical" ]; then
        emit FAIL_PROTOCOL
    fi
    canonical="$methods"
    IFS=',' read -r -a tokens <<< "$methods"
    for token in "${tokens[@]}"; do
        case "$token" in
            publickey|password|keyboard-interactive|hostbased|gssapi-keyex|gssapi-with-mic)
                ;;
            *)
                emit FAIL_PROTOCOL
                ;;
        esac
        if [ "$token" = password ]; then
            password_offered=true
        fi
    done
done <<< "$methods_lines"

if [ "$password_offered" = true ]; then
    emit PASSWORD_OFFERED
fi

emit PASSWORD_NOT_OFFERED
