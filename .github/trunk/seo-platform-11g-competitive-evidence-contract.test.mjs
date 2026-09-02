import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const classifier = readFileSync(new URL("./classify-paths.mjs", import.meta.url), "utf8");
const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");

test("11G uses the permanent exact-SHA control plane", () => {
  for (const source of [classifier, ci, deploy, deployer]) {
    assert.match(source, /seo_competitive_evidence/);
  }
  assert.match(ci, /SeoPlatform11B\*\.php tests\/Feature\/SeoIntel\/SeoPlatform11G\*\.php/);
  assert.match(ci, /SEO-PLATFORM-11G/);
  assert.match(ci, /dependency_ingestion\.external_reads == 0/);
  assert.match(deploy, /seo-competitive-evidence-staging/);
  assert.match(deploy, /seo-competitive-evidence-production/);
});

test("competitive ingestion is measurement-gated and environment independent", () => {
  const preactivation = deployer.indexOf("task('seo:competitive-evidence-preactivation'");
  const activation = deployer.indexOf("before('deploy:symlink'");
  assert.ok(preactivation > 0 && activation > 0);
  assert.match(deployer, /after\('artisan:config:cache', 'seo:competitive-measurement-refresh'\)/);
  assert.match(deployer, /task\('seo:competitive-measurement-refresh'/);
  assert.match(deployer, /after\('healthcheck:seo-council-anonymous', 'seo:competitive-evidence-finalize'\)/);
  assert.match(deployer, /SEO_COMPETITIVE_EXTERNAL_READ_ENABLED=true/);
  assert.match(deployer, /SEO_COMPETITIVE_EVIDENCE_WRITE_ENABLED=true/);
  assert.match(deployer, /--cohort=competitive\.big-five\.live\.v2 --write-evidence/);
  assert.match(deployer, /environment=\{\{competitive_environment\}\}/);
  assert.match(deployer, /\.production_sha == null/);
  assert.match(deployer, /--finalize-activation/);
  assert.match(deploy, /closeout_state == "STAGING_VALIDATED"/);
  assert.match(deploy, /search_measurement\.hold_reason == "NONE"/);
  assert.match(deploy, /cro_measurement\.hold_reason == "NONE"/);
});

test("Council stays zero-egress while dependency reads are accounted separately", () => {
  for (const source of [ci, deploy, deployer]) {
    assert.match(source, /external_calls/);
    assert.match(source, /production_permissions/);
    assert.match(source, /execution_allowed/);
  }
  assert.match(deployer, /dependency_ingestion/);
  assert.match(deployer, /outreach_actions/);
  assert.match(deployer, /deferred_p2_manual/);
});
