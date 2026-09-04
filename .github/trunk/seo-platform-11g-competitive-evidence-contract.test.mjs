import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
import test from "node:test";

const classifier = readFileSync(new URL("./classify-paths.mjs", import.meta.url), "utf8");
const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");
const competitiveCloseout = readFileSync(
  new URL("../../backend/app/Services/SeoCouncil/Competitive/CompetitiveCloseoutBuilder.php", import.meta.url),
  "utf8",
);

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
  assert.match(deployer, /after\('artisan:config:cache', 'seo:competitive-evidence-preactivation'\)/);
  assert.doesNotMatch(deployer, /task\('seo:competitive-measurement-refresh'/);
  assert.match(deployer, /after\('healthcheck:seo-council-anonymous', 'seo:competitive-evidence-finalize'\)/);
  assert.match(deployer, /SEO_COMPETITIVE_EXTERNAL_READ_ENABLED=true/);
  assert.match(deployer, /SEO_COMPETITIVE_EVIDENCE_WRITE_ENABLED=true/);
  assert.match(deployer, /seo:competitive-release-prepare/);
  assert.match(deployer, /--cohort=competitive\.big-five\.live\.v2/);
  assert.match(deployer, /environment=\{\{competitive_environment\}\}/);
  assert.match(deployer, /\.preactivation_receipt\.production_sha == null/);
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
  assert.match(preactivation, /gsc_env='\{\{seo_measurement_sync_env\}\}'/);
  assert.match(preactivation, /\.status == "READY"/);
  assert.doesNotMatch(preactivation, /\{\{bin\/php\}\} -r/);
  assert.match(preactivation, /jq -ce --arg sha "\$candidate_sha" --arg environment "\$environment"/);
  assert.match(preactivation, /\.preactivation_receipt\.candidate_sha == \$sha/);
  assert.match(preactivation, /\.preactivation_receipt\.production_sha == null/);
  assert.match(preactivation, /\.preactivation_receipt\.execution_allowed == false/);
  assert.match(preactivation, /COMPETITIVE_PREACTIVATION_ENVELOPE_INVALID/);
  assert.doesNotMatch(preactivation, /printf '%s' "\$receipt" \| jq -e/);
  assert.doesNotMatch(preactivation, /HTTPS_PROXY|HTTP_PROXY|GSC_SERVICE_ACCOUNT/);
});

test("production preactivation envelope filter returns only a valid zero-write receipt", () => {
  const preactivationStart = deployer.indexOf("task('seo:competitive-evidence-preactivation'");
  const preactivationEnd = deployer.indexOf("task('seo:competitive-evidence-finalize'", preactivationStart);
  const preactivation = deployer.slice(preactivationStart, preactivationEnd);
  const match = preactivation.match(
    /jq -ce --arg sha "\$candidate_sha" --arg environment "\$environment" '\n([\s\S]*?)\n'\)"/,
  );
  assert.ok(match);

  const sha = "a".repeat(40);
  const receipt = {
    receipt_version: "seo.competitive_evidence_closeout.v3",
    candidate_sha: sha,
    environment: "production",
    closeout_state: "HOLD",
    production_sha: null,
    "SEO-PLATFORM-11G": "HOLD",
    ready_for_11H: false,
    "11i_handoff_ready": false,
    competitive_context_status: "READY",
    competitive_hold_reason: "NONE",
    execution_allowed: false,
    model_calls: 0,
    tool_calls: 0,
    external_calls: 0,
    cms_writes: 0,
    url_truth_writes: 0,
    search_writes: 0,
    business_writes: 0,
    production_permissions: 0,
    outreach_actions: 0,
    receipt_hash: "b".repeat(64),
  };
  const payload = {
    schema_version: "seo.competitive_release_prepare.v1",
    status: "READY",
    failed_stage: "none",
    reason_code: "NONE",
    measurement_snapshot_set_hash: "c".repeat(64),
    dependency_ingestion: { external_reads: 4 },
    preactivation_receipt: receipt,
  };
  const run = (input) => spawnSync(
    "jq",
    ["-ce", "--arg", "sha", sha, "--arg", "environment", "production", match[1]],
    { input: JSON.stringify(input), encoding: "utf8" },
  );

  const valid = run(payload);
  assert.equal(valid.status, 0, valid.stderr);
  assert.deepEqual(JSON.parse(valid.stdout), receipt);

  const writeEnabled = structuredClone(payload);
  writeEnabled.preactivation_receipt.cms_writes = 1;
  assert.notEqual(run(writeEnabled).status, 0);
});

test("production competitive receipts use the existing bounded runtime owner fallback", () => {
  const preactivationStart = deployer.indexOf("task('seo:competitive-evidence-preactivation'");
  const finalizeStart = deployer.indexOf("task('seo:competitive-evidence-finalize'", preactivationStart);
  const finalizeEnd = deployer.indexOf("task('seo:agent-policy-gateway-closeout'", finalizeStart);
  const preactivation = deployer.slice(preactivationStart, finalizeStart);
  const finalize = deployer.slice(finalizeStart, finalizeEnd);
  const productionReceiptStart = deploy.indexOf("- name: Read production competitive evidence receipt");
  const productionReceiptEnd = deploy.indexOf("- name: Read production SEO Evidence closeout receipt", productionReceiptStart);
  const productionReceipt = deploy.slice(productionReceiptStart, productionReceiptEnd);

  assert.ok(preactivationStart > 0 && finalizeStart > preactivationStart && finalizeEnd > finalizeStart);
  assert.ok(productionReceiptStart > 0 && productionReceiptEnd > productionReceiptStart);
  for (const receiptTask of [preactivation, finalize]) {
    assert.match(receiptTask, /receipt_owner=deploy/);
    assert.match(receiptTask, /sudo -n -u www-data -- mkdir -p "\$receipt_dir"/);
    assert.match(receiptTask, /as_receipt_owner test ! -L "\$receipt_dir"/);
    assert.match(receiptTask, /as_receipt_owner tee "\$tmp"/);
    assert.match(receiptTask, /as_receipt_owner chmod 0640 "\$tmp"/);
    assert.match(receiptTask, /as_receipt_owner ln "\$tmp"/);
    assert.match(receiptTask, /as_receipt_owner cmp -s "\$tmp"/);
    assert.match(receiptTask, /COMPETITIVE_RECEIPT_PERSISTENCE_UNAVAILABLE/);
    assert.doesNotMatch(receiptTask, /chmod 0?777|chown/);
  }
  assert.match(finalize, /set \+e/);
  assert.match(finalize, /finalize_owner=deploy/);
  assert.match(finalize, /if ! test -r "\$preactivation"/);
  assert.match(finalize, /sudo -n -u www-data -- test -r "\$preactivation"/);
  assert.match(finalize, /sudo -n -u www-data -- env SEO_RELEASE_SHA=/);
  assert.match(finalize, /finalize_status=\$\?/);
  assert.match(finalize, /competitive_finalize_status=/);
  assert.match(finalize, /competitive_finalize_reason=/);
  assert.match(finalize, /exit "\$finalize_status"/);
  assert.doesNotMatch(finalize, /\.dependency_ingestion|\.policy_observations|\.release_ref/);
  assert.match(
    productionReceipt,
    /sudo -n -u www-data -- test -f \\"\\\$file\\"; sudo -n -u www-data -- test ! -L \\"\\\$file\\"; sudo -n -u www-data -- cat \\"\\\$file\\"/,
  );
});

test("production validates local write configuration without requiring live GSC transport", () => {
  const preflightStart = deploy.indexOf("- name: Verify production 11G configuration before transport");
  const preflightEnd = deploy.indexOf("- uses: shivammathur/setup-php", preflightStart);
  const preflight = deploy.slice(preflightStart, preflightEnd);
  const sshStart = deploy.indexOf("- name: Start SSH agent without key metadata output", preflightStart);

  assert.ok(preflightStart > 0 && preflightEnd > preflightStart);
  assert.ok(sshStart > preflightEnd);
  assert.match(preflight, /if: needs\.policy\.outputs\.seo_competitive_evidence == 'true'/);
  assert.match(preflight, /COMPETITIVE_WRITER_DB_USERNAME: \$\{\{ secrets\.SEO_INTEL_MIGRATION_DB_USERNAME \}\}/);
  assert.match(preflight, /COMPETITIVE_WRITER_DB_PASSWORD: \$\{\{ secrets\.SEO_INTEL_MIGRATION_DB_PASSWORD \}\}/);
  assert.doesNotMatch(preflight, /^\s+SEO_INTEL_MIGRATION_DB_(?:USERNAME|PASSWORD):/m);
  assert.match(preflight, /COMPETITIVE_WRITER_DB_CREDENTIAL_MISSING/);
  assert.match(preflight, /gsc_refresh_validation=deferred_until_needed/);
  assert.match(preflight, /production_competitive_configuration=READY/);
  assert.doesNotMatch(preflight, /\b(?:ssh|scp|curl)\b/);
});

test("production preparation reuses valid snapshots and bounds conditional parallel refresh", () => {
  const command = readFileSync(
    new URL("../../backend/app/Console/Commands/SeoCompetitiveReleasePrepareCommand.php", import.meta.url),
    "utf8",
  );
  const verifier = readFileSync(
    new URL("../../backend/app/Services/SeoAgentEvidence/Competitive/MeasurementSnapshotVerifier.php", import.meta.url),
    "utf8",
  );
  const productionStart = deploy.indexOf("- name: Deploy once and automatically restore LKG after committed smoke failure");
  const productionEnd = deploy.indexOf("- name: Read production competitive evidence receipt", productionStart);
  const production = deploy.slice(productionStart, productionEnd);

  assert.match(command, /seo:competitive-release-prepare/);
  assert.match(command, /seo\.competitive_release_prepare\.v1/);
  assert.match(command, /PROCESS_TIMEOUT_SECONDS = 1500/);
  assert.match(command, /SUPERVISOR_TIMEOUT_SECONDS = 1800/);
  assert.match(command, /->start\(\)/);
  assert.match(command, /GSC_REFRESH_TIMEOUT/);
  assert.match(command, /CRO_REFRESH_TIMEOUT/);
  assert.match(command, /MEASUREMENT_REVALIDATION_HOLD/);
  assert.match(command, /incremental_refresh/);
  assert.match(command, /full_refresh/);
  assert.match(command, /probeEvidenceWriter/);
  assert.match(verifier, /measurement_snapshot_set_hash/);
  assert.match(verifier, /property_hash/);
  assert.match(verifier, /'org_id' => .*0/);
  assert.doesNotMatch(deployer, /timeout 15m .*seo-intel:gsc-sync/);
  assert.doesNotMatch(deployer, /timeout 15m .*analytics:refresh-seo-conversion-daily/);
  assert.match(deployer, /BASH, timeout: 2100/);
  assert.match(production, /stop_owned_pid/);
  assert.match(production, /\^\[1-9\]\[0-9\]\*\$/);
  assert.match(production, /ps -p "\$pid" -o command=/);
});

test("Council stays zero-egress while dependency reads are accounted separately", () => {
  for (const source of [ci, deploy, deployer]) {
    assert.match(source, /external_calls/);
    assert.match(source, /production_permissions/);
    assert.match(source, /execution_allowed/);
  }
  assert.match(deployer, /dependency_ingestion/);
  assert.match(deployer, /outreach_actions/);
  assert.match(competitiveCloseout, /deferred_p2_manual/);
});
