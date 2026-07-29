#!/usr/bin/env bash
set -euo pipefail

environment="${1:-}"
output_dir="${2:-}"
window="${3:-20}"

if [[ ! "$environment" =~ ^(staging|production)$ ]]; then
  echo "timing history: environment must be staging or production" >&2
  exit 64
fi
if [[ -z "$output_dir" || ! "$window" =~ ^[1-9][0-9]*$ || "$window" -gt 99 ]]; then
  echo "timing history: output directory and window are required" >&2
  exit 64
fi
if [[ ! "${GITHUB_REPOSITORY:-}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
  echo "timing history: GITHUB_REPOSITORY is invalid" >&2
  exit 64
fi
if [[ ! "${GITHUB_RUN_ID:-}" =~ ^[1-9][0-9]*$ ]]; then
  echo "timing history: GITHUB_RUN_ID is invalid" >&2
  exit 64
fi

mkdir -p "$output_dir"
artifact_name="deployer-task-timing-${environment}"
metadata_file="$(mktemp)"
trap 'rm -f "$metadata_file"' EXIT

if ! gh api \
  -X GET \
  "repos/${GITHUB_REPOSITORY}/actions/artifacts" \
  -f "name=${artifact_name}" \
  -F "per_page=$((window + 1))" > "$metadata_file"; then
  echo "timing history: prior artifacts unavailable; percentiles will show N/A" >&2
  exit 0
fi

mapfile -t artifact_ids < <(
  jq -r \
    --arg run_id "$GITHUB_RUN_ID" \
    --argjson window "$window" \
    '[.artifacts[] | select(.expired == false and (.workflow_run.id | tostring) != $run_id)]
     | sort_by(.created_at) | reverse | .[:$window] | .[].id' \
    "$metadata_file"
)

for artifact_id in "${artifact_ids[@]}"; do
  [[ "$artifact_id" =~ ^[1-9][0-9]*$ ]] || continue
  artifact_dir="${output_dir}/${artifact_id}"
  archive="$(mktemp)"
  mkdir -p "$artifact_dir"
  if gh api \
    -H "Accept: application/vnd.github+json" \
    "repos/${GITHUB_REPOSITORY}/actions/artifacts/${artifact_id}/zip" > "$archive"; then
    unzip -qq "$archive" -d "$artifact_dir" || true
  fi
  rm -f "$archive"
done

echo "timing history: collected ${#artifact_ids[@]} prior receipt artifact(s)"
