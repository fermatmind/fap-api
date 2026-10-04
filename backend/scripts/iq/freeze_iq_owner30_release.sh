#!/usr/bin/env bash
set -euo pipefail

release_root="${1:?release root is required}"
snapshot="$release_root/backend/resources/iq_owner_original30"
# Verified Career-only incremental releases inherit the accepted IQ snapshot.
# Never refresh that snapshot from the shared content_packages symlink.
if [ -L "$release_root/content_packages" ] && [ "${2:-}" = '--inherited-snapshot' ]; then
    test -d "$snapshot" && test ! -L "$snapshot"
    for name in answer_key items asset_inventory manifest scoring_spec; do
        test -s "$snapshot/banks/IQ_OWNER_ORIGINAL_30/$name.json"
    done
    for option in a b c d e f; do
        test -s "$snapshot/assets/iq_owner_original_30/q02/q2-option-$option.webp"
    done
    echo 'Inherited IQ owner30 release-local snapshot retained.'
    exit 0
fi
# This must run before deploy:shared replaces the committed package directory.
test ! -L "$release_root/content_packages"
source_pack="$release_root/content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO"
test -d "$source_pack/banks/IQ_OWNER_ORIGINAL_30"
test -d "$source_pack/assets/iq_owner_original_30"
mkdir -p "$snapshot/banks" "$snapshot/assets"
cp -a "$source_pack/banks/IQ_OWNER_ORIGINAL_30" "$snapshot/banks/"
cp -a "$source_pack/assets/iq_owner_original_30" "$snapshot/assets/"
diff -qr "$source_pack/banks/IQ_OWNER_ORIGINAL_30" "$snapshot/banks/IQ_OWNER_ORIGINAL_30"
diff -qr "$source_pack/assets/iq_owner_original_30" "$snapshot/assets/iq_owner_original_30"
echo 'IQ owner30 release-local bank and assets verified.'
