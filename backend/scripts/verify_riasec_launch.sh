#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://api.fermatmind.com}"
LOCALE="${LOCALE:-zh-CN}"
REGION="${REGION:-CN_MAINLAND}"
SLUG="holland-career-interest-test-riasec"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

curl_json() {
  local path="$1"
  curl -fsS \
    -H "Accept: application/json" \
    -H "X-Region: ${REGION}" \
    -H "Accept-Language: ${LOCALE}" \
    "${BASE_URL}${path}"
}

save_json() {
  local name="$1"
  local path="${TMP_DIR}/${name}.json"
  cat > "${path}"
  printf '%s\n' "${path}"
}

assert_json() {
  local path="$1"
  local label="$2"
  local code="$3"
  python3 - "$path" "$label" "$code" <<'PY'
import json
import sys

path, label, code = sys.argv[1], sys.argv[2], sys.argv[3]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)

scope = {"data": data}
exec(code, {"json": json}, scope)
print(f"[riasec-launch] ok {label}")
PY
}

echo "[riasec-launch] BASE_URL=${BASE_URL} LOCALE=${LOCALE} REGION=${REGION}"

health_path="$(curl_json "/api/healthz" | save_json health)"
assert_json "$health_path" "healthz" 'assert data.get("ok") is True'

lookup_path="$(curl_json "/api/v0.3/scales/lookup?slug=${SLUG}&locale=${LOCALE}&region=${REGION}" | save_json lookup)"
assert_json "$lookup_path" "lookup forms" '
assert data.get("ok") is True
assert data.get("scale_code") == "RIASEC"
assert data.get("primary_slug") == "holland-career-interest-test-riasec"
forms = data.get("forms") or []
codes = [f.get("form_code") for f in forms]
assert codes[:2] == ["riasec_60", "riasec_140"], codes
assert forms[0].get("is_default") is True
assert forms[0].get("question_count") == 60
assert forms[1].get("question_count") == 140
assert "riasec_" + "36" not in codes
'

catalog_path="$(curl_json "/api/v0.3/scales/catalog?locale=${LOCALE}&region=${REGION}" | save_json catalog)"
assert_json "$catalog_path" "catalog card" '
assert data.get("ok") is True
items = data.get("items") or []
item = next((i for i in items if i.get("slug") == "holland-career-interest-test-riasec"), None)
assert item is not None
assert item.get("scale_code") == "RIASEC"
forms = item.get("forms") or []
codes = [f.get("form_code") for f in forms]
assert codes[:2] == ["riasec_60", "riasec_140"], codes
assert item.get("questions_count") == 60
'

q60_path="$(curl_json "/api/v0.3/scales/RIASEC/questions?form_code=riasec_60&locale=${LOCALE}&region=${REGION}" | save_json questions_60)"
assert_json "$q60_path" "questions riasec_60" '
assert data.get("ok") is True
assert data.get("scale_code") == "RIASEC"
assert data.get("form_code") == "riasec_60"
assert data.get("dir_version") == "v1-standard-60"
items = ((data.get("questions") or {}).get("items")) or data.get("items") or []
assert len(items) == 60, len(items)
opts = items[0].get("options") or []
assert [str(o.get("code")) for o in opts] == ["1", "2", "3", "4", "5"]
'

q140_path="$(curl_json "/api/v0.3/scales/RIASEC/questions?form_code=riasec_140&locale=${LOCALE}&region=${REGION}" | save_json questions_140)"
assert_json "$q140_path" "questions riasec_140" '
assert data.get("ok") is True
assert data.get("scale_code") == "RIASEC"
assert data.get("form_code") == "riasec_140"
assert data.get("dir_version") == "v1-enhanced-140"
items = ((data.get("questions") or {}).get("items")) or data.get("items") or []
assert len(items) == 140, len(items)
'

landing_path="$(curl_json "/api/v0.5/landing-surfaces/tests?locale=${LOCALE}&org_id=0" | save_json landing_tests)"
assert_json "$landing_path" "landing surface canonical links" '
legacy_route = "/career/tests/" + "riasec"
legacy_copy_en = "36 question" + "s"
legacy_copy_zh = "36 " + "题"
payload = data.get("payload") or data.get("data") or data
text = json.dumps(payload, ensure_ascii=False)
assert "/tests/holland-career-interest-test-riasec" in text
assert legacy_route not in text
assert legacy_copy_en not in text
assert legacy_copy_zh not in text
assert "riasec_60" in text
assert "riasec_140" in text
'

echo "[riasec-launch] PASS"
