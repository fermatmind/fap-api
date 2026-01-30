# Data Retention Runbook (PR21)

Date: 2026-01-30

## 1) When to archive
- Trigger when `attempt_answer_rows` or `events` exceed retention window.
- Suggested window: keep 6–12 months hot in MySQL, archive older data monthly.

## 2) Command
```
php artisan fap:archive:cold-data --before=YYYY-MM-DD
```

## 3) Expected outputs
- A gzip JSONL file per table in `fap_attempts.archive_path`.
- One `archive_audits` row per table per run.

## 4) Verify
- Check audit:
  - `SELECT * FROM archive_audits ORDER BY id DESC LIMIT 5;`
- Validate file checksum against `archive_audits.checksum`.

## 5) Rollback / restore
- If a drop happened on MySQL, restore by loading JSONL archive into a staging table, then reinsert.
- Keep archive files immutable; never rewrite a previously audited object.

## 6) Common failures
- **Partition drop fails (MySQL)**: ensure table is partitioned; rerun after fixing partitions.
- **Archive path missing**: confirm `ARCHIVE_PATH` exists and is writable.
- **Checksum mismatch**: re-export and compare row counts; verify storage integrity.
