#!/usr/bin/env python3
import argparse
import csv
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser(description="Build BIG5 en_johnson bootstrap norms CSV (offline deterministic build).")
    parser.add_argument(
        "--input",
        default="backend/content_packs/BIG5_OCEAN/v1/raw/norm_stats.csv",
        help="Input raw norm_stats.csv path",
    )
    parser.add_argument(
        "--output",
        default="backend/resources/norms/big5/big5_norm_stats_seed.csv",
        help="Output merged seed CSV path",
    )
    args = parser.parse_args()

    root = Path(__file__).resolve().parents[2]
    in_path = (root.parent / args.input).resolve() if args.input.startswith("backend/") else (root / args.input).resolve()
    out_path = (root.parent / args.output).resolve() if args.output.startswith("backend/") else (root / args.output).resolve()

    rows = []
    with in_path.open("r", encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f)
        for row in reader:
            if row.get("group_id") != "global_all":
                continue
            level = (row.get("metric_level") or "").strip().lower()
            code = (row.get("metric_code") or "").strip().upper()
            if level not in {"domain", "facet"}:
                continue
            rows.append({
                "scale_code": "BIG5_OCEAN",
                "norms_version": "2026Q1_bootstrap_v1",
                "locale": "en",
                "region": "GLOBAL",
                "group_id": "en_johnson_all_18-60",
                "gender": "ALL",
                "age_min": "18",
                "age_max": "60",
                "metric_level": level,
                "metric_code": code,
                "mean": f"{float(row.get('mean') or 0):.3f}",
                "sd": f"{float(row.get('sd') or 0):.3f}",
                "sample_n": str(int(float(row.get("sample_n") or 0))),
                "source_id": "GLOBAL_IPIPNEO_JOHNSON_ARCHIVE",
                "source_type": "open_dataset",
                "status": "CALIBRATED",
                "is_active": "1",
                "published_at": "2026-02-21T00:00:00Z",
            })

    fieldnames = [
        "scale_code", "norms_version", "locale", "region", "group_id", "gender", "age_min", "age_max",
        "metric_level", "metric_code", "mean", "sd", "sample_n", "source_id", "source_type", "status",
        "is_active", "published_at",
    ]

    if len(rows) != 35:
        raise SystemExit(f"expected 35 rows from global_all, got {len(rows)}")

    out_path.parent.mkdir(parents=True, exist_ok=True)
    with out_path.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

    print(f"[big5_build_johnson_bootstrap] wrote {len(rows)} rows -> {out_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
