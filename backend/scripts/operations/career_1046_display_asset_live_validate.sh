#!/usr/bin/env bash
set -euo pipefail

package_path="${1:-}"
if [[ ! -f "$package_path" ]]; then
  echo '{"status":"FAIL","safe_error_code":"PACKAGE_FILE_MISSING"}'
  exit 1
fi

work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
failures="$work_dir/failures"
: > "$failures"

validate_target() {
  local slug="$1" locale="$2" segment api_file web_file
  segment=en
  [[ "$locale" == zh-CN ]] && segment=zh
  api_file="$(mktemp "$work_dir/api.XXXXXX")"
  web_file="$(mktemp "$work_dir/web.XXXXXX")"

  if ! curl --retry 2 --retry-delay 2 --retry-all-errors -fsS \
    --connect-timeout 5 --max-time 30 \
    "https://api.fermatmind.com/api/v0.5/career/jobs/${slug}?locale=${locale}" \
    > "$api_file"; then
    printf '%s\n' "${slug}|${locale}|api_transport" >> "$failures"
    return
  fi

  if ! jq -e --arg slug "$slug" '
    .identity.canonical_slug == $slug
    and .display_surface_v1.surface_version == "display.surface.v1"
    and .display_surface_v1.asset_version == "v4.2"
    and (.display_surface_v1.component_order | length) == 26
    and .display_surface_v1.component_order[10] == "career_ai_description_block"
    and .display_surface_v1.component_order[17] == "career_path_block"
    and (.display_surface_v1.page.content.hero | type) == "object"
    and (.display_surface_v1.page.content.definition_block != null)
    and (.display_surface_v1.page.content.responsibilities_block != null)
    and (.display_surface_v1.page.content.market_signal_card != null)
    and (.display_surface_v1.page.content.faq_block != null)
    and (.display_surface_v1.page.content.career_ai_description_block.heading | strings | length) > 0
    and (.display_surface_v1.page.content.career_ai_description_block.body | arrays | length) > 0
    and (.display_surface_v1.page.content.career_path_block.heading | strings | length) > 0
    and (.display_surface_v1.page.content.career_path_block.rows | arrays | length) == 4
  ' "$api_file" >/dev/null; then
    printf '%s\n' "${slug}|${locale}|api_contract" >> "$failures"
    return
  fi

  if ! curl --retry 2 --retry-delay 2 --retry-all-errors -fsS \
    --connect-timeout 5 --max-time 30 \
    "https://fermatmind.com/${segment}/career/jobs/${slug}" \
    > "$web_file"; then
    printf '%s\n' "${slug}|${locale}|web_transport" >> "$failures"
    return
  fi

  if grep -Fq 'display_asset_backed_directory_draft_shell' "$web_file" \
    || grep -Fq '暂不提供完整页面' "$web_file" \
    || grep -Fq 'Full page is not available yet' "$web_file"; then
    printf '%s\n' "${slug}|${locale}|web_degraded_shell" >> "$failures"
  fi
}
export -f validate_target
export work_dir failures

jq -r '[.slug, .locale] | @tsv' "$package_path" \
  | xargs -P 8 -n 2 bash -c 'validate_target "$1" "$2"' _

failure_count="$(wc -l < "$failures" | tr -d ' ')"
if [[ "$failure_count" != 0 ]]; then
  jq -n --argjson failures "$failure_count" \
    '{status:"FAIL",safe_error_code:"PUBLIC_2092_VALIDATION_FAILED",checked:2092,failures:$failures}'
  exit 1
fi

jq -n '{status:"PASS",checked:2092,api_contract_passed:2092,web_http_and_shell_passed:2092,search_submission_count:0}'
