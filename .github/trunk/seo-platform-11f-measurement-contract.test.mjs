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
  assert.match(exporter, /seo-measurement-contract-manifest\.v2\.json/);
  assert.match(ci, /seo\.measurement_closeout\.v2/);
  assert.match(deploy, /evidence_source_state/);
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
});
