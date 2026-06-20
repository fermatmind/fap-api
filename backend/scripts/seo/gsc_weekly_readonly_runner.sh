#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
ARTIFACT_DIR="${ARTIFACT_DIR:-/opt/fermatmind/seo-gsc-runner/artifacts}"
WINDOW_DAYS="${WINDOW_DAYS:-28}"
LIMIT="${LIMIT:-250}"
DIMENSIONS="${DIMENSIONS:-query,page}"
END_DATE="${END_DATE:-$(date -u -d '3 days ago' +%F)}"
START_DATE="${START_DATE:-$(date -u -d "${END_DATE} - $((WINDOW_DAYS - 1)) days" +%F)}"
TS="$(date -u +%Y%m%dT%H%M%SZ)"

fail() {
  printf 'error=%s\n' "$1" >&2
  exit 1
}

case "${WINDOW_DAYS}" in
  7|28) ;;
  *) fail "weekly_window_days_must_be_7_or_28" ;;
esac

case "${LIMIT}" in
  ''|*[!0-9]*) fail "weekly_limit_must_be_integer" ;;
  *) ;;
esac

if (( LIMIT < 1 || LIMIT > 250 )); then
  fail "weekly_limit_must_be_between_1_and_250"
fi

if [[ "${DIMENSIONS}" != "query,page" ]]; then
  fail "weekly_dimensions_must_be_query_page"
fi

mkdir -p "${ARTIFACT_DIR}"
cd "${BACKEND_DIR}"

PREFLIGHT_LOG="${ARTIFACT_DIR}/gsc-weekly-readonly-preflight-${TS}.log"
LIVE_LOG="${ARTIFACT_DIR}/gsc-weekly-readonly-live-read-${TS}.log"
DRYRUN_ARTIFACT="${ARTIFACT_DIR}/gsc-weekly-readonly-dryrun-${TS}.json"
EVIDENCE_ARTIFACT="${ARTIFACT_DIR}/gsc-weekly-readonly-opportunity-precheck-${TS}.json"

scripts/seo/gsc_sidecar_runner.sh \
  --mode=preflight \
  --artifact-dir="${ARTIFACT_DIR}" >"${PREFLIGHT_LOG}" 2>&1

scripts/seo/gsc_sidecar_runner.sh \
  --mode=live-read \
  --start-date="${START_DATE}" \
  --end-date="${END_DATE}" \
  --limit="${LIMIT}" \
  --dimensions="${DIMENSIONS}" \
  --artifact-dir="${ARTIFACT_DIR}" >"${LIVE_LOG}" 2>&1

LIVE_ARTIFACT="$(ls -t "${ARTIFACT_DIR}"/gsc-live-read-wrapper-*-success.json 2>/dev/null | head -1)"

php artisan seo-intel:gsc-readmodel-import-dry-run \
  --artifact="${LIVE_ARTIFACT}" \
  --limit="${LIMIT}" \
  --dry-run \
  --json >"${DRYRUN_ARTIFACT}"

python3 - "${LIVE_ARTIFACT}" "${DRYRUN_ARTIFACT}" "${EVIDENCE_ARTIFACT}" "${PREFLIGHT_LOG}" "${LIVE_LOG}" "${START_DATE}" "${END_DATE}" "${WINDOW_DAYS}" "${LIMIT}" <<'PY'
import datetime
import hashlib
import json
import os
import sys

live_path, dryrun_path, evidence_path, preflight_log, live_log, start_date, end_date, window_days, limit = sys.argv[1:]

def load_json(path):
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)

def file_stat(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            digest.update(chunk)
    return {
        "path": path,
        "size": os.path.getsize(path),
        "sha256": digest.hexdigest(),
    }

def data_get(value, dotted, default=None):
    current = value
    for part in dotted.split("."):
        if isinstance(current, dict) and part in current:
            current = current[part]
        else:
            return default
    return current

dryrun = load_json(dryrun_path)
live = load_json(live_path)
rows = dryrun.get("preview_rows") or []
miss_reasons = {}
candidate_rows = []

for index, row in enumerate(rows, 1):
    reasons = []
    impressions = int(row.get("impressions") or 0)
    clicks = int(row.get("clicks") or 0)
    ctr_ppm = row.get("ctr_ppm")
    position_milli = row.get("average_position_milli")
    is_brand = bool(row.get("is_brand_query"))
    query_type = str(row.get("query_type") or "unknown")

    if impressions < 50:
        reasons.append("impressions_below_50")
    if ctr_ppm is None:
        reasons.append("ctr_ppm_missing")
    elif int(ctr_ppm) > 10000:
        reasons.append("ctr_ppm_above_10000")
    if position_milli is None:
        reasons.append("position_milli_missing")
    elif int(position_milli) < 8000:
        reasons.append("position_above_window_rank_better_than_8")
    elif int(position_milli) > 20000:
        reasons.append("position_below_window_rank_worse_than_20")
    if is_brand:
        reasons.append("brand_query")
    if query_type != "non_brand":
        reasons.append("query_type_not_non_brand")

    if reasons:
        for reason in reasons:
            miss_reasons[reason] = miss_reasons.get(reason, 0) + 1
    else:
        candidate_rows.append({
            "row_index": index,
            "report_date": row.get("report_date"),
            "canonical_url_hash": row.get("canonical_url_hash"),
            "query_hash": row.get("query_hash"),
            "query_display_masked": row.get("query_display_masked"),
            "impressions": impressions,
            "clicks": clicks,
            "ctr_ppm": ctr_ppm,
            "average_position_milli": position_milli,
            "query_type": query_type,
            "is_brand_query": is_brand,
        })

if candidate_rows:
    diagnosis = "has_current_threshold_candidates"
elif not rows:
    diagnosis = "no_rows_returned_or_importer_blocked"
elif miss_reasons.get("impressions_below_50", 0) == len(rows):
    diagnosis = "data_volume_insufficient_all_rows_below_impression_threshold"
else:
    diagnosis = "mixed_threshold_miss"

evidence = {
    "schema_version": "gsc-weekly-readonly-run.v1",
    "task": "SEO-GSC-WEEKLY-READONLY-AUTOMATION-PLAN-01",
    "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "mode": "manual_weekly_readonly_runner",
    "date_window": {
        "start_date": start_date,
        "end_date": end_date,
        "window_days": int(window_days),
    },
    "runtime": {
        "artifact_dir": os.path.dirname(evidence_path),
        "limit": int(limit),
        "dimensions": "query,page",
    },
    "artifacts": {
        "preflight_log": file_stat(preflight_log),
        "live_log": file_stat(live_log),
        "live_read": file_stat(live_path),
        "dryrun_importer": file_stat(dryrun_path),
    },
    "live_read": {
        "data_origin": data_get(live, "payload.metadata.data_origin"),
        "data_quality_gate": data_get(live, "payload.metadata.data_quality_gate.status"),
        "items_seen": data_get(live, "payload.items_seen") or data_get(live, "payload.metadata.items_seen"),
    },
    "dryrun_importer": {
        "ok": dryrun.get("ok"),
        "would_write": dryrun.get("would_write"),
        "rows_previewed": dryrun.get("rows_previewed"),
        "rows_would_insert": dryrun.get("rows_would_insert"),
        "data_origin": dryrun.get("data_origin"),
        "data_quality_gate": dryrun.get("data_quality_gate"),
    },
    "opportunity_contract": {
        "min_impressions": 50,
        "max_ctr_ppm": 10000,
        "position_milli_window": [8000, 20000],
        "query_type_required": "non_brand",
        "brand_query_allowed": False,
    },
    "opportunity_precheck": {
        "rows_evaluated": len(rows),
        "candidate_count": len(candidate_rows),
        "top_candidates_sanitized": sorted(
            candidate_rows,
            key=lambda item: (item["impressions"], -(item["ctr_ppm"] or 0)),
            reverse=True,
        )[:10],
        "miss_reason_counts": dict(sorted(miss_reasons.items())),
        "diagnosis": diagnosis,
    },
    "negative_guarantees": {
        "database_write": False,
        "seo_gsc_daily_write": False,
        "controlled_import_execute": False,
        "scheduler_activation": False,
        "queue_worker_started": False,
        "opportunity_queue_enqueue": False,
        "cms_write": False,
        "search_channel_submit": False,
        "indexing_request": False,
        "google_indexing_api_call": False,
        "live_gsc_api_call": True,
    },
}

with open(evidence_path, "w", encoding="utf-8") as handle:
    json.dump(evidence, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
PY

python3 -m json.tool "${LIVE_ARTIFACT}" >/dev/null
python3 -m json.tool "${DRYRUN_ARTIFACT}" >/dev/null
python3 -m json.tool "${EVIDENCE_ARTIFACT}" >/dev/null

printf 'evidence_path=%s\n' "${EVIDENCE_ARTIFACT}"
wc -c "${EVIDENCE_ARTIFACT}" | awk '{print "evidence_size="$1}'
sha256sum "${EVIDENCE_ARTIFACT}" | awk '{print "evidence_sha256="$1}'
