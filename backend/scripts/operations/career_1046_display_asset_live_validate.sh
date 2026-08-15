#!/usr/bin/env bash
set -euo pipefail

package_path="${1:-}"
apply_receipt="${2:-}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
html_validator="$script_dir/career_1046_display_asset_html_validate.py"
if [[ ! -f "$package_path" || ! -f "$apply_receipt" || ! -f "$html_validator" ]]; then
  echo '{"status":"FAIL","safe_error_code":"VALIDATION_INPUT_MISSING"}'
  exit 1
fi

expected_ai_hash="$(jq -er '.cache.career_ai_description_block_sha256' "$apply_receipt")"
expected_path_hash="$(jq -er '.cache.career_path_block_sha256' "$apply_receipt")"
expected_aggregate_hash="$(jq -er '.cache.display_block_aggregate_sha256' "$apply_receipt")"

work_dir="$(mktemp -d)"
trap 'rm -rf -- "$work_dir"' EXIT
failures="$work_dir/failures"
ai_hashes="$work_dir/ai-hashes"
path_hashes="$work_dir/path-hashes"
: > "$failures"
: > "$ai_hashes"
: > "$path_hashes"

fetch_exact_200() {
  local url="$1" output="$2" error_output="$3" attempt=1 rc=0 code=000 temp
  temp="${output}.next"
  while (( attempt <= 3 )); do
    set +e
    code="$(curl -sS --connect-timeout 5 --max-time 30 -o "$temp" -w '%{http_code}' "$url")"
    rc=$?
    set -e
    if (( rc == 0 )) && [[ "$code" == 200 ]]; then
      mv "$temp" "$output"
      return 0
    fi
    if (( rc == 0 )); then
      if (( 10#$code >= 500 )) && (( attempt < 3 )); then
        sleep 2
        attempt=$((attempt + 1))
        continue
      fi
      printf 'http_%s\n' "$code" > "$error_output"
      return 1
    fi
    case "$rc" in
      5|6|7|18|28|35|52|55|56)
        if (( attempt < 3 )); then
          sleep 2
          attempt=$((attempt + 1))
          continue
        fi
        ;;
    esac
    printf 'transport_%s\n' "$rc" > "$error_output"
    return 1
  done
}

validate_target() {
  local slug="$1" locale="$2" segment api_file web_file fetch_error category identity ai_hash path_hash
  segment=en
  [[ "$locale" == zh-CN ]] && segment=zh
  api_file="$(mktemp "$work_dir/api.XXXXXX")"
  web_file="$(mktemp "$work_dir/web.XXXXXX")"
  fetch_error="$(mktemp "$work_dir/fetch.XXXXXX")"
  identity="${slug}|${locale}"

  if ! fetch_exact_200 "https://api.fermatmind.com/api/v0.5/career/jobs/${slug}?locale=${locale}" "$api_file" "$fetch_error"; then
    printf '%s|api_%s\n' "$identity" "$(<"$fetch_error")" >> "$failures"
    return
  fi

  if ! jq -e --arg slug "$slug" '
    def nonempty_content:
      (type == "string" or type == "array" or type == "object")
      and any(..; type == "string" and test("\\S"));
    .identity.canonical_slug == $slug
    and .display_surface_v1.surface_version == "display.surface.v1"
    and .display_surface_v1.asset_version == "v4.2"
    and .display_surface_v1.component_order == [
      "breadcrumb","hero","fermat_decision_card","primary_cta",
      "career_snapshot_primary_locale","career_snapshot_secondary_locale",
      "fit_decision_checklist","riasec_fit_block","personality_fit_block",
      "definition_block","career_ai_description_block","responsibilities_block",
      "work_context_block","market_signal_card","adjacent_career_comparison_table",
      "ai_impact_table","career_risk_cards","career_path_block",
      "contract_project_risk_block","next_steps_block","faq_block",
      "related_next_pages","source_card","review_validity_card","boundary_notice","final_cta"
    ]
    and (.display_surface_v1.page.content.hero | type) == "object"
    and (.display_surface_v1.page.content.fermat_decision_card | type) == "object"
    and (.display_surface_v1.page.content.definition_block | nonempty_content)
    and (.display_surface_v1.page.content.responsibilities_block | nonempty_content)
    and (.display_surface_v1.page.content.career_snapshot_primary_locale | type) == "object"
    and (.display_surface_v1.page.content.faq_block | type) == "object"
    and (.display_surface_v1.page.content.career_ai_description_block.heading | strings | length) > 0
    and (.display_surface_v1.page.content.career_ai_description_block.body | arrays | length) > 0
    and (.display_surface_v1.page.content.career_path_block.heading | strings | length) > 0
    and (.display_surface_v1.page.content.career_path_block.rows | arrays | length) == 4
    and all(.display_surface_v1.page.content.career_path_block.rows[]; length == 4)
  ' "$api_file" >/dev/null; then
    printf '%s|api_contract\n' "$identity" >> "$failures"
    return
  fi

  ai_hash="$(jq -j -cS '.display_surface_v1.page.content.career_ai_description_block' "$api_file" | sha256sum | awk '{print $1}')"
  path_hash="$(jq -j -cS '.display_surface_v1.page.content.career_path_block' "$api_file" | sha256sum | awk '{print $1}')"
  printf '%s|%s\n' "$identity" "$ai_hash" >> "$ai_hashes"
  printf '%s|%s\n' "$identity" "$path_hash" >> "$path_hashes"

  if ! fetch_exact_200 "https://fermatmind.com/${segment}/career/jobs/${slug}" "$web_file" "$fetch_error"; then
    printf '%s|web_%s\n' "$identity" "$(<"$fetch_error")" >> "$failures"
    return
  fi
  set +e
  category="$(python3 "$html_validator" "$web_file" "$slug" "$locale" "$segment")"
  rc=$?
  set -e
  if (( rc != 0 )); then
    printf '%s|%s\n' "$identity" "$category" >> "$failures"
  fi
}
export -f fetch_exact_200 validate_target
export work_dir failures ai_hashes path_hashes html_validator

# shellcheck disable=SC2016
jq -r '[.slug, .locale] | @tsv' "$package_path" \
  | xargs -P 8 -n 2 bash -c 'validate_target "$1" "$2"' _

ai_hash="$(sort -u "$ai_hashes" | sha256sum | awk '{print $1}')"
path_hash="$(sort -u "$path_hashes" | sha256sum | awk '{print $1}')"
aggregate_hash="$(sort -u "$ai_hashes" "$path_hashes" | sha256sum | awk '{print $1}')"
[[ "$ai_hash" == "$expected_ai_hash" ]] || printf '%s\n' 'aggregate|all|api_ai_aggregate_hash' >> "$failures"
[[ "$path_hash" == "$expected_path_hash" ]] || printf '%s\n' 'aggregate|all|api_path_aggregate_hash' >> "$failures"
[[ "$aggregate_hash" == "$expected_aggregate_hash" ]] || printf '%s\n' 'aggregate|all|api_display_aggregate_hash' >> "$failures"

failure_count="$(wc -l < "$failures" | tr -d ' ')"
if [[ "$failure_count" != 0 ]]; then
  jq -Rn --argjson failures "$failure_count" '
    [inputs | split("|") | {slug:.[0],locale:.[1],category:.[2]}] as $items
    | {
        status:"FAIL",
        safe_error_code:"PUBLIC_2092_VALIDATION_FAILED",
        checked:2092,
        failure_count:$failures,
        category_counts:($items | group_by(.category) | map({key:.[0].category,value:length}) | from_entries),
        samples:($items[:8]),
        search_submission_count:0
      }
  ' < <(sort "$failures")
  exit 1
fi

jq -n --arg ai "$ai_hash" --arg path "$path_hash" --arg aggregate "$aggregate_hash" '{
  status:"PASS",
  checked:2092,
  api_contract_and_hash_passed:2092,
  web_render_seo_and_cta_passed:2092,
  career_ai_description_block_sha256:$ai,
  career_path_block_sha256:$path,
  display_block_aggregate_sha256:$aggregate,
  search_submission_count:0
}'
