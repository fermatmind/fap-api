# attempt_quality Retirement Evidence

## Scope

This repository records only repository-level evidence for retiring the legacy
`attempt_quality` table through:

- `database/migrations/2026_03_26_120000_drop_attempt_quality_table.php`
- `docs/migrations/destructive-retirements.json`

It does not claim that production data has already been archived or that a
production migration has been executed.

## Operator Checklist

Before running this migration outside local/test environments, the operator must:

1. Confirm no runtime reads or writes still depend on `attempt_quality`.
2. Capture a production backup or immutable archive for `attempt_quality`.
3. Record the archive object URI, row count, checksum, operator, and timestamp in
   the production change ticket.
4. Verify restore into a staging table before production execution.
5. Execute during an approved maintenance window with rollback ownership assigned.

## Rollback Strategy

This is a forward-only retirement migration. Rollback must not recreate or drop
additional production tables from `down()`. Recovery requires restoring the
verified backup or archive into a staging table, validating row counts and
checksums, and then applying a separately reviewed follow-up migration if runtime
schema restoration is required.
