#!/usr/bin/env bash

set -Eeuo pipefail

required_env=(
  DEPLOY_PATH
  EXPECTED_CONTROL_PLANE_SHA
  EXPECTED_RELEASE_SHA
  EXPECTED_RELEASE_NAME
  EXPECTED_MANIFEST_SHA256
  WORKFLOW_RUN_ID
  WORKFLOW_RUN_ATTEMPT
  PUBLIC_API_BASE_URL
  PUBLIC_WEB_BASE_URL
)

for name in "${required_env[@]}"; do
  test -n "${!name:-}"
done

[[ "$DEPLOY_PATH" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$DEPLOY_PATH" != *".."* ]]
[[ "$EXPECTED_CONTROL_PLANE_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$EXPECTED_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$EXPECTED_RELEASE_NAME" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]
[[ "$EXPECTED_MANIFEST_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$WORKFLOW_RUN_ID" =~ ^[1-9][0-9]*$ ]]
[[ "$WORKFLOW_RUN_ATTEMPT" =~ ^[1-9][0-9]*$ ]]
[[ "$PUBLIC_API_BASE_URL" =~ ^https://[A-Za-z0-9.-]+$ ]]
[[ "$PUBLIC_WEB_BASE_URL" =~ ^https://[A-Za-z0-9.-]+$ ]]

current_release="$(readlink -f "$DEPLOY_PATH/current")"
test -d "$current_release"
test "$(basename "$current_release")" = "$EXPECTED_RELEASE_NAME"
test -f "$current_release/REVISION"
deployed_revision="$(tr -d '\r\n' < "$current_release/REVISION")"
test "$deployed_revision" = "$EXPECTED_RELEASE_SHA"

backend_dir="$current_release/backend"
manifest_path="$backend_dir/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json"
test -f "$manifest_path"
observed_manifest_sha256="$(sha256sum "$manifest_path" | awk '{print $1}')"
test "$observed_manifest_sha256" = "$EXPECTED_MANIFEST_SHA256"

jq -e '
  .schema_version == "detail_ready_1046_rollout_manifest.v1" and
  .manifest_safe == true and
  .read_only == true and
  .writes_database == false and
  .apply_allowed == false and
  .rollout_apply_allowed == false and
  .current_public_detail_count == 30 and
  .clean_delta_count == 1016 and
  .target_public_total == 1046 and
  (.baseline_slugs | length) == 30 and
  (.delta_slugs | length) == 1016 and
  (.rollback_group | length) == 1016 and
  (.delta_slugs | index("accountants-and-auditors")) != null and
  (.delta_slugs | index("software-developers")) == null and
  (.delta_slugs | index("digital-forensics-analysts")) == null and
  (.delta_slugs | index("computer-occupations-all-other")) == null
' "$manifest_path" >/dev/null

artisan_commands="$(cd "$backend_dir" && php artisan list --raw --no-ansi)"
grep -q '^career:audit-detail-ready-1048-candidates' <<<"$artisan_commands"
grep -q '^career:audit-canonical-eligibility' <<<"$artisan_commands"
grep -q '^career:execute-canonical-rollout-batch' <<<"$artisan_commands"
rollout_help="$(cd "$backend_dir" && php artisan career:execute-canonical-rollout-batch --help --no-ansi)"
grep -q -- '--dry-run' <<<"$rollout_help"
grep -q -- '--no-audit-write' <<<"$rollout_help"

set +e
authority_payload="$(
  cd "$backend_dir" &&
  timeout 180 php -d memory_limit=768M <<'PHP' 2>/dev/null
<?php

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ledgerEnvelope = $app->make(App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService::class)->build();
$ledger = $ledgerEnvelope[App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? [];
$projection = $app->make(App\Domain\Career\Publish\CareerRuntimePublishProjectionService::class)->buildFromLedgerArray($ledger);
$truth = $app->make(App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter::class)->buildFromProjectionArray($projection);
$slug = 'accountants-and-auditors';

$ledgerRows = data_get($ledger, 'public_resolution.rows');
if (! is_array($ledgerRows)) {
    $ledgerRows = is_array($ledger['members'] ?? null) ? $ledger['members'] : [];
}

$pick = static function (array $row, array $keys): array {
    $result = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            $result[$key] = $row[$key];
        }
    }

    return $result;
};
$isAa = static fn (array $row): bool => strtolower(trim((string) ($row['source_slug'] ?? $row['canonical_slug'] ?? $row['slug'] ?? ''))) === $slug;

$aaLedger = [];
foreach ($ledgerRows as $row) {
    if (is_array($row) && $isAa($row)) {
        $aaLedger[] = $pick($row, [
            'source_slug', 'canonical_slug', 'slug', 'public_resolution_type', 'release_cohort',
            'public_eligible', 'indexability', 'public_index_state', 'index_state', 'index_eligible',
            'review_queue_status', 'reviewer_status', 'blockers', 'blocker_reasons', 'reason_codes',
        ]);
    }
}

$aaProjection = [];
foreach (($projection['items'] ?? []) as $row) {
    if (is_array($row) && ($row['slug'] ?? null) === $slug) {
        $aaProjection[] = $pick($row, [
            'slug', 'locale', 'public_resolution_type', 'runtime_publish_state', 'detail_route_enabled',
            'dataset_visible', 'search_visible', 'sitemap_live', 'llms_live', 'llms_full_live',
            'canonical_self', 'robots_indexable', 'release_gate_pass', 'blockers',
        ]);
    }
}

$aaTruth = [];
foreach (($truth['items'] ?? []) as $row) {
    if (is_array($row) && ($row['slug'] ?? null) === $slug) {
        $aaTruth[] = $pick($row, [
            'slug', 'locale', 'public_resolution_type', 'projection_state', 'route_exists', 'final_200',
            'robots_indexable', 'canonical_self', 'dataset_visible', 'search_visible', 'sitemap_live',
            'llms_live', 'llms_full_live', 'release_gate_pass', 'fully_live',
            'candidate_pre_route_expected', 'candidate_release_gate_applicability',
            'candidate_unexpected_exposures',
        ]);
    }
}

echo json_encode([
    'read_only' => true,
    'writes_database' => false,
    'ledger_counts' => $ledger['counts'] ?? null,
    'projection_counts' => $projection['counts'] ?? null,
    'truth_counts' => $truth['counts'] ?? null,
    'aa' => [
        'ledger' => $aaLedger,
        'projection' => $aaProjection,
        'truth' => $aaTruth,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP
)"
authority_exit_code=$?
set -e
authority_valid=false
authority_summary='null'
if jq -e '
  type == "object" and
  .read_only == true and
  .writes_database == false and
  (.aa.ledger | length) >= 1 and
  (.aa.projection | length) == 2 and
  ((.aa.truth | length) == 0 or (.aa.truth | length) == 2)
' <<<"$authority_payload" >/dev/null 2>&1; then
  authority_valid=true
  authority_summary="$(jq -c '{ledger_counts, projection_counts, truth_counts, aa}' <<<"$authority_payload")"
fi

set +e
scan_payload="$(
  cd "$backend_dir" &&
  timeout 180 php -d memory_limit=768M artisan career:audit-detail-ready-1048-candidates \
    --json --no-interaction --no-ansi 2>/dev/null
)"
scan_exit_code=$?
set -e
scan_valid=false
scan_summary='null'
if jq -e 'type == "object" and .writes_database == false' <<<"$scan_payload" >/dev/null 2>&1; then
  scan_valid=true
  scan_summary="$(jq -c '{status:(.status // "observed"), counts, writes_database, next_required_action}' <<<"$scan_payload")"
fi

set +e
eligibility_payload="$(
  cd "$backend_dir" &&
  timeout 180 php -d memory_limit=768M artisan career:audit-canonical-eligibility \
    --scope=slugs \
    --slugs=accountants-and-auditors \
    --locales=en,zh \
    --json --no-interaction --no-ansi 2>/dev/null
)"
eligibility_exit_code=$?
set -e
eligibility_valid=false
eligibility_summary='null'
if jq -e 'type == "object" and .read_only == true and .writes_database == false' <<<"$eligibility_payload" >/dev/null 2>&1; then
  eligibility_valid=true
  eligibility_summary="$(jq -c '{status, expected_occupations, audited_occupations, eligible_count, blocked_count, by_reason, rows:[.rows[]? | {slug, locale, overall_status, layers}]}' <<<"$eligibility_payload")"
fi

set +e
rollout_payload="$(
  cd "$backend_dir" &&
  timeout 180 php -d memory_limit=768M artisan career:execute-canonical-rollout-batch \
    --batch-id=detail-ready-1046-aa-canary-preflight \
    --slugs=accountants-and-auditors \
    --locales=en,zh \
    --rollback-group=accountants-and-auditors \
    --dry-run --no-audit-write --json --no-interaction --no-ansi 2>/dev/null
)"
rollout_exit_code=$?
set -e
rollout_valid=false
rollout_summary='null'
if jq -e 'type == "object" and .writes_database == false' <<<"$rollout_payload" >/dev/null 2>&1; then
  rollout_valid=true
  rollout_summary="$(jq -c '{status, reason, batch_id, dry_run, writes_database, promoted_locale_rows, occupation_count, missing_occupation_slugs, plan_validation:{status:.plan_validation.status, failures:.plan_validation.failures}, failures:[.failures[]? | {reason}]}' <<<"$rollout_payload")"
fi

job_index_en="$(curl -fsS --connect-timeout 5 --max-time 45 "$PUBLIC_API_BASE_URL/api/v0.5/career/jobs?locale=en")"
job_index_zh="$(curl -fsS --connect-timeout 5 --max-time 45 "$PUBLIC_API_BASE_URL/api/v0.5/career/jobs?locale=zh-CN")"
directory_en="$(curl -fsS --connect-timeout 5 --max-time 45 "$PUBLIC_API_BASE_URL/api/v0.5/career/directory?locale=en")"
directory_zh="$(curl -fsS --connect-timeout 5 --max-time 45 "$PUBLIC_API_BASE_URL/api/v0.5/career/directory?locale=zh-CN")"
job_index_en_count="$(jq -er '.items | length' <<<"$job_index_en")"
job_index_zh_count="$(jq -er '.items | length' <<<"$job_index_zh")"
directory_en_count="$(jq -er '.items | length' <<<"$directory_en")"
directory_zh_count="$(jq -er '.items | length' <<<"$directory_zh")"

seo_en_status="$(curl -sS --connect-timeout 5 --max-time 30 -o /dev/null -w '%{http_code}' "$PUBLIC_API_BASE_URL/api/v0.5/career-jobs/accountants-and-auditors/seo?locale=en")"
seo_zh_status="$(curl -sS --connect-timeout 5 --max-time 30 -o /dev/null -w '%{http_code}' "$PUBLIC_API_BASE_URL/api/v0.5/career-jobs/accountants-and-auditors/seo?locale=zh-CN")"
aa_en_status="$(curl -sS --connect-timeout 5 --max-time 30 -o /dev/null -w '%{http_code}' "$PUBLIC_WEB_BASE_URL/en/career/jobs/accountants-and-auditors")"
aa_zh_status="$(curl -sS --connect-timeout 5 --max-time 30 -o /dev/null -w '%{http_code}' "$PUBLIC_WEB_BASE_URL/zh/career/jobs/accountants-and-auditors")"
sitemap_payload="$(curl -fsS --connect-timeout 5 --max-time 45 "$PUBLIC_WEB_BASE_URL/sitemap.xml")"
sitemap_url_count="$(grep -o '<loc>' <<<"$sitemap_payload" | wc -l | tr -d ' ')"
sitemap_career_count="$(grep -oE '<loc>[^<]*/(en|zh)/career/jobs/[^<]+</loc>' <<<"$sitemap_payload" | wc -l | tr -d ' ')"
if grep -q '/career/jobs/accountants-and-auditors' <<<"$sitemap_payload"; then
  aa_in_sitemap=true
else
  aa_in_sitemap=false
fi

mem_available_kb="$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)"
test "$mem_available_kb" -gt 0
if (( mem_available_kb >= 4194304 )); then
  recommended_batch_size=100
elif (( mem_available_kb >= 2097152 )); then
  recommended_batch_size=50
elif (( mem_available_kb >= 1048576 )); then
  recommended_batch_size=25
else
  recommended_batch_size=10
fi
recommended_batch_count=$(( (1016 + recommended_batch_size - 1) / recommended_batch_size ))

if [[ "$authority_valid" == true && "$scan_valid" == true && "$eligibility_valid" == true && "$rollout_valid" == true ]]; then
  status='PASS_ZERO_WRITE_PREFLIGHT_CAPTURED'
else
  status='HOLD_ZERO_WRITE_PREFLIGHT_INCOMPLETE'
fi

checked_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
jq -n \
  --arg status "$status" \
  --arg control_plane_sha "$EXPECTED_CONTROL_PLANE_SHA" \
  --arg release_sha "$EXPECTED_RELEASE_SHA" \
  --arg release_name "$EXPECTED_RELEASE_NAME" \
  --arg manifest_sha256 "$observed_manifest_sha256" \
  --arg checked_at "$checked_at" \
  --argjson workflow_run_id "$WORKFLOW_RUN_ID" \
  --argjson workflow_run_attempt "$WORKFLOW_RUN_ATTEMPT" \
  --argjson authority_exit_code "$authority_exit_code" \
  --argjson authority_valid "$authority_valid" \
  --argjson authority "$authority_summary" \
  --argjson scan_exit_code "$scan_exit_code" \
  --argjson scan_valid "$scan_valid" \
  --argjson scan "$scan_summary" \
  --argjson eligibility_exit_code "$eligibility_exit_code" \
  --argjson eligibility_valid "$eligibility_valid" \
  --argjson eligibility "$eligibility_summary" \
  --argjson rollout_exit_code "$rollout_exit_code" \
  --argjson rollout_valid "$rollout_valid" \
  --argjson rollout "$rollout_summary" \
  --argjson job_index_en_count "$job_index_en_count" \
  --argjson job_index_zh_count "$job_index_zh_count" \
  --argjson directory_en_count "$directory_en_count" \
  --argjson directory_zh_count "$directory_zh_count" \
  --arg seo_en_status "$seo_en_status" \
  --arg seo_zh_status "$seo_zh_status" \
  --arg aa_en_status "$aa_en_status" \
  --arg aa_zh_status "$aa_zh_status" \
  --argjson sitemap_url_count "$sitemap_url_count" \
  --argjson sitemap_career_count "$sitemap_career_count" \
  --argjson aa_in_sitemap "$aa_in_sitemap" \
  --argjson mem_available_kb "$mem_available_kb" \
  --argjson recommended_batch_size "$recommended_batch_size" \
  --argjson recommended_batch_count "$recommended_batch_count" \
  '{
    schema_version: "career.1046_rollout.zero_write_preflight.v1",
    status: $status,
    checked_at: $checked_at,
    control_plane_sha: $control_plane_sha,
    workflow_run_id: $workflow_run_id,
    workflow_run_attempt: $workflow_run_attempt,
    release: {
      sha: $release_sha,
      name: $release_name,
      revision_match: true
    },
    authority_manifest: {
      sha256: $manifest_sha256,
      current_public_detail_count: 30,
      clean_delta_count: 1016,
      target_public_total: 1046,
      aa_canary_in_delta: true,
      excluded_manual_hold_preserved: true,
      excluded_conflicts_preserved: true,
      apply_allowed: false,
      rollout_apply_allowed: false
    },
    production_read_only_observations: {
      aa_projection_truth_review_authority: {exit_code: $authority_exit_code, valid: $authority_valid, summary: $authority},
      detail_ready_scan: {exit_code: $scan_exit_code, valid: $scan_valid, summary: $scan},
      aa_eligibility: {exit_code: $eligibility_exit_code, valid: $eligibility_valid, summary: $eligibility},
      aa_rollout_dry_run: {exit_code: $rollout_exit_code, valid: $rollout_valid, summary: $rollout},
      public_read_models: {
        job_index: {en: $job_index_en_count, zh_CN: $job_index_zh_count},
        directory: {en: $directory_en_count, zh_CN: $directory_zh_count}
      },
      aa_public_surface: {
        seo_authority_http: {en: $seo_en_status, zh_CN: $seo_zh_status},
        page_http: {en: $aa_en_status, zh_CN: $aa_zh_status},
        sitemap_present: $aa_in_sitemap
      },
      sitemap: {url_count: $sitemap_url_count, career_detail_count: $sitemap_career_count}
    },
    pipeline_boundaries: {
      legacy_canonical_rollout: "candidate truth plus projection plus governance review authority",
      search_entry_quality_pipeline: "review and search-entry eligibility only; not publication authority",
      cross_pipeline_inference_allowed: false
    },
    memory_batch_plan: {
      measurement: "MemAvailable snapshot only; no warm or full rollout executed",
      mem_available_kb: $mem_available_kb,
      php_memory_limit_mb: 768,
      command_timeout_seconds: 180,
      aa_canary_batch_size: 1,
      recommended_delta_batch_size: $recommended_batch_size,
      recommended_delta_batch_count: $recommended_batch_count,
      stop_on_timeout_or_non_json: true,
      upgrade_required: "not_decided_by_this_preflight"
    },
    negative_guarantees: {
      production_apply: false,
      database_write: false,
      cms_write: false,
      publication: false,
      warm: false,
      expansion: false,
      deploy: false,
      sitemap_write: false,
      llms_write: false,
      search_channel_action: false,
      url_submission: false,
      remote_file_write: false,
      raw_log_read: false
    },
    apply_authorized: false,
    writes_committed: false,
    automatic_retry_allowed: false
  }'
