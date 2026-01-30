# Archival (PR21)

Date: 2026-01-30

## 1) Targets
Cold data is archived from:
- `attempt_answer_rows`
- `events`

Archive metadata is recorded in `archive_audits`.

## 2) Command
```
php artisan fap:archive:cold-data --before=YYYY-MM-DD
```

Behavior:
- Exports rows older than `--before` into JSONL, then gzip.
- Writes `archive_audits` record with:
  - `table_name`, `range_end`, `object_uri`, `row_count`, `checksum`.

## 3) Storage drivers
Configured by `fap_attempts.archive_driver`:
- `file` (default):
  - `object_uri`: `file:///.../archives/<table>/<table>_before_YYYYMMDD_*.jsonl.gz`
- `s3` / `oss`:
  - `object_uri`: `s3://bucket/archives/...` or `oss://bucket/archives/...`

## 4) MySQL vs sqlite
- **MySQL**: attempts to drop partitions with upper bound <= `--before`.
- **sqlite**: no partition support; command still generates archive file + audit entry.

## 5) Audit Table
`archive_audits` columns:
- `table_name`
- `range_start` (optional)
- `range_end`
- `object_uri`
- `row_count`
- `checksum`
- `created_at`
