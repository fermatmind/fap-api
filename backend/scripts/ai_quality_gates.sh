#!/usr/bin/env bash

set -u
set -o pipefail

AI_GATES_ENABLED="${AI_GATES_ENABLED:-0}"
if [ "$AI_GATES_ENABLED" != "1" ]; then
  echo "AI gates disabled (AI_GATES_ENABLED=${AI_GATES_ENABLED}). skip."
  exit 0
fi

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
REPO_ROOT=$(cd "${SCRIPT_DIR}/../.." && pwd)
DEFAULT_DIR="${REPO_ROOT}/content_baselines"
TARGET_DIR="${1:-$DEFAULT_DIR}"

if [ ! -d "$TARGET_DIR" ]; then
  echo "FAIL: content_baselines not found at ${TARGET_DIR}. Please complete 10B first."
  exit 1
fi

json_count=$(find "$TARGET_DIR" -type f -name "*.json" | wc -l | tr -d ' ')
if [ "$json_count" -ne 20 ]; then
  echo "FAIL: ${TARGET_DIR} must contain 20 json files; found ${json_count}. Please complete 10B first."
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "FAIL: jq not found."
  exit 1
fi

python3_available=1
if ! command -v python3 >/dev/null 2>&1; then
  python3_available=0
  echo "WARN: python3 not found; 4-gram check skipped."
fi

echo "AI quality gates: scanning ${TARGET_DIR} (files=${json_count})"

fail_count=0

while IFS= read -r file; do
  file_fail=0
  reasons=()

  if ! jq -e . "$file" >/dev/null 2>&1; then
    reasons+=("invalid_json")
    file_fail=1
  else
    if ! jq -e '(.id|type=="string" and length>0)
      and (.type|type=="string" and length>0)
      and (.title|type=="string" and length>0)
      and (.slug|type=="string" and length>0)
      and (.status|type=="string" and length>0)' "$file" >/dev/null 2>&1; then
      reasons+=("missing_required_fields")
      file_fail=1
    fi

    type=$(jq -r '.type // empty' "$file")
    case "$type" in
      read|role_card|strategy_card) ;;
      *)
        reasons+=("invalid_type:${type}")
        file_fail=1
        ;;
    esac

    if [ "$type" = "read" ]; then
      if ! jq -e '.body and ((.body|type=="string" and length>0) or (.body|type=="array" and length>0))' "$file" >/dev/null 2>&1; then
        reasons+=("missing_body_for_read")
        file_fail=1
      fi
    fi

    text_for_length=$(jq -r '
      def collect_strings:
        if type=="string" then .
        elif type=="array" then map(collect_strings) | join("")
        elif type=="object" then to_entries | map(.value | collect_strings) | join("")
        else "" end;
      if .body then
        if (.body|type)=="string" then .body
        elif (.body|type)=="array" then (.body|map(tostring)|join(""))
        else (.body|tostring) end
      else
        if .sections then (.sections|collect_strings)
        else (collect_strings) end
      end
    ' "$file")

    text_for_scan=$(jq -r '
      def collect_strings:
        if type=="string" then .
        elif type=="array" then map(collect_strings) | join("")
        elif type=="object" then to_entries | map(.value | collect_strings) | join("")
        else "" end;
      collect_strings
    ' "$file")

    if printf "%s" "$text_for_scan" | grep -Eq '100%准确|保证|绝对|一定会|完全正确'; then
      reasons+=("forbidden_absolutism")
      file_fail=1
    fi
    if printf "%s" "$text_for_scan" | grep -Eq '抑郁症|焦虑症|诊断|治疗|处方|替代医生'; then
      reasons+=("forbidden_medical")
      file_fail=1
    fi
    if printf "%s" "$text_for_scan" | grep -Eq '作为一个AI语言模型|我无法|很抱歉'; then
      reasons+=("forbidden_ai_phrases")
      file_fail=1
    fi
    if printf "%s" "$text_for_scan" | grep -Eq '超过[0-9]{1,3}%的人|超过[0-9]{1,3}％的人'; then
      reasons+=("forbidden_fake_stats")
      file_fail=1
    fi

    clean=$(printf "%s" "$text_for_length" | tr -d '[:space:]')
    length=$(printf "%s" "$clean" | wc -m | tr -d ' ')

    min=0
    max=0
    case "$type" in
      role_card)
        min=500
        max=900
        ;;
      strategy_card)
        min=300
        max=600
        ;;
      read)
        min=600
        max=1200
        ;;
    esac

    if [ "$min" -gt 0 ]; then
      if [ "$length" -lt "$min" ] || [ "$length" -gt "$max" ]; then
        reasons+=("length_${length}_out_of_${min}-${max}")
        file_fail=1
      fi
    fi

    if [ "$python3_available" -eq 1 ]; then
      ratio=$(printf "%s" "$clean" | python3 - <<'PY'
import sys
from collections import Counter

text = sys.stdin.read()
length = len(text)
if length < 4:
    print("0")
    sys.exit(0)

grams = [text[i:i+4] for i in range(length - 3)]
counts = Counter(grams)
repeated = sum(v for v in counts.values() if v > 1)
ratio = repeated / len(grams)
print("{:.4f}".format(ratio))
sys.exit(1 if ratio > 0.08 else 0)
PY
)
      status=$?
      if [ "$status" -ne 0 ]; then
        reasons+=("4gram_repeat_ratio_${ratio}")
        file_fail=1
      fi
    fi
  fi

  if [ "$file_fail" -eq 0 ]; then
    echo "PASS $file"
  else
    echo "FAIL $file - ${reasons[*]}"
    fail_count=$((fail_count + 1))
  fi


done < <(find "$TARGET_DIR" -type f -name "*.json" | sort)

echo "Failed files: ${fail_count}"
if [ "$fail_count" -gt 0 ]; then
  exit 1
fi

exit 0
