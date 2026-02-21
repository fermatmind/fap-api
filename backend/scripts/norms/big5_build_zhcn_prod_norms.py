#!/usr/bin/env python3
import argparse
import csv
import math
from collections import defaultdict
from pathlib import Path

DOMAINS = ['O', 'C', 'E', 'A', 'N']
FACETS = [
    'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
    'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
    'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
    'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
    'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
]
METRIC_CODES = DOMAINS + FACETS


def population_sd(values):
    if not values:
        return 0.0
    mean = sum(values) / len(values)
    acc = 0.0
    for v in values:
        d = v - mean
        acc += d * d
    return math.sqrt(acc / len(values))


def resolve_path(base_dir: Path, raw: str) -> Path:
    if raw.startswith('backend/'):
        return (base_dir.parent / raw).resolve()
    return (base_dir / raw).resolve()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description='Build BIG5 zh-CN production norms rows (35 metrics) and merge into seed CSV.'
    )
    parser.add_argument(
        '--input',
        default='backend/resources/norms/big5/input/zh_cn_prod_ab_attempts.csv',
        help='Input attempt-level CSV containing quality_level and O/C/E/A/N + 30 facet columns',
    )
    parser.add_argument(
        '--seed',
        default='backend/resources/norms/big5/big5_norm_stats_seed.csv',
        help='Seed CSV path to upsert group rows into',
    )
    parser.add_argument('--group-id', default='zh-CN_prod_all_18-60')
    parser.add_argument('--norms-version', default='2026Q1_prod_v1')
    parser.add_argument('--source-id', default='FERMATMIND_PROD_ZHCN_2026Q1')
    parser.add_argument('--source-type', default='internal_prod')
    parser.add_argument('--status', default='CALIBRATED')
    parser.add_argument('--published-at', default='2026-02-21T00:00:00Z')
    parser.add_argument('--fallback-group', default='zh-CN_xu_all_18-60')
    return parser.parse_args()


def load_seed(seed_path: Path):
    if not seed_path.exists():
        raise SystemExit(f'[FAIL] seed not found: {seed_path}')

    with seed_path.open('r', encoding='utf-8', newline='') as f:
        reader = csv.DictReader(f)
        fieldnames = reader.fieldnames or []
        rows = [dict(r) for r in reader]

    required_headers = [
        'scale_code', 'norms_version', 'locale', 'region', 'group_id', 'gender',
        'age_min', 'age_max', 'metric_level', 'metric_code', 'mean', 'sd',
        'sample_n', 'source_id', 'source_type', 'status', 'is_active', 'published_at',
    ]
    missing = [h for h in required_headers if h not in fieldnames]
    if missing:
        raise SystemExit(f'[FAIL] seed missing headers: {missing}')

    return fieldnames, rows


def build_from_attempts(input_path: Path):
    if not input_path.exists():
        return []

    with input_path.open('r', encoding='utf-8', newline='') as f:
        reader = csv.DictReader(f)
        headers = reader.fieldnames or []
        missing = [k for k in (['quality_level'] + METRIC_CODES) if k not in headers]
        if missing:
            raise SystemExit(f'[FAIL] input missing required headers: {missing}')

        metrics = defaultdict(list)
        valid_rows = 0
        for row in reader:
            level = (row.get('quality_level') or '').strip().upper()
            if level not in {'A', 'B'}:
                continue

            try:
                vals = {code: float(row.get(code) or 0.0) for code in METRIC_CODES}
            except ValueError as e:
                raise SystemExit(f'[FAIL] invalid numeric value in input: {e}') from e

            valid_rows += 1
            for code, value in vals.items():
                metrics[code].append(value)

    if valid_rows == 0:
        return []

    out = []
    for code in METRIC_CODES:
        values = metrics.get(code, [])
        if not values:
            raise SystemExit(f'[FAIL] no values for metric={code}')

        level = 'domain' if code in DOMAINS else 'facet'
        mean = sum(values) / len(values)
        sd = population_sd(values)
        out.append({
            'metric_level': level,
            'metric_code': code,
            'mean': f'{mean:.3f}',
            'sd': f'{max(sd, 0.0001):.3f}',
            'sample_n': str(len(values)),
        })

    return out


def build_from_fallback(rows, fallback_group: str):
    out = []
    for row in rows:
        if (row.get('group_id') or '') != fallback_group:
            continue
        level = (row.get('metric_level') or '').strip().lower()
        code = (row.get('metric_code') or '').strip().upper()
        if level not in {'domain', 'facet'}:
            continue
        if code not in METRIC_CODES:
            continue

        out.append({
            'metric_level': level,
            'metric_code': code,
            'mean': f"{float(row.get('mean') or 0):.3f}",
            'sd': f"{max(float(row.get('sd') or 0), 0.0001):.3f}",
            'sample_n': str(max(int(float(row.get('sample_n') or 0)), 1)),
        })

    if len(out) != 35:
        raise SystemExit(
            f'[FAIL] fallback group {fallback_group} does not have full 35 metrics; got {len(out)}'
        )

    return out


def main() -> int:
    args = parse_args()
    base_dir = Path(__file__).resolve().parents[2]

    input_path = resolve_path(base_dir, args.input)
    seed_path = resolve_path(base_dir, args.seed)

    fieldnames, rows = load_seed(seed_path)
    prod_metrics = build_from_attempts(input_path)
    source_mode = 'attempts'

    if not prod_metrics:
        prod_metrics = build_from_fallback(rows, args.fallback_group)
        source_mode = f'fallback:{args.fallback_group}'

    # Replace target group rows.
    merged = [r for r in rows if (r.get('group_id') or '') != args.group_id]

    for metric in prod_metrics:
        merged.append({
            'scale_code': 'BIG5_OCEAN',
            'norms_version': args.norms_version,
            'locale': 'zh-CN',
            'region': 'CN_MAINLAND',
            'group_id': args.group_id,
            'gender': 'ALL',
            'age_min': '18',
            'age_max': '60',
            'metric_level': metric['metric_level'],
            'metric_code': metric['metric_code'],
            'mean': metric['mean'],
            'sd': metric['sd'],
            'sample_n': metric['sample_n'],
            'source_id': args.source_id,
            'source_type': args.source_type,
            'status': args.status,
            'is_active': '1',
            'published_at': args.published_at,
        })

    merged.sort(key=lambda r: (
        r.get('group_id', ''),
        0 if (r.get('metric_level') == 'domain') else 1,
        r.get('metric_code', ''),
    ))

    with seed_path.open('w', encoding='utf-8', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(merged)

    print(
        f"[big5_build_zhcn_prod_norms] source={source_mode} group={args.group_id} "
        f"metrics={len(prod_metrics)} -> {seed_path}"
    )

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
