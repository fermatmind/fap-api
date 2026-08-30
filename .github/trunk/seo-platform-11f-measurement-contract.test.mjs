import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");
const exporter = readFileSync(new URL("../../backend/scripts/seo/export_seo_council_contracts.php", import.meta.url), "utf8");

test("11F extends the permanent exact-SHA control plane with offline-eval and runtime receipts", () => {
  for (const source of [ci, deploy, deployer]) {
    assert.match(source, /SEO-PLATFORM-11F/);
    assert.match(source, /measurement_review/);
    assert.match(source, /ready_for_11G/);
    assert.match(source, /execution_allowed/);
  }
  assert.match(ci, /SeoPlatform11F\*\.php/);
  assert.match(exporter, /seo-measurement-contract-manifest\.v3\.json/);
  assert.match(ci, /seo\.measurement_closeout\.v3/);
  assert.match(deploy, /search_source_state/);
  assert.match(deploy, /cro_source_state/);
  assert.match(deploy, /search_hold_reason/);
  assert.match(deploy, /cro_hold_reason/);
  assert.match(deploy, /OFFLINE_EVAL_READY/);
  assert.match(deploy, /STAGING_READY/);
  assert.match(deploy, /CLOSED/);
});

test("11F receipts remain zero-call zero-write and never add a workflow", () => {
  for (const source of [ci, deploy, deployer]) {
    assert.match(source, /model_calls/);
    assert.match(source, /tool_calls/);
    assert.match(source, /external_calls/);
    assert.match(source, /production_permissions/);
    assert.match(source, /cms_writes/);
    assert.match(source, /search_writes/);
  }
  for (const field of ["all_privacy_bypass", "source_conflict_bypass", "causal_overclaim", "orchestrator_bypass"]) {
    assert.match(ci, new RegExp(field));
    assert.match(deploy, new RegExp(field));
    assert.match(deployer, new RegExp(field));
  }
});

test("11F deployment diagnostics expose only reason enums, booleans, and hashes", () => {
  const start = deployer.indexOf('$measurementDiagnostic = [');
  const end = deployer.indexOf('fwrite(STDERR, "SEO Council safe measurement diagnostic:', start);
  assert.ok(start > 0 && end > start);
  const diagnostic = deployer.slice(start, end);
  assert.match(diagnostic, /GSC_SCHEMA_UNAVAILABLE/);
  assert.match(diagnostic, /CRO_STAGE_COVERAGE_INCOMPLETE/);
  assert.match(diagnostic, /INTERNAL_SAFE_HOLD/);
  assert.doesNotMatch(diagnostic, /getMessage|DB_HOST|DB_PORT|DB_DATABASE|canonical_url|query_hash|source_ref|payload|token|credential/i);
});
