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

test("runtime acceptance before cleanup", () => {
  assert.ok(rootRules.includes("successful exact-SHA CI, staging, production, and online acceptance before closeout"));
});

test("docs-only closeout without deployment", () => {
  assert.ok(rootRules.includes("successful exact-SHA CI and deploy-skip; do not trigger staging or production"));
});

test("failed releases retain diagnostic context", () => {
  assert.ok(rootRules.includes("preserve the diagnostic worktree and necessary evidence"));
  assert.ok(rootRules.includes("do not report completion or remove the recovery context"));
});

test("undelivered files are saved without blanket commits or backups", () => {
  assert.ok(rootRules.includes("Inspect tracked changes, untracked files, and ignored local configuration"));
  assert.ok(rootRules.includes("verify the saved bytes"));
  assert.ok(rootRules.includes("Do not require every draft to be committed or every file to be backed up"));
});

test("user work and dependent previews are preserved", () => {
  assert.ok(rootRules.includes("Never delete pre-existing user changes"));
  assert.ok(rootRules.includes("Preserve user-requested previews"));
  assert.ok(rootRules.includes("still needed by another task"));
  assert.ok(rootRules.includes("never kill by port alone"));
});

test("task-owned removal is verified", () => {
  assert.ok(rootRules.includes("every task commit is contained in the latest `origin/main`"));
  assert.ok(rootRules.includes("remove its worktree and local branch from the main checkout"));
  assert.ok(rootRules.includes("Verify the final worktree/branch inventory"));
});

test("completion includes autonomous cleanup reporting", () => {
  assert.ok(rootRules.includes("applicable exact-SHA acceptance and the Delivery closeout requirements"));
  assert.ok(rootRules.includes("Ordinary closeout needs no additional user confirmation"));
  assert.ok(rootRules.includes("final report must include cleanup results and concrete reasons"));
});

test("deployment and scope skills use the shared closeout contract", () => {
  for (const name of ["fap-api-deploy-sre", "fermatmind-scope-guard"]) {
    const skill = readFileSync(new URL(`../../.agents/skills/${name}/SKILL.md`, import.meta.url), "utf8");
    assert.ok(skill.includes("../../../AGENTS.md#delivery-closeout"));
    assert.ok(skill.includes("acceptance -> closeout -> final report"));
    assert.ok(!skill.includes("when cleanup is requested"));
  }
});
