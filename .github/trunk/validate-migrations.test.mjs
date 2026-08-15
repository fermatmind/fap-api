import assert from "node:assert/strict";
import test from "node:test";

import { validateMigration } from "./validate-migrations.mjs";

test("accepts expand migrations", () => {
  const result = validateMigration("Schema::table('users', fn ($table) => $table->string('nickname')->nullable());");
  assert.equal(result.safe, true);
});

for (const source of [
  "Schema::dropIfExists('users');",
  "$table->dropColumn('legacy');",
  "$table->renameColumn('old', 'new');",
  "$table->string('name')->change();",
  "DB::statement('ALTER TABLE users DROP COLUMN legacy');",
]) {
  test(`rejects destructive migration: ${source}`, () => assert.equal(validateMigration(source).safe, false));
}

test("requires time and version evidence for contract cleanup", () => {
  const unsafe = validateMigration("// @trunk-contract-cleanup\n$table->dropColumn('legacy');");
  assert.equal(unsafe.safe, false);
  assert.match(unsafe.violations.at(-1), /at least 2 production versions/);
});
