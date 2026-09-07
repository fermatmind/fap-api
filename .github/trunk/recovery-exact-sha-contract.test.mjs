import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const recovery = readFileSync(new URL("../workflows/recovery.yml", import.meta.url), "utf8");

test("exact-SHA recovery remains usable when the active symlink is unreadable", () => {
  const caseStart = recovery.indexOf('case "$MODE" in');
  const exactStart = recovery.indexOf("exact_sha)", caseStart);
  const exactEnd = recovery.indexOf(";;", exactStart);
  const beforeExact = recovery.slice(caseStart, exactStart);
  const exact = recovery.slice(exactStart, exactEnd);

  assert.notEqual(caseStart, -1);
  assert.notEqual(exactStart, -1);
  assert.doesNotMatch(recovery.slice(0, caseStart), /current\/REVISION/);
  assert.match(beforeExact, /diagnose\)[\s\S]*current\/REVISION/);
  assert.match(beforeExact, /lkg\)[\s\S]*current\/REVISION/);
  assert.doesNotMatch(exact, /current\/REVISION|active=/);
  assert.match(exact, /deploy:code-only production/);
});
