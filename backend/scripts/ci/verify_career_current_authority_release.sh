#!/usr/bin/env bash
set -euo pipefail

backend_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
repository_root="$(cd "$backend_root/.." && pwd)"
intent="$backend_root/content_assets/career/career_current_authority_release.v1.json"
receipt="$(cd "$backend_root" && php artisan career:current-authority-release-intent-verify)"

jq -e '
  .contract_version == "career.current_authority_release_intent.v1"
  and .status == "PASS_CAREER_CURRENT_RELEASE_INTENT"
  and .slug_count == 1046
  and .locale_page_count == 2092
  and .module_count == 10
  and .shard_count == 640
  and .database_writes == 0
  and .cache_writes == 0
  and .discoverability_writes == 0
  and .search_submissions == 0
' <<<"$receipt" >/dev/null

source_merge_sha="$(jq -r .source_merge_sha "$intent")"
git -C "$repository_root" merge-base --is-ancestor "$source_merge_sha" HEAD

printf '%s\n' "$receipt"
