#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CSV_PATH="${1:-$ROOT_DIR/resources/norms/big5/big5_norm_stats_seed.csv}"

python3 - "$CSV_PATH" <<'PY'
import csv
import hashlib
import sys
from collections import defaultdict
from pathlib import Path

csv_path = Path(sys.argv[1])
if not csv_path.exists():
    raise SystemExit(f"[FAIL] file not found: {csv_path}")

required = [
    'scale_code','norms_version','locale','region','group_id','gender','age_min','age_max',
    'metric_level','metric_code','mean','sd','sample_n','source_id','source_type','status','is_active','published_at'
]

domains = {'O','C','E','A','N'}
facets = {
    'N1','N2','N3','N4','N5','N6',
    'E1','E2','E3','E4','E5','E6',
    'O1','O2','O3','O4','O5','O6',
    'A1','A2','A3','A4','A5','A6',
    'C1','C2','C3','C4','C5','C6',
}

with csv_path.open('r', encoding='utf-8', newline='') as f:
    reader = csv.DictReader(f)
    header = reader.fieldnames or []
    missing = [h for h in required if h not in header]
    if missing:
        raise SystemExit(f"[FAIL] missing headers: {missing}")

    groups = defaultdict(lambda: {'domain': set(), 'facet': set(), 'rows': []})
    canonical = []

    for idx, row in enumerate(reader, start=2):
        gid = (row.get('group_id') or '').strip()
        lvl = (row.get('metric_level') or '').strip().lower()
        code = (row.get('metric_code') or '').strip().upper()
        sd = float(row.get('sd') or 0)
        n = int(float(row.get('sample_n') or 0))

        if gid == '':
            raise SystemExit(f"[FAIL] line {idx}: group_id required")
        if lvl not in {'domain','facet'}:
            raise SystemExit(f"[FAIL] line {idx}: invalid metric_level={lvl}")
        if sd <= 0:
            raise SystemExit(f"[FAIL] line {idx}: sd must be > 0")
        if n <= 0:
            raise SystemExit(f"[FAIL] line {idx}: sample_n must be > 0")

        if lvl == 'domain':
            if code not in domains:
                raise SystemExit(f"[FAIL] line {idx}: invalid domain code={code}")
            groups[gid]['domain'].add(code)
        else:
            if code not in facets:
                raise SystemExit(f"[FAIL] line {idx}: invalid facet code={code}")
            groups[gid]['facet'].add(code)

        groups[gid]['rows'].append((lvl, code))
        canonical.append('|'.join(row.get(k,'') for k in required))

if len(groups) < 2:
    raise SystemExit(f"[FAIL] groups < 2, got {len(groups)}")

for gid, info in sorted(groups.items()):
    if len(info['domain']) != 5:
        raise SystemExit(f"[FAIL] {gid}: domain coverage {len(info['domain'])}/5")
    if len(info['facet']) != 30:
        raise SystemExit(f"[FAIL] {gid}: facet coverage {len(info['facet'])}/30")
    if len(info['rows']) != 35:
        raise SystemExit(f"[FAIL] {gid}: total rows {len(info['rows'])}/35")

if 'en_johnson_all_18-60' not in groups:
    raise SystemExit('[FAIL] missing group en_johnson_all_18-60')
if 'zh-CN_xu_all_18-60' not in groups:
    raise SystemExit('[FAIL] missing group zh-CN_xu_all_18-60')

checksum = hashlib.sha256('\n'.join(sorted(canonical)).encode('utf-8')).hexdigest()
print(f"[PASS] groups={len(groups)} checksum={checksum}")
print(f"[PASS] file={csv_path}")
PY
