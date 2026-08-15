#!/usr/bin/env node

import { readFileSync } from "node:fs";

const destructivePatterns = [
  /Schema::drop(?:IfExists)?\s*\(/,
  /->drop(?:Column|Index|Unique|Primary|Foreign|ConstrainedForeignId|Morphs|Timestamps|SoftDeletes)\s*\(/,
  /->renameColumn\s*\(/,
  /->change\s*\(/,
  /ALTER\s+TABLE[\s\S]+(?:DROP|RENAME|MODIFY|CHANGE)\b/i,
  /TRUNCATE\s+TABLE\b/i,
  /DELETE\s+FROM\b/i,
];

export function validateMigration(source, path = "migration.php") {
  const violations = destructivePatterns
    .filter((pattern) => pattern.test(source))
    .map((pattern) => pattern.source);
  const contractCleanup = /@trunk-contract-cleanup\b/.test(source);

  if (contractCleanup) {
    const after = source.match(/@trunk-contract-after\s+(\d{4}-\d{2}-\d{2})/u)?.[1];
    const versions = Number(source.match(/@trunk-contract-min-production-versions\s+(\d+)/u)?.[1] ?? 0);
    if (!after || versions < 2) {
      violations.push("contract cleanup requires @trunk-contract-after and at least 2 production versions");
    }
  }

  return { path, safe: violations.length === 0, contract_cleanup: contractCleanup, violations };
}

function cli() {
  const paths = process.argv.slice(2);
  if (paths.length === 0) throw new Error("at least one migration path is required");
  const results = paths.map((path) => validateMigration(readFileSync(path, "utf8"), path));
  process.stdout.write(`${JSON.stringify({ results }, null, 2)}\n`);
  if (results.some((result) => !result.safe)) process.exitCode = 1;
}

if (import.meta.url === `file://${process.argv[1]}`) cli();
