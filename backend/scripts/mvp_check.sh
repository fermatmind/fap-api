#!/usr/bin/env bash
set -euo pipefail

PACK_DIR="${1:-}"
if [[ -z "${PACK_DIR}" ]]; then
  echo "Usage: $0 <PACK_DIR>" >&2
  exit 2
fi

TEMPLATES="${PACK_DIR}/report_highlights_templates.json"
READS="${PACK_DIR}/report_recommended_reads.json"

for f in "$TEMPLATES" "$READS"; do
  if [[ ! -f "$f" ]]; then
    echo "FAIL: missing file: $f" >&2
    exit 1
  fi
done

echo "== Highlights templates coverage (dim×side, any level in {clear,strong,very_strong}) =="
jq -r '
  .templates
  | to_entries[]
  | .key as $dim
  | .value
  | to_entries[]
  | .key as $side
  | [(.value.clear?!=null),(.value.strong?!=null),(.value.very_strong?!=null)]
  | any
  | "\($dim).\($side)=\(.)"
' "$TEMPLATES" | sort

echo
echo "== Reads stats (total_unique / fallback / non_empty_strategy_buckets) =="
jq -r '
  def arr_or_empty:
    if . == null then []
    elif (type=="array") then .
    else []
    end;

  def all_items:
    (
      (.items.by_type     | to_entries | map(.value | arr_or_empty) | add // []) +
      (.items.by_role     | to_entries | map(.value | arr_or_empty) | add // []) +
      (.items.by_strategy | to_entries | map(.value | arr_or_empty) | add // []) +
      (.items.by_top_axis | to_entries | map(.value | arr_or_empty) | add // []) +
      (.items.fallback    | arr_or_empty)
    )
    | map(select(type=="object"));

  def uniq_by_id: unique_by(.id);

  (all_items | uniq_by_id) as $U
  | "reads.total_unique=" + ($U|length|tostring),
    "reads.fallback=" + ((.items.fallback|arr_or_empty|length) | tostring),
    "reads.non_empty_strategy_buckets=" + (
      (.items.by_strategy
        | to_entries
        | map(select((.value|arr_or_empty|length) > 0))
        | map(.key)
      ) as $keys
      | ($keys|length|tostring) + " => " + ($keys|join(","))
    )
' "$READS"
