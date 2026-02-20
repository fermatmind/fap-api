#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

jget () { curl -sS -H "Accept: application/json" "$1"; }
jpost () {
  local url="$1"
  local data="$2"
  curl -sS -H "Accept: application/json" -H "Content-Type: application/json" -X POST "$url" -d "$data"
}

save_json () {
  local name="$1"
  local payload="$2"
  local path="$TMP_DIR/$name.json"
  printf "%s" "$payload" > "$path"
  echo "$path"
}

pp () { python3 -m json.tool < "$1"; }

# 用 tinker 读 DB 行数（不依赖 mysql/sqlite 客户端）
count_events ()  { php artisan tinker --execute="echo \\App\\Models\\Event::count();"   | tr -d '\r\n '; }
count_results () { php artisan tinker --execute="echo \\App\\Models\\Result::count();" | tr -d '\r\n '; }
count_attempts(){ php artisan tinker --execute="echo \\App\\Models\\Attempt::count();"| tr -d '\r\n '; }

echo "BASE_URL=$BASE_URL"
echo

echo "0) Baseline DB counts"
E0="$(count_events)"; R0="$(count_results)"; A0="$(count_attempts)"
echo "events=$E0 results=$R0 attempts=$A0"
echo

echo "1) Health: $BASE_URL/api/healthz"
H="$(jget "$BASE_URL/api/healthz")"
Hf="$(save_json health "$H")"
pp "$Hf"
python3 - "$Hf" <<'PY'
import json,sys
d=json.load(open(sys.argv[1], encoding="utf-8"))
assert d.get("ok") is True, "health.ok must be true"
print("health ok ✅")
PY
echo

echo "2) Scale meta: $BASE_URL/api/v0.3/scales/MBTI"
S="$(jget "$BASE_URL/api/v0.3/scales/MBTI")"
Sf="$(save_json scale "$S")"
pp "$Sf"
python3 - "$Sf" <<'PY'
import json,sys
d=json.load(open(sys.argv[1], encoding="utf-8"))
assert d.get("scale_code")=="MBTI", "scale_code must be MBTI"
assert d.get("version")=="v0.2", "version must be v0.2"
print("scale meta ok ✅")
PY
echo

echo "3) Questions: $BASE_URL/api/v0.3/scales/MBTI/questions"
Q="$(jget "$BASE_URL/api/v0.3/scales/MBTI/questions")"
Qf="$(save_json questions "$Q")"
python3 - "$Qf" <<'PY'
import json,sys
d=json.load(open(sys.argv[1], encoding="utf-8"))
items=d.get("items") or []
assert isinstance(items,list), "items must be array"
assert len(items)>=1, "questions.items must have >=1 item"
print("question_items_count=",len(items))
print("first_question_id=",items[0].get("question_id"))
PY
echo

echo "4) POST attempt: $BASE_URL/api/v0.3/attempts"
ANON="smoke-$(date +%s)"
qids_json=$(curl -sS "$BASE_URL/api/v0.3/scales/MBTI/questions" | jq -c '[.items[].question_id]')
export QIDS_JSON="$qids_json"
answers_json=$(python3 - <<'PY'
import json, os
qids = json.loads(os.environ["QIDS_JSON"])
answers = [{"question_id": qid, "code": "A"} for qid in qids]
print(json.dumps(answers, ensure_ascii=False))
PY
)
export ANSWERS_JSON="$answers_json"
export ANON_ID="$ANON"
POST_BODY="$(python3 - <<'PY'
import json, os
answers = json.loads(os.environ["ANSWERS_JSON"])
payload = {
  "anon_id": os.environ["ANON_ID"],
  "scale_code": "MBTI",
  "scale_version": "v0.2",
  "client_platform": "smoke",
  "client_version": "strict",
  "channel": "dev",
  "answers": answers,
}
print(json.dumps(payload, ensure_ascii=False))
PY
)"
P="$(jpost "$BASE_URL/api/v0.3/attempts" "$POST_BODY")"
Pf="$(save_json post_attempt "$P")"
pp "$Pf"

ATTEMPT_ID="$(python3 - "$Pf" <<'PY'
import json,sys
d=json.load(open(sys.argv[1], encoding="utf-8"))
assert d.get("ok") is True, "post.ok must be true"
aid=d.get("attempt_id")
assert aid, "attempt_id required"
print(aid)
PY
)"
echo "ATTEMPT_ID=$ATTEMPT_ID"

python3 - "$Pf" <<'PY'
import json,sys
d=json.load(open(sys.argv[1], encoding="utf-8"))
res=d.get("result") or {}
dims=["EI","SN","TF","JP","AT"]
scores=res.get("scores") or {}
scores_pct=res.get("scores_pct") or {}
axis_states=res.get("axis_states") or {}
for k in dims:
  assert k in scores, f"result.scores missing {k}"
  assert k in scores_pct, f"result.scores_pct missing {k}"
  assert k in axis_states, f"result.axis_states missing {k}"
assert res.get("content_package_version"), "result.content_package_version required"
assert res.get("profile_version"), "result.profile_version required"
print("POST attempt payload ok (5 axes + versions) ✅")
PY

E1="$(count_events)"; R1="$(count_results)"; A1="$(count_attempts)"
python3 - <<PY
E0=int("$E0");R0=int("$R0");A0=int("$A0")
E1=int("$E1");R1=int("$R1");A1=int("$A1")
assert A1==A0+1, f"attempts should +1 after POST (got {A0}->{A1})"
assert R1==R0+1, f"results should +1 after POST (got {R0}->{R1})"
assert E1==E0+1, f"events should +1 after POST (test_submit) (got {E0}->{E1})"
print("DB delta after POST ok ✅")
PY
echo

echo "5) GET result: $BASE_URL/api/v0.3/attempts/$ATTEMPT_ID/result"
GR_BEFORE_R="$R1"
GR_BEFORE_E="$E1"

G="$(jget "$BASE_URL/api/v0.3/attempts/$ATTEMPT_ID/result")"
Gf="$(save_json get_result "$G")"
pp "$Gf"

python3 - "$Gf" <<'PY'
import json,sys
d=json.load(open(sys.argv[1], encoding="utf-8"))
assert d.get("ok") is True, "getResult.ok must be true"
dims=["EI","SN","TF","JP","AT"]
scores=d.get("scores") or {}
scores_pct=d.get("scores_pct") or {}
axis_states=d.get("axis_states") or {}
for k in dims:
  assert k in scores, f"scores missing {k}"
  assert k in scores_pct, f"scores_pct missing {k}"
  assert k in axis_states, f"axis_states missing {k}"
assert d.get("content_package_version"), "content_package_version required"
assert d.get("profile_version"), "profile_version required"
print("GET result payload ok (5 axes + versions) ✅")
PY

E2="$(count_events)"; R2="$(count_results)"
python3 - <<PY
E1=int("$GR_BEFORE_E");R1=int("$GR_BEFORE_R")
E2=int("$E2");R2=int("$R2")
assert R2==R1, f"results must NOT change on GET result (got {R1}->{R2})"
assert E2==E1+1, f"events should +1 on GET result (result_view) (got {E1}->{E2})"
print("DB delta after GET result ok ✅")
PY
echo

echo "6) GET share: $BASE_URL/api/v0.3/attempts/$ATTEMPT_ID/share"
GS_BEFORE_R="$R2"
GS_BEFORE_E="$E2"

SHARE="$(jget "$BASE_URL/api/v0.3/attempts/$ATTEMPT_ID/share")"
Sf2="$(save_json get_share "$SHARE")"
pp "$Sf2"

python3 - "$Sf2" <<'PY'
import json,sys
d=json.load(open(sys.argv[1], encoding="utf-8"))
assert d.get("ok") is True, "getShare.ok must be true"
assert d.get("content_package_version"), "share.content_package_version required"
assert d.get("type_code"), "share.type_code required"
assert d.get("type_name"), "share.type_name required (from type_profiles)"
assert d.get("tagline"), "share.tagline required (from type_profiles)"
assert d.get("rarity"), "share.rarity required (from type_profiles)"
kw=d.get("keywords")
assert isinstance(kw,list) and len(kw)>=1, "share.keywords must be non-empty list (from type_profiles)"
assert d.get("short_summary"), "share.short_summary required (from type_profiles)"
print("GET share payload ok (content + type_profiles fields) ✅")
PY

E3="$(count_events)"; R3="$(count_results)"
python3 - <<PY
E2=int("$GS_BEFORE_E");R2=int("$GS_BEFORE_R")
E3=int("$E3");R3=int("$R3")
assert R3==R2, f"results must NOT change on GET share (got {R2}->{R3})"
delta = E3 - E2
assert delta >= 1, f"events should increase on GET share (share_view) (got {E2}->{E3})"
print(f"events delta on GET share = +{delta}")
print("DB delta after GET share ok ✅")
PY
echo

echo "DONE ✅ strict smoke passed"
