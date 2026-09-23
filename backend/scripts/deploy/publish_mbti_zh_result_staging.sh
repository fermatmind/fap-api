#!/usr/bin/env bash
set -euo pipefail

# This runs only in the staging job after the exact SHA is deployed. The existing
# release manifest is bound and checked by the policy job before this script runs.
: "${DEPLOY_SHA:?}" "${PACKAGE_HASH:?}" "${ADMIN_ACTOR:?}" "${OPERATION_KEY:?}"
: "${DEPLOY_PATH:?}" "${DEPLOY_HOST:?}" "${DEPLOY_PORT:?}" "${DEPLOY_USER:?}"
: "${DEPLOY_IDENTITY_FILE_STG:?}"
[[ "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$PACKAGE_HASH" =~ ^[0-9a-f]{64}$ ]]
[[ "$ADMIN_ACTOR" =~ ^[1-9][0-9]*$ ]]
[[ "$OPERATION_KEY" =~ ^[0-9a-f]{64}$ ]]

out=artifacts/mbti-zh-result-staging-publish
mkdir -p "$out"
printf -v q_path '%q' "$DEPLOY_PATH"
printf -v q_release '%q' "$DEPLOY_SHA"
ssh_base=(ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10
  -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -o IdentitiesOnly=yes
  -i "$DEPLOY_IDENTITY_FILE_STG" -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST")
remote_prefix="set -euo pipefail; current=\$(readlink -f $q_path/current); releases=\$(readlink -f $q_path/releases); test -n \"\$current\"; test -n \"\$releases\"; case \"\$current\" in \"\$releases\"/*) ;; *) exit 1 ;; esac; test \"\$(tr -d '\r\n' < \"\$current/REVISION\")\" = $q_release; test ! -e $q_path/.dep/deploy.lock; cd \"\$current/backend\";"
flags=(--staging-content-write-authorized --no-publication-change --no-indexability-change --no-sitemap --no-llms --no-search-release)
artisan() {
  "${ssh_base[@]}" "$remote_prefix sudo -n -u www-data -- php artisan personality:mbti-zh-result-content-release $* --no-interaction --no-ansi"
}
public_snapshot() {
  local output="$1" code slug digest
  : > "$output"
  for base in ENFJ ENFP ENTJ ENTP ESFJ ESFP ESTJ ESTP INFJ INFP INTJ INTP ISFJ ISFP ISTJ ISTP; do
    for axis in A T; do
      code="$base-$axis"
      slug="$(tr '[:upper:]' '[:lower:]' <<<"$code")"
      digest="$(curl --retry 3 --retry-all-errors --retry-delay 2 -fsS --connect-timeout 5 --max-time 30 \
        "https://staging-api.fermatmind.com/api/v0.5/personality/${slug}/desktop-clone?locale=zh-CN&org_id=0&scale_code=MBTI" \
        | jq -e -S -c --arg code "$code" 'select(.ok == true and .full_code == $code)' \
        | sha256sum | awk '{print $1}')"
      jq -cn --arg code "$code" --arg digest "$digest" '{full_code:$code,response_sha256:$digest}' >> "$output"
    done
  done
}
public_readback() {
  local output="$1" code slug
  : > "$output"
  for base in ENFJ ENFP ENTJ ENTP ESFJ ESFP ESTJ ESTP INFJ INFP INTJ INTP ISFJ ISFP ISTJ ISTP; do
    for axis in A T; do
      code="$base-$axis"
      slug="$(tr '[:upper:]' '[:lower:]' <<<"$code")"
      curl --retry 3 --retry-all-errors --retry-delay 2 -fsS --connect-timeout 5 --max-time 30 \
        "https://staging-api.fermatmind.com/api/v0.5/personality/${slug}/desktop-clone?locale=zh-CN&org_id=0&scale_code=MBTI" \
        | jq -c --arg code "$code" --arg package "$PACKAGE_HASH" '
            select(.ok == true and .full_code == $code
              and ._meta.package_hash == $package
              and (._meta.package_id | type == "string" and length > 0)
              and (._meta.source_hash | test("^[a-f0-9]{64}$"))
              and (._meta.revision_no | type == "number" and . > 0)
              and (.content.faq | length) == 4
              and (.asset_slots | length) == 7
              and ([.asset_slots[] | select(.status == "disabled" and .asset_ref == null and .alt == "")] | length) == 7)
            | {full_code,package_hash:._meta.package_hash,source_hash:._meta.source_hash,revision_no:._meta.revision_no,faq_count:4,disabled_slots:7}
          ' >> "$output"
    done
  done
  jq -s --arg package "$PACKAGE_HASH" '
    {record_count:length,disabled_slot_count:(map(.disabled_slots)|add // 0),faq_count:(map(.faq_count)|add // 0),
     unique_source_hashes:(map(.source_hash)|unique|length),
     ok:(length == 32 and (map(.full_code)|unique|length) == 32
       and (map(.package_hash)|unique) == [$package]
       and (map(.source_hash)|unique|length) == 32)}
  ' "$output" > "$out/public-readback.json"
  jq -e '.ok == true and .record_count == 32 and .disabled_slot_count == 224 and .faq_count == 128 and .unique_source_hashes == 32' "$out/public-readback.json" >/dev/null
}
receipt() {
  local status="$1" rollback="$2"
  jq -n --arg sha "$DEPLOY_SHA" --arg package "$PACKAGE_HASH" --arg operation "$OPERATION_KEY" \
    --arg status "$status" --argjson rollback "$rollback" '
    {contract_version:"mbti.zh_result_staging_publish.v1",status:$status,release_sha:$sha,
     package_hash:$package,operation_key:$operation,record_count:32,disabled_slot_count:224,
     public_api_readback_count:(if $status == "PASS" or $status == "PASS_ALREADY_ACTIVE" then 32 else 0 end),
     rollback_performed:$rollback,
     negative_guarantees:{publication_eligibility_changed:false,indexability_changed:false,
       sitemap_mutated:false,llms_mutated:false,search_channel_mutated:false}}
  ' > "$out/receipt.json"
}

artisan --stage=dry-run > "$out/dry-run.json"
jq -e --arg package "$PACKAGE_HASH" '.ok == true and .record_count == 32 and .package_hash == $package and .writes == false and .discoverability_changes == false' "$out/dry-run.json" >/dev/null
pre_state="$(jq -r .pre_state_hash "$out/dry-run.json")"
if [ "$(jq -r .already_active "$out/dry-run.json")" = true ]; then
  artisan --stage=readback --package-hash="$PACKAGE_HASH" > "$out/database-readback.json"
  jq -e '.ok == true and .record_count == 32 and .disabled_slot_count == 224' "$out/database-readback.json" >/dev/null
  public_readback "$out/public-rows.jsonl"
  receipt PASS_ALREADY_ACTIVE false
  exit 0
fi

public_snapshot "$out/public-pre-state.jsonl"
rollback_set=''
recover() {
  trap - ERR
  local rollback=false
  artisan --stage=dry-run > "$out/failed-state.json"
  if ! jq -e --arg pre "$pre_state" '.ok == true and .pre_state_hash == $pre and .already_active == false' "$out/failed-state.json" >/dev/null; then
    [[ "$rollback_set" =~ ^[0-9a-f]{64}$ ]]
    artisan --stage=rollback --package-hash="$PACKAGE_HASH" --pre-state-hash="$pre_state" --revision-set-hash="$rollback_set" "${flags[@]}" > "$out/rollback.json"
    rollback=true
  fi
  artisan --stage=dry-run > "$out/rollback-database-readback.json"
  jq -e --arg pre "$pre_state" '.ok == true and .pre_state_hash == $pre and .already_active == false' "$out/rollback-database-readback.json" >/dev/null
  public_snapshot "$out/public-restored-state.jsonl"
  diff -u "$out/public-pre-state.jsonl" "$out/public-restored-state.jsonl" > "$out/rollback-public-diff.txt"
  receipt FAIL_ROLLED_BACK "$rollback"
}
trap recover ERR
artisan --stage=draft --package-hash="$PACKAGE_HASH" --pre-state-hash="$pre_state" --admin-user-id="$ADMIN_ACTOR" "${flags[@]}" > "$out/draft.json"
jq -e '.ok == true and .stage == "draft_write" and .record_count == 32 and (.candidate_revisions|length) == 32 and (.rollback_revisions|length) == 32' "$out/draft.json" >/dev/null
rollback_set="$(jq -r .rollback_revision_set_hash "$out/draft.json")"
artisan --stage=promotion-dry-run > "$out/promotion-dry-run.json"
revision_set="$(jq -r .candidate_revision_set_hash "$out/promotion-dry-run.json")"
test "$(jq -r .rollback_revision_set_hash "$out/promotion-dry-run.json")" = "$rollback_set"
jq -e --arg package "$PACKAGE_HASH" --arg pre "$pre_state" '.ok == true and .package_hash == $package and .pre_state_hash == $pre and .record_count == 32' "$out/promotion-dry-run.json" >/dev/null
artisan --stage=promote --package-hash="$PACKAGE_HASH" --pre-state-hash="$pre_state" --revision-set-hash="$revision_set" "${flags[@]}" > "$out/promotion.json"
jq -e '.ok == true and .committed == true and .record_count == 32' "$out/promotion.json" >/dev/null
artisan --stage=readback --package-hash="$PACKAGE_HASH" > "$out/database-readback.json"
jq -e '.ok == true and .record_count == 32 and .disabled_slot_count == 224 and ([.revision_nos[]] | all(. > 0))' "$out/database-readback.json" >/dev/null
public_readback "$out/public-rows.jsonl"
trap - ERR
receipt PASS false
