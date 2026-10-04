#!/usr/bin/env bash
set -euo pipefail

release_root="${1:?release root is required}"
# This must run before deploy:shared replaces the committed package directory.
test ! -L "$release_root/content_packages"
source_pack="$release_root/content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO"
snapshot="$release_root/backend/resources/iq_owner_original30"
test -d "$source_pack/banks/IQ_OWNER_ORIGINAL_30"
test -d "$source_pack/assets/iq_owner_original_30"
mkdir -p "$snapshot/banks" "$snapshot/assets"
cp -a "$source_pack/banks/IQ_OWNER_ORIGINAL_30" "$snapshot/banks/"
cp -a "$source_pack/assets/iq_owner_original_30" "$snapshot/assets/"
diff -qr "$source_pack/banks/IQ_OWNER_ORIGINAL_30" "$snapshot/banks/IQ_OWNER_ORIGINAL_30"
diff -qr "$source_pack/assets/iq_owner_original_30" "$snapshot/assets/iq_owner_original_30"
echo 'IQ owner30 release-local bank and assets verified.'
