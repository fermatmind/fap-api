#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
FIRST_WAVE_MANIFEST="${REPO_ROOT}/docs/career/first_wave_manifest.json"
FIRST_WAVE_ALIASES="${REPO_ROOT}/docs/career/first_wave_aliases.json"

usage() {
  cat <<'EOF'
Usage:
  scripts/career_gold_diff.sh CANDIDATE_JSON [--assert-frozen-clean] [--frozen-base=REF]

Behavior:
  - validates the candidate batch manifest structurally
  - rejects forbidden engine-owned fields
  - rejects missing required keys
  - rejects duplicate draft_id / occupation_uuid / canonical_slug values
  - rejects canonical_slug overlap with frozen first-wave manifest

This helper is validation-only.
It never rewrites files and never computes truth, scores, trust, or claims.
EOF
}

if [ "${1:-}" = "" ] || [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
  usage
  exit 0
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "FAIL: jq not found."
  exit 1
fi

CANDIDATE_PATH="$1"
ASSERT_FROZEN_CLEAN=0
FROZEN_BASE_REF="${CAREER_GOLD_DIFF_BASE_REF:-origin/main}"

shift
while [ "$#" -gt 0 ]; do
  case "$1" in
    --assert-frozen-clean)
      ASSERT_FROZEN_CLEAN=1
      ;;
    --frozen-base=*)
      FROZEN_BASE_REF="${1#--frozen-base=}"
      ;;
    --frozen-base)
      shift
      FROZEN_BASE_REF="${1:-}"
      ;;
    *)
      echo "FAIL: unknown option $1."
      usage
      exit 1
      ;;
  esac
  shift
done

if [ ! -f "${CANDIDATE_PATH}" ]; then
  echo "FAIL: candidate manifest not found at ${CANDIDATE_PATH}."
  exit 1
fi

if ! jq -e . "${CANDIDATE_PATH}" >/dev/null 2>&1; then
  echo "FAIL: candidate manifest is not valid JSON."
  exit 1
fi

if ! jq -e . "${FIRST_WAVE_MANIFEST}" >/dev/null 2>&1; then
  echo "FAIL: frozen first-wave manifest is not valid JSON."
  exit 1
fi

if ! jq -e . "${FIRST_WAVE_ALIASES}" >/dev/null 2>&1; then
  echo "FAIL: frozen first-wave aliases file is not valid JSON."
  exit 1
fi

if [ "${ASSERT_FROZEN_CLEAN}" = "1" ]; then
  if ! git -C "${REPO_ROOT}" diff --quiet -- "${FIRST_WAVE_MANIFEST}" "${FIRST_WAVE_ALIASES}"; then
    echo "FAIL: frozen first-wave files have local modifications."
    exit 1
  fi
  if ! git -C "${REPO_ROOT}" diff --cached --quiet -- "${FIRST_WAVE_MANIFEST}" "${FIRST_WAVE_ALIASES}"; then
    echo "FAIL: frozen first-wave files have staged modifications."
    exit 1
  fi
  if [ "${FROZEN_BASE_REF}" != "" ] && git -C "${REPO_ROOT}" rev-parse --verify --quiet "${FROZEN_BASE_REF}^{commit}" >/dev/null; then
    if ! git -C "${REPO_ROOT}" diff --quiet "${FROZEN_BASE_REF}...HEAD" -- "${FIRST_WAVE_MANIFEST}" "${FIRST_WAVE_ALIASES}"; then
      echo "FAIL: frozen first-wave files differ from ${FROZEN_BASE_REF}."
      exit 1
    fi
  elif [ "${FROZEN_BASE_REF}" != "" ] && [ "${CAREER_GOLD_DIFF_BASE_REF+x}" = "x" ]; then
    echo "FAIL: frozen base ref not found: ${FROZEN_BASE_REF}."
    exit 1
  fi
fi

TOP_LEVEL_REQUIRED='[
  "manifest_version",
  "manifest_kind",
  "generated_from",
  "generated_at",
  "wave_name",
  "batch_id",
  "scope",
  "engine_boundary",
  "occupations"
]'

ITEM_REQUIRED='[
  "draft_id",
  "occupation_uuid",
  "canonical_slug",
  "canonical_title_en",
  "canonical_title_zh",
  "family_uuid",
  "source_refs",
  "alias_candidates",
  "editorial_patch",
  "human_moat_tags",
  "task_prototype_signature",
  "authoring_status",
  "notes"
]'

TOP_LEVEL_ALLOWED="${TOP_LEVEL_REQUIRED}"
ITEM_ALLOWED="${ITEM_REQUIRED}"

FORBIDDEN_FIELDS='[
  "crosswalk_mode",
  "wave_classification",
  "publish_reason_codes",
  "trust_seed",
  "reviewer_seed",
  "index_seed",
  "claim_seed",
  "score_summary",
  "trust_summary",
  "claim_permissions",
  "seo_contract",
  "provenance_meta"
]'

TMPDIR="$(mktemp -d 2>/dev/null || mktemp -d -t career_gold_diff)"
cleanup() {
  rm -rf "${TMPDIR}"
}
trap cleanup EXIT

MISSING_TOP="${TMPDIR}/missing_top.txt"
MISSING_ITEM="${TMPDIR}/missing_item.txt"
UNEXPECTED_TOP="${TMPDIR}/unexpected_top.txt"
UNEXPECTED_ITEM="${TMPDIR}/unexpected_item.txt"
FORBIDDEN_FOUND="${TMPDIR}/forbidden.txt"
DUP_DRAFT="${TMPDIR}/dup_draft.txt"
DUP_OCC="${TMPDIR}/dup_occ.txt"
DUP_SLUG="${TMPDIR}/dup_slug.txt"
FIRST_WAVE_OVERLAP="${TMPDIR}/first_wave_overlap.txt"
INVALID_KIND="${TMPDIR}/invalid_kind.txt"
INVALID_SCOPE="${TMPDIR}/invalid_scope.txt"

jq -r --argjson required "${TOP_LEVEL_REQUIRED}" '
  $required[] as $key | select(has($key) | not) | $key
' "${CANDIDATE_PATH}" > "${MISSING_TOP}"

jq -r --argjson allowed "${TOP_LEVEL_ALLOWED}" '
  keys_unsorted[] | select(($allowed | index(.)) == null)
' "${CANDIDATE_PATH}" > "${UNEXPECTED_TOP}"

jq -r --argjson required "${ITEM_REQUIRED}" '
  .occupations[]? as $item
  | $required[] as $key
  | select($item | has($key) | not)
  | $key
' "${CANDIDATE_PATH}" > "${MISSING_ITEM}"

jq -r --argjson allowed "${ITEM_ALLOWED}" '
  .occupations[]? as $item
  | ($item.canonical_slug // "<missing-slug>") as $slug
  | ($item | keys_unsorted[]) as $key
  | select(($allowed | index($key)) == null)
  | "\($slug)\t\($key)"
' "${CANDIDATE_PATH}" > "${UNEXPECTED_ITEM}"

jq -r --argjson forbidden "${FORBIDDEN_FIELDS}" '
  .occupations[]? as $item
  | ($item.canonical_slug // "<missing-slug>") as $slug
  | $forbidden[] as $key
  | select($item | has($key))
  | "\($slug)\t\($key)"
' "${CANDIDATE_PATH}" > "${FORBIDDEN_FOUND}"

jq -r '.occupations[]?.draft_id // empty' "${CANDIDATE_PATH}" | sort | uniq -d > "${DUP_DRAFT}"
jq -r '.occupations[]?.occupation_uuid // empty' "${CANDIDATE_PATH}" | sort | uniq -d > "${DUP_OCC}"
jq -r '.occupations[]?.canonical_slug // empty' "${CANDIDATE_PATH}" | sort | uniq -d > "${DUP_SLUG}"

jq -r '.occupations[]?.canonical_slug // empty' "${FIRST_WAVE_MANIFEST}" | sort -u > "${TMPDIR}/first_wave_slugs.txt"
jq -r '.occupations[]?.canonical_slug // empty' "${CANDIDATE_PATH}" | sort -u > "${TMPDIR}/candidate_slugs.txt"
comm -12 "${TMPDIR}/first_wave_slugs.txt" "${TMPDIR}/candidate_slugs.txt" > "${FIRST_WAVE_OVERLAP}"

jq -r 'select(.manifest_kind != "career_batch_draft_template") | .manifest_kind // "<missing-manifest-kind>"' \
  "${CANDIDATE_PATH}" > "${INVALID_KIND}"

jq -r 'select(.scope.first_wave_overlap_allowed != false) | (.scope.first_wave_overlap_allowed | tostring)' \
  "${CANDIDATE_PATH}" > "${INVALID_SCOPE}"

fail=0

if [ -s "${MISSING_TOP}" ]; then
  echo "FAIL: missing top-level keys:"
  cat "${MISSING_TOP}"
  fail=1
fi

if [ -s "${MISSING_ITEM}" ]; then
  echo "FAIL: missing occupation-level keys:"
  cat "${MISSING_ITEM}"
  fail=1
fi

if [ -s "${UNEXPECTED_TOP}" ]; then
  echo "FAIL: unexpected top-level keys:"
  cat "${UNEXPECTED_TOP}"
  fail=1
fi

if [ -s "${UNEXPECTED_ITEM}" ]; then
  echo "FAIL: unexpected occupation-level keys:"
  cat "${UNEXPECTED_ITEM}"
  fail=1
fi

if [ -s "${FORBIDDEN_FOUND}" ]; then
  echo "FAIL: forbidden engine-owned fields present:"
  cat "${FORBIDDEN_FOUND}"
  fail=1
fi

if [ -s "${DUP_DRAFT}" ]; then
  echo "FAIL: duplicate draft_id values:"
  cat "${DUP_DRAFT}"
  fail=1
fi

if [ -s "${DUP_OCC}" ]; then
  echo "FAIL: duplicate occupation_uuid values:"
  cat "${DUP_OCC}"
  fail=1
fi

if [ -s "${DUP_SLUG}" ]; then
  echo "FAIL: duplicate canonical_slug values:"
  cat "${DUP_SLUG}"
  fail=1
fi

if [ -s "${FIRST_WAVE_OVERLAP}" ]; then
  echo "FAIL: candidate manifest overlaps frozen first-wave slugs:"
  cat "${FIRST_WAVE_OVERLAP}"
  fail=1
fi

if [ -s "${INVALID_KIND}" ]; then
  echo "FAIL: manifest_kind must equal career_batch_draft_template:"
  cat "${INVALID_KIND}"
  fail=1
fi

if [ -s "${INVALID_SCOPE}" ]; then
  echo "FAIL: scope.first_wave_overlap_allowed must be false:"
  cat "${INVALID_SCOPE}"
  fail=1
fi

if [ "${fail}" -ne 0 ]; then
  exit 1
fi

echo "career gold diff validation ok"
echo "candidate=${CANDIDATE_PATH}"
echo "frozen_first_wave_manifest=${FIRST_WAVE_MANIFEST}"
echo "frozen_first_wave_aliases=${FIRST_WAVE_ALIASES}"
