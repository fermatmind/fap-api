# PR66 Recon

- Keywords: migrations|dropIfExists|catch
- Scope:
  - backend/database/migrations/*.php
  - backend/tests/Unit/Migrations/MigrationSafetyTest.php

## Findings
- pseudo-create migration with executable `Schema::dropIfExists(...)` in `down()`: 0
- pseudo-create migration with executable `Schema::drop(...)` in `down()`: 5
- empty catch blocks in migrations: 0
- `2026_01_27_210000_pr9_add_observability_columns_to_events.php` used non-empty try/catch around index DDL; switched to fail-fast direct execution.

## Targets
- Pseudo-create migrations (`create_*.php` + `up()` has `Schema::hasTable(...)` guard and return):
  - `down()` must be no-op with safety comment.
  - no executable `Schema::dropIfExists(...)` or `Schema::drop(...)`.
- Migrations catch policy:
  - no empty catch blocks.
  - observability migration remove suppression-style try/catch and fail-fast.

## Safety Rule
- Down for pseudo-create should keep this comment:
  - `// Safety: Down is a no-op to prevent accidental data loss.`
