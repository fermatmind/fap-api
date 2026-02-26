#!/usr/bin/env bash
set -euo pipefail

export CI="${CI:-true}"
export FAP_NONINTERACTIVE="${FAP_NONINTERACTIVE:-1}"
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ARTIFACT_ROOT="${ARTIFACT_ROOT:-${BACKEND_DIR}/artifacts/pr78}"

RUN_TS="$(date -u '+%Y%m%dT%H%M%SZ')"
WEEK_TAG="$(date -u '+%G-W%V')"
RUN_DIR="${ARTIFACT_ROOT}/runs/${RUN_TS}"
WEEKLY_DIR="${ARTIFACT_ROOT}/weekly/${WEEK_TAG}"
LATEST_DIR="${ARTIFACT_ROOT}/latest"

AUDIT_JSON="${RUN_DIR}/composer_audit.json"
AUDIT_STDERR="${RUN_DIR}/composer_audit.stderr.log"
NORMALIZED_JSON="${RUN_DIR}/advisories_normalized.json"
SUMMARY_JSON="${RUN_DIR}/summary.json"
SUMMARY_TXT="${RUN_DIR}/summary.txt"
DIFF_JSON="${RUN_DIR}/diff.json"
DIFF_TXT="${RUN_DIR}/diff.txt"
PREV_SUMMARY_JSON="${LATEST_DIR}/summary.json"

mkdir -p "${RUN_DIR}" "${WEEKLY_DIR}" "${LATEST_DIR}"
cd "${BACKEND_DIR}"

fail() {
  echo "[PR78][VERIFY][FAIL] $*"
  exit 1
}

echo "[PR78][VERIFY] start"
echo "[PR78][VERIFY] run_ts=${RUN_TS} week=${WEEK_TAG}"

AUDIT_EXIT=0
for attempt in 1 2 3; do
  set +e
  composer audit --no-interaction --format=json >"${AUDIT_JSON}" 2>"${AUDIT_STDERR}"
  AUDIT_EXIT=$?
  set -e

  if [[ -s "${AUDIT_JSON}" ]]; then
    break
  fi

  if [[ "${attempt}" -lt 3 ]]; then
    echo "[PR78][VERIFY] composer audit produced no JSON (attempt ${attempt}/3), retrying in 5s..."
    sleep 5
  fi
done

if [[ ! -s "${AUDIT_JSON}" ]]; then
  fail "composer audit did not produce JSON output after retries"
fi

AUDIT_JSON_PATH="${AUDIT_JSON}" NORMALIZED_JSON_PATH="${NORMALIZED_JSON}" SUMMARY_JSON_PATH="${SUMMARY_JSON}" php <<'PHP'
<?php
declare(strict_types=1);

$auditJsonPath = (string) getenv('AUDIT_JSON_PATH');
$normalizedPath = (string) getenv('NORMALIZED_JSON_PATH');
$summaryPath = (string) getenv('SUMMARY_JSON_PATH');
$payload = json_decode((string) file_get_contents($auditJsonPath), true);
if (!is_array($payload)) {
    fwrite(STDERR, "[PR78][VERIFY][FAIL] invalid composer audit JSON payload\n");
    exit(2);
}

$rawAdvisories = $payload['advisories'] ?? [];
$normalized = [];

$appendRow = static function (array $row, string $defaultPackage) use (&$normalized): void {
    $severity = strtolower(trim((string) ($row['severity'] ?? 'unknown')));
    if ($severity === '') {
        $severity = 'unknown';
    }

    $normalized[] = [
        'package' => (string) ($row['packageName'] ?? $row['package'] ?? $defaultPackage),
        'advisory_id' => (string) ($row['advisoryId'] ?? $row['advisory_id'] ?? ''),
        'cve' => (string) ($row['cve'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'link' => (string) ($row['link'] ?? ''),
        'severity' => $severity,
        'affected_versions' => (string) ($row['affectedVersions'] ?? $row['affected_versions'] ?? ''),
        'reported_at' => (string) ($row['reportedAt'] ?? $row['reported_at'] ?? ''),
    ];
};

if (is_array($rawAdvisories)) {
    if (array_is_list($rawAdvisories)) {
        foreach ($rawAdvisories as $row) {
            if (is_array($row)) {
                $appendRow($row, 'unknown-package');
            }
        }
    } else {
        foreach ($rawAdvisories as $package => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $appendRow($row, (string) $package);
                }
            }
        }
    }
}

usort(
    $normalized,
    static function (array $a, array $b): int {
        return [$a['package'], $a['severity'], $a['advisory_id'], $a['cve'], $a['title']]
            <=> [$b['package'], $b['severity'], $b['advisory_id'], $b['cve'], $b['title']];
    }
);

$severityCounts = [
    'critical' => 0,
    'high' => 0,
    'medium' => 0,
    'low' => 0,
    'unknown' => 0,
];

foreach ($normalized as $item) {
    $sev = $item['severity'];
    if (!array_key_exists($sev, $severityCounts)) {
        $sev = 'unknown';
    }
    $severityCounts[$sev]++;
}

$abandoned = $payload['abandoned'] ?? [];
$abandonedCount = is_array($abandoned) ? count($abandoned) : 0;

$summary = [
    'generated_at_utc' => gmdate('c'),
    'total' => count($normalized),
    'high_or_critical' => $severityCounts['critical'] + $severityCounts['high'],
    'by_severity' => $severityCounts,
    'abandoned_count' => $abandonedCount,
    'advisories' => $normalized,
];

file_put_contents(
    $normalizedPath,
    json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
file_put_contents(
    $summaryPath,
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
PHP

if [[ -f "${PREV_SUMMARY_JSON}" ]]; then
  PREV_SUMMARY_PATH="${PREV_SUMMARY_JSON}" CURR_SUMMARY_PATH="${SUMMARY_JSON}" DIFF_JSON_PATH="${DIFF_JSON}" DIFF_TXT_PATH="${DIFF_TXT}" php <<'PHP'
<?php
declare(strict_types=1);

$prevPath = (string) getenv('PREV_SUMMARY_PATH');
$currPath = (string) getenv('CURR_SUMMARY_PATH');
$diffJsonPath = (string) getenv('DIFF_JSON_PATH');
$diffTxtPath = (string) getenv('DIFF_TXT_PATH');
$prev = json_decode((string) file_get_contents($prevPath), true);
$curr = json_decode((string) file_get_contents($currPath), true);

if (!is_array($prev)) {
    $prev = ['advisories' => []];
}
if (!is_array($curr)) {
    fwrite(STDERR, "[PR78][VERIFY][FAIL] current summary parse failed\n");
    exit(2);
}

$prevList = is_array($prev['advisories'] ?? null) ? $prev['advisories'] : [];
$currList = is_array($curr['advisories'] ?? null) ? $curr['advisories'] : [];

$toMap = static function (array $rows): array {
    $map = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = implode('|', [
            (string) ($row['package'] ?? ''),
            (string) ($row['advisory_id'] ?? ''),
            (string) ($row['cve'] ?? ''),
            (string) ($row['title'] ?? ''),
        ]);
        $map[$key] = $row;
    }
    return $map;
};

$prevMap = $toMap($prevList);
$currMap = $toMap($currList);

$new = [];
$resolved = [];
$severityChanged = [];

foreach ($currMap as $k => $row) {
    if (!array_key_exists($k, $prevMap)) {
        $new[] = $row;
        continue;
    }
    $prevSeverity = (string) ($prevMap[$k]['severity'] ?? 'unknown');
    $currSeverity = (string) ($row['severity'] ?? 'unknown');
    if ($prevSeverity !== $currSeverity) {
        $severityChanged[] = [
            'key' => $k,
            'from' => $prevSeverity,
            'to' => $currSeverity,
            'package' => (string) ($row['package'] ?? ''),
            'advisory_id' => (string) ($row['advisory_id'] ?? ''),
            'cve' => (string) ($row['cve'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
        ];
    }
}

foreach ($prevMap as $k => $row) {
    if (!array_key_exists($k, $currMap)) {
        $resolved[] = $row;
    }
}

$diff = [
    'generated_at_utc' => gmdate('c'),
    'current_total' => (int) ($curr['total'] ?? count($currList)),
    'previous_total' => (int) ($prev['total'] ?? count($prevList)),
    'new_count' => count($new),
    'resolved_count' => count($resolved),
    'severity_changed_count' => count($severityChanged),
    'new' => $new,
    'resolved' => $resolved,
    'severity_changed' => $severityChanged,
];

file_put_contents(
    $diffJsonPath,
    json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$lines = [];
$lines[] = 'current_total=' . $diff['current_total'];
$lines[] = 'previous_total=' . $diff['previous_total'];
$lines[] = 'new=' . $diff['new_count'];
$lines[] = 'resolved=' . $diff['resolved_count'];
$lines[] = 'severity_changed=' . $diff['severity_changed_count'];
if ($diff['new_count'] > 0) {
    foreach ($new as $row) {
        $lines[] = 'NEW ' . (string) ($row['package'] ?? '') . '|' . (string) ($row['severity'] ?? '') . '|' . (string) ($row['advisory_id'] ?? '') . '|' . (string) ($row['cve'] ?? '');
    }
}
if ($diff['resolved_count'] > 0) {
    foreach ($resolved as $row) {
        $lines[] = 'RESOLVED ' . (string) ($row['package'] ?? '') . '|' . (string) ($row['severity'] ?? '') . '|' . (string) ($row['advisory_id'] ?? '') . '|' . (string) ($row['cve'] ?? '');
    }
}
if ($diff['severity_changed_count'] > 0) {
    foreach ($severityChanged as $row) {
        $lines[] = 'CHANGED ' . (string) ($row['package'] ?? '') . '|' . (string) ($row['advisory_id'] ?? '') . '|' . (string) ($row['from'] ?? '') . '->' . (string) ($row['to'] ?? '');
    }
}

file_put_contents($diffTxtPath, implode(PHP_EOL, $lines) . PHP_EOL);
PHP
else
  cat > "${DIFF_JSON}" <<'EOF'
{
  "generated_at_utc": null,
  "baseline_initialized": true,
  "current_total": 0,
  "previous_total": 0,
  "new_count": 0,
  "resolved_count": 0,
  "severity_changed_count": 0,
  "new": [],
  "resolved": [],
  "severity_changed": []
}
EOF
  cat > "${DIFF_TXT}" <<'EOF'
baseline_initialized=true
new=0
resolved=0
severity_changed=0
EOF
fi

cp "${AUDIT_JSON}" "${WEEKLY_DIR}/composer_audit_${RUN_TS}.json"
cp "${SUMMARY_JSON}" "${WEEKLY_DIR}/summary_${RUN_TS}.json"
cp "${DIFF_TXT}" "${WEEKLY_DIR}/diff_${RUN_TS}.txt"

cp "${AUDIT_JSON}" "${LATEST_DIR}/composer_audit.json"
cp "${SUMMARY_JSON}" "${LATEST_DIR}/summary.json"
cp "${DIFF_TXT}" "${LATEST_DIR}/diff.txt"

TOTAL_ADVISORIES="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string) ((int)($j["total"] ?? 0));' "${SUMMARY_JSON}")"
HIGH_OR_CRITICAL="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string) ((int)($j["high_or_critical"] ?? 0));' "${SUMMARY_JSON}")"
ABANDONED_COUNT="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string) ((int)($j["abandoned_count"] ?? 0));' "${SUMMARY_JSON}")"

{
  echo "run_ts=${RUN_TS}"
  echo "week=${WEEK_TAG}"
  echo "audit_exit_code=${AUDIT_EXIT}"
  echo "total_advisories=${TOTAL_ADVISORIES}"
  echo "high_or_critical=${HIGH_OR_CRITICAL}"
  echo "abandoned_count=${ABANDONED_COUNT}"
  echo "artifact_run_dir=${RUN_DIR}"
  echo "artifact_weekly_dir=${WEEKLY_DIR}"
  echo "artifact_latest_dir=${LATEST_DIR}"
} > "${SUMMARY_TXT}"

echo "[PR78][VERIFY] total_advisories=${TOTAL_ADVISORIES} high_or_critical=${HIGH_OR_CRITICAL} abandoned=${ABANDONED_COUNT}"
echo "[PR78][VERIFY] artifacts=${RUN_DIR}"

if [[ "${HIGH_OR_CRITICAL}" -gt 0 ]]; then
  fail "high/critical advisories detected; release is blocked"
fi

echo "[PR78][VERIFY] pass"
