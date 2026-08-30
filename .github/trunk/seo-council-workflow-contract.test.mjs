import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import test from "node:test";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");

test("11D and 11E extend only the permanent CI and deploy control plane", () => {
  const workflows = readdirSync(new URL("../workflows", import.meta.url)).filter((name) => name.endsWith(".yml")).sort();
  assert.deepEqual(workflows, ["ci.yml", "deploy.yml", "nightly.yml", "recovery.yml"]);
  assert.match(ci, /seo-council-orchestration:/);
  assert.match(ci, /seo:council-closeout --expected-sha="\$GITHUB_SHA"/);
  assert.match(ci, /seo\.council_closeout\.v2/);
  assert.match(ci, /SEO-PLATFORM-11E/);
  assert.match(ci, /ready_for_11F/);
  assert.match(ci, /seo\.technical_diagnosis_closeout\.v2/);
  assert.match(ci, /closeout-environment=ci_candidate/);
  assert.equal((ci.match(/seo\.council_closeout\.v2/g) || []).length, 2);
  assert.doesNotMatch(ci, /seo\.council_closeout\.v1/);
  assert.match(ci, /seo_council_orchestration:\$council/);
  assert.match(deploy, /\.seo_council_orchestration\.required == \.classification\.operations\.seo_council_orchestration/);
  assert.match(deploy, /seo-council-orchestration-staging\.json/);
  assert.match(deploy, /seo-council-orchestration-production\.json/);
  assert.match(deploy, /seo\.council_closeout\.v2/);
  assert.match(deploy, /SEO-PLATFORM-11E/);
  assert.match(deploy, /ready_for_11F/);
  assert.match(deploy, /seo\.technical_diagnosis_closeout\.v2/);
  assert.match(deploy, /staging_runtime/);
  assert.match(deploy, /production_runtime/);
  assert.match(deploy, /private_result_authority_publish_required/);
  assert.match(deploy, /\.classification\.categories == \["infrastructure_deployment"\]/);
});

test("11D and 11E deployment stay disabled and write immutable exact-SHA closeout receipts only", () => {
  assert.equal((deployer.match(/task\('seo:council-orchestration-closeout'/g) || []).length, 1);
  assert.match(deployer, /set\('seo_council_orchestration', false\)/);
  assert.match(deployer, /artisan seo:council-closeout --expected-sha="\$expected_sha"/);
  assert.match(deployer, /seo\.council_closeout\.v2/);
  assert.match(deployer, /SEO-PLATFORM-11E/);
  assert.match(deployer, /ready_for_11F/);
  assert.match(deployer, /seo\.technical_diagnosis_closeout\.v2/);
  assert.match(deployer, /closeout-environment=\{\{technical_closeout_environment\}\}/);
  assert.match(deployer, /after\('scheduler:wait-natural-heartbeat', 'seo:council-orchestration-closeout'\)/);
  assert.match(deployer, /set\('private_result_authority_publish_required', true\)/);
  assert.equal((deployer.match(/get\('private_result_authority_publish_required', true\)/g) || []).length, 4);
  assert.doesNotMatch(deployer, /seo\.council_closeout\.v1/);
  assert.match(deployer, /release-receipts\/seo-council-orchestration/);
  assert.match(deployer, /task\('healthcheck:seo-council-anonymous'/);
  assert.doesNotMatch(`${ci}\n${deploy}`, /seo-agent:/);
});
