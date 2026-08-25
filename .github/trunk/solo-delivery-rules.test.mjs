import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const rootRules = readFileSync(new URL("../../AGENTS.md", import.meta.url), "utf8");
const backendRules = readFileSync(new URL("../../backend/AGENTS.md", import.meta.url), "utf8");
const ciWorkflow = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const backendDeliveryRules = backendRules.split("## Truth boundary", 1)[0];

test("ordinary delivery uses isolated codex branches and pushes the validated commit directly to main", () => {
  assert.match(rootRules, /named `codex\/` branch in a clean isolated worktree created from the latest `origin\/main`/);
  assert.match(rootRules, /git push origin HEAD:main/);
  assert.match(backendDeliveryRules, /push the validated commit directly to `main` without a pull request/);
});

test("pull requests are an explicit-only yeet exception", () => {
  assert.match(rootRules, /Use `\$yeet` only when the user explicitly requests a pull request/);
  assert.match(backendDeliveryRules, /only when the user explicitly requests a PR or identifies a PR-train scope/);
  assert.doesNotMatch(backendDeliveryRules, /Ordinary Fast and Product lane work[^\n]*one-PR/);
});

test("validated-tree checks require path-limited staging and reusable tree-bound results", () => {
  assert.match(rootRules, /git add -- <paths>/);
  assert.match(rootRules, /Record `git write-tree` as the candidate tree SHA/);
  assert.match(rootRules, /Reuse those results when the candidate tree is unchanged/);
  assert.match(rootRules, /git rev-parse HEAD\^\{tree\}/);
  assert.doesNotMatch(rootRules, /git add -A/);
});

test("local validation state is not persisted while immutable delivery and controlled evidence remains", () => {
  assert.match(rootRules, /do not persist a local validation receipt, ledger, manifest, or other process artifact/);
  assert.match(rootRules, /does not remove immutable exact-SHA CI receipts, deployment receipts, or Controlled lane business and audit evidence/);
  assert.match(backendDeliveryRules, /Never add a persistent validation receipt or ledger for ordinary Fast\/Product work/);
  assert.match(backendDeliveryRules, /does not affect immutable exact-SHA CI\/deploy receipts or Controlled lane business audit evidence/);
});

test("backend PR-train ledgers remain limited to explicit train scope", () => {
  assert.match(backendDeliveryRules, /ledger updates apply only to an explicitly identified PR-train task/);
  assert.match(backendDeliveryRules, /ordinary work must keep validation results in the active task context only/);
});

test("CI still listens only to main pushes", () => {
  assert.match(ciWorkflow, /on:\n  push:\n    branches: \[main\]/);
  assert.doesNotMatch(ciWorkflow, /pull_request\s*:/);
  assert.doesNotMatch(ciWorkflow, /workflow_dispatch\s*:/);
});
