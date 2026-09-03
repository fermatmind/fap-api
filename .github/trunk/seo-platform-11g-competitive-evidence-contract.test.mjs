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

test("competitive persistence uses an ephemeral writer without changing runtime authority", () => {
  const stagingStart = deploy.indexOf("- name: Finalize staging competitive evidence after 11F readiness");
  const stagingEnd = deploy.indexOf("- uses: actions/upload-artifact", stagingStart);
  const staging = deploy.slice(stagingStart, stagingEnd);
  const productionStart = deploy.indexOf("- name: Deploy once and automatically restore LKG after committed smoke failure");
  const productionEnd = deploy.indexOf("- name: Read production competitive evidence receipt", productionStart);
  const production = deploy.slice(productionStart, productionEnd);
  const preactivationStart = deployer.indexOf("task('seo:competitive-evidence-preactivation'");
  const preactivationEnd = deployer.indexOf("task('seo:competitive-evidence-finalize'", preactivationStart);
  const preactivation = deployer.slice(preactivationStart, preactivationEnd);

  assert.ok(stagingStart > 0 && stagingEnd > stagingStart);
  assert.ok(productionStart > 0 && productionEnd > productionStart);
  assert.ok(preactivationStart > 0 && preactivationEnd > preactivationStart);
  assert.match(staging, /SEO_INTEL_EVIDENCE_DB_USERNAME: \$\{\{ secrets\.SEO_INTEL_MIGRATION_DB_USERNAME \}\}/);
  assert.match(staging, /SEO_INTEL_EVIDENCE_DB_PASSWORD: \$\{\{ secrets\.SEO_INTEL_MIGRATION_DB_PASSWORD \}\}/);
  assert.match(staging, /competitive-writer\.env/);
  assert.match(staging, /competitive-config\.php/);
  assert.match(staging, /chmod 0600/);
  assert.match(staging, /test ! -L/);
  assert.match(staging, /test ! -e/);
  assert.match(staging, /trap cleanup EXIT HUP INT TERM/);
  assert.doesNotMatch(staging, /HTTPS_PROXY|HTTP_PROXY|GSC_SERVICE_ACCOUNT/);

  assert.match(deployer, /set\('seo_competitive_writer_env', ''\)/);
  assert.match(production, /local_writer_env="\$RUNNER_TEMP\/competitive-writer\.env"/);
  assert.match(production, /remote_writer_config="\$remote_tmp\/competitive-config\.php"/);
  assert.match(production, /-o seo_competitive_writer_env="\$remote_writer_env"/);
  assert.match(production, /rm -f "\$local_key" "\$local_env" "\$local_writer_env"/);
  assert.match(preactivation, /writer_env='\{\{seo_competitive_writer_env\}\}'/);
  assert.match(preactivation, /competitive-writer\\\.env/);
  assert.match(preactivation, /competitive-config\\\.php/);
  assert.match(preactivation, /test ! -L "\$writer_env"/);
  assert.match(preactivation, /test ! -e "\$APP_CONFIG_CACHE"/);
  assert.match(preactivation, /test "\$\{SEO_INTEL_WRITE_ENABLED:-\}" = true/);
  assert.doesNotMatch(preactivation, /seo_measurement_sync_env|HTTPS_PROXY|HTTP_PROXY|GSC_/);
});

test("production validates the complete 11G configuration before transport", () => {
  const preflightStart = deploy.indexOf("- name: Verify production 11G configuration before transport");
  const preflightEnd = deploy.indexOf("- uses: shivammathur/setup-php", preflightStart);
  const preflight = deploy.slice(preflightStart, preflightEnd);
  const sshStart = deploy.indexOf("- name: Start SSH agent without key metadata output", preflightStart);

  assert.ok(preflightStart > 0 && preflightEnd > preflightStart);
  assert.ok(sshStart > preflightEnd);
  assert.match(preflight, /if: needs\.policy\.outputs\.seo_competitive_evidence == 'true'/);
  assert.match(preflight, /SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON/);
  assert.match(preflight, /SEO_INTEL_GSC_PROPERTY_URL/);
  assert.match(preflight, /COMPETITIVE_WRITER_DB_USERNAME: \$\{\{ secrets\.SEO_INTEL_MIGRATION_DB_USERNAME \}\}/);
  assert.match(preflight, /COMPETITIVE_WRITER_DB_PASSWORD: \$\{\{ secrets\.SEO_INTEL_MIGRATION_DB_PASSWORD \}\}/);
  assert.doesNotMatch(preflight, /^\s+SEO_INTEL_MIGRATION_DB_(?:USERNAME|PASSWORD):/m);
  assert.match(preflight, /COMPETITIVE_WRITER_DB_CREDENTIAL_MISSING/);
  assert.match(preflight, /production_competitive_configuration=READY/);
  assert.doesNotMatch(preflight, /\b(?:ssh|scp|curl)\b/);
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
