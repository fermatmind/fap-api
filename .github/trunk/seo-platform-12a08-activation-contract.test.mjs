import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";
import { fingerprint, inRuntimeScope, mayCarry, validateNightly } from "./seo-platform-12a08-activation.mjs";

const sha = "a".repeat(40);
const digest = `sha256:${"b".repeat(64)}`;
const domains = Object.fromEntries([
  "authority_contract", "full_phpunit", "dependency_audit", "workflow_contracts", "security_scan",
].map((name) => [name, { required: true, result: "success" }]));
const receipt = { schema_version: "nightly-failure-domain-summary.v2", workflow_sha: sha,
  check_scope: "weekly_full_checks", status: "pass", domains };
const metadata = { repository: "fermatmind/fap-api", workflow_name: "Nightly",
  workflow_path: ".github/workflows/nightly.yml", head_branch: "main", event: "schedule",
  run_id: 123, run_attempt: 1, sha, artifact_digest: digest };

test("weekly exact-SHA evidence is accepted while daily, forged SHA, digest, or domain is rejected", () => {
  assert.equal(validateNightly(receipt, metadata), true);
  for (const [changedReceipt, changedMetadata] of [
    [{ ...receipt, check_scope: "daily_checks" }, metadata],
    [{ ...receipt, workflow_sha: "c".repeat(40) }, metadata],
    [receipt, { ...metadata, artifact_digest: "b".repeat(64) }],
    [{ ...receipt, domains: { ...domains, full_phpunit: { required: true, result: "failure" } } }, metadata],
  ]) assert.throws(() => validateNightly(changedReceipt, changedMetadata), /FULL_NIGHTLY_EVIDENCE_HOLD/);
});

test("compatibility scope is conservative and carry-forward requires identical fingerprint and vector", () => {
  assert.equal(inRuntimeScope("backend/app/Foo.php"), true);
  assert.equal(inRuntimeScope("backend/routes/api.php"), true);
  assert.equal(inRuntimeScope("backend/database/migrations/x.php"), true);
  assert.equal(inRuntimeScope("backend/composer.lock"), true);
  assert.equal(inRuntimeScope(".github/workflows/deploy.yml"), true);
  assert.equal(inRuntimeScope("README.md"), false);
  const fp = { scope_version: "seo-council-a08-runtime.v1", sha256: "d".repeat(64), file_count: 12 };
  const vector = { role: "e".repeat(64) };
  const manifest = { schema_version: "seo.platform12_a08_activation.v1", repository: "fermatmind/fap-api",
    bound_production_sha: sha, compatibility: { fingerprint: fp }, runtime: { version_vector: vector } };
  assert.equal(mayCarry(manifest, { fingerprint: fp, version_vector: vector, production_sha: "f".repeat(40) }), true);
  assert.equal(mayCarry(manifest, { fingerprint: { ...fp, sha256: "0".repeat(64) }, version_vector: vector, production_sha: "f".repeat(40) }), false);
  assert.equal(mayCarry(manifest, { fingerprint: fp, version_vector: { role: "0".repeat(64) }, production_sha: "f".repeat(40) }), false);
});

test("deploy uses one workflow for CI release and Nightly activation with no manual entry", () => {
  const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
  assert.match(deploy, /workflows: \[CI, Nightly\]/);
  assert.doesNotMatch(deploy, /workflow_dispatch:/);
  assert.match(deploy, /council-a08-activation:/);
  assert.match(deploy, /pause_intent/);
  assert.match(deploy, /verify-nightly/);
  assert.match(deploy, /A08_SOURCE_ACCEPTANCE_HOLD/);
  assert.match(deploy, /status,mission_id,terminal_committed,mission_verdict,source_gaps/);
  assert.match(deploy, /- name: Validate three A08 sources through controlled read-only Missions\n\s+if: needs\.policy\.outputs\.seo_council_orchestration == 'true'/);
});

test("current checkout produces a non-empty deterministic fingerprint", () => {
  const first = fingerprint(new URL("../..", import.meta.url).pathname);
  const second = fingerprint(new URL("../..", import.meta.url).pathname);
  assert.deepEqual(first, second);
  assert.ok(first.file_count > 100);
  assert.match(first.sha256, /^[a-f0-9]{64}$/);
});
