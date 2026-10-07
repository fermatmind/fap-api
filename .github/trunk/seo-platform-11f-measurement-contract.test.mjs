import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");
const exporter = readFileSync(new URL("../../backend/scripts/seo/export_seo_council_contracts.php", import.meta.url), "utf8");
const bigFiveDeliverySmoke = readFileSync(
  new URL("../../backend/scripts/deploy/verify_staging_big_five_report_delivery.sh", import.meta.url),
  "utf8",
);

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

test("staging refreshes real measurement and production validates before conditional refresh", () => {
  const stagingStart = deploy.indexOf("  staging:");
  const productionStart = deploy.indexOf("  production:");
  const staging = deploy.slice(stagingStart, productionStart);
  const production = deploy.slice(productionStart);

  const readiness = staging.indexOf("Verify staging measurement source readiness");
  const enforcement = staging.indexOf("Enforce staging measurement source readiness");
  const candidate = staging.indexOf("Materialize inactive staging measurement candidate");
  const deployment = staging.indexOf("Deploy staging and run repository smoke chain");
  const closeout = staging.indexOf("Finalize staging SEO Council closeout");
  const receipt = staging.indexOf("Read staging SEO Council closeout receipt");
  assert.ok(candidate > 0 && readiness > candidate && enforcement > readiness && deployment > enforcement && closeout > deployment && receipt > closeout);
  assert.equal((staging.match(/deploy:candidate-only staging/g) ?? []).length, 1);
  assert.match(staging, /deploy_task=deploy:prepared/);
  assert.match(staging, /--measurement-only/);
  assert.match(staging, /seo\.measurement_source_readiness\.v2/);
  assert.match(staging, /\.cms_writes == 0 and \.search_writes == 0/);
  assert.match(staging, /candidate[^\n]+!=[^\n]+current/);
  assert.match(staging, /APP_CONFIG_CACHE/);
  assert.match(staging, /staging_closeout=HOLD reason=MEASUREMENT_SOURCE_READINESS_HOLD/);
  assert.match(staging, /steps\.measurement_source_readiness\.outcome != 'success'/);
  assert.match(staging, /gsc_restricted_connect_proxy\.mjs/);
  assert.match(staging, /ConnectionAttempts=3/);
  const command = readFileSync(new URL('../../backend/app/Console/Commands/SeoCompetitiveReleasePrepareCommand.php', import.meta.url), 'utf8');
  assert.match(command, /hasTrustedBaseline/);
  assert.match(command, /refreshable/);
  assert.match(command, /measurement_revalidation/);
  assert.match(command, /'cms_writes' => 0, 'search_writes' => 0/);
  assert.match(command, /\$snapshots->verify\(\$sha, 'tests', \$environment\)/);
  assert.doesNotMatch(command, /\$process->start\(\)/);

  assert.match(production, /GSC_SERVICE_ACCOUNT_JSON: \$\{\{ secrets\.SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON \}\}/);
  assert.match(production, /production_measurement_refresh=HOLD/);
  assert.match(production, /gsc_restricted_connect_proxy\.mjs/);
  assert.match(production, /seo_measurement_sync_env="\$remote_env"/);
  assert.doesNotMatch(production, /GSC_SYNC_DB_(USERNAME|PASSWORD)/);
  assert.doesNotMatch(production, /measurement-source-readiness/);
  assert.doesNotMatch(deployer, /task\('seo:competitive-measurement-refresh'/);
  assert.match(deployer, /seo:competitive-release-prepare/);
  assert.doesNotMatch(deployer, /timeout 15m/);
  assert.match(production, /gsc_refresh_configured=false/);
  assert.match(production, /stop_owned_pid/);
  assert.match(production, /ConnectionAttempts=3/);
  assert.match(production, /production_council_closeout=false/);
  assert.match(production, /if \[ "\$competitive_evidence" = true \] && \[ "\$council_orchestration" = true \]; then/);
  assert.match(production, /if test "\$production_council_closeout" = true; then deploy_timeout=60m; fi/);
  assert.match(production, /-o seo_council_orchestration="\$production_council_closeout"/);
});

test("11F readiness remains downstream of queue-backed Big Five delivery", () => {
  assert.match(deployer, /after\('queue:reload-workers', 'healthcheck:queue-smoke'\);/);
  assert.match(
    deployer,
    /after\('healthcheck:queue-smoke', 'healthcheck:staging-big-five-report-delivery'\);/,
  );
  assert.match(bigFiveDeliverySmoke, /snapshot_status" == ready/);
  assert.match(bigFiveDeliverySmoke, /Illuminate\\Support\\Facades\\DB::table\("report_snapshots"\)/);
  assert.match(bigFiveDeliverySmoke, /public_result=200/);
});
