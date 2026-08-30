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

test("staging source readiness precedes Council closeout and production never backfills", () => {
  const stagingStart = deploy.indexOf("  staging:");
  const productionStart = deploy.indexOf("  production:");
  const staging = deploy.slice(stagingStart, productionStart);
  const production = deploy.slice(productionStart);

  const readiness = staging.indexOf("Verify staging measurement source readiness");
  const deployment = staging.indexOf("Deploy staging and run repository smoke chain");
  assert.ok(readiness > 0 && deployment > readiness);
  assert.match(staging, /SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON/);
  assert.match(staging, /test -n "\$GSC_SERVICE_ACCOUNT_JSON"/);
  assert.match(staging, /GSC_SECRET_MISSING/);
  assert.match(staging, /GSC_SCOPE_NOT_READONLY/);
  assert.match(staging, /GSC_SYNC_QUALITY_HOLD/);
  assert.match(staging, /GSC_RESTRICTED_EGRESS_TRANSPORT_FAILED/);
  assert.match(staging, /GSC_AUTHENTICATION_FAILED/);
  assert.match(staging, /GSC_EMPTY_RESPONSE/);
  assert.match(staging, /GSC_SYNC_INTERNAL_FAILURE/);
  assert.match(staging, /GSC_SYNC_OUTPUT_INVALID/);
  assert.match(staging, /GSC_SYNC_OUTPUT_EMPTY/);
  assert.match(staging, /awk 'started \|\| \/\^\[\[:space:\]\]\*\\\{\//);
  assert.match(staging, /GSC_SEARCH_ANALYTICS_REQUEST_FAILED/);
  assert.match(staging, /GSC_PAGINATION_LIMIT_EXCEEDED/);
  assert.match(staging, /sync_issue="\$\(jq -r '\.issue \/\/ ""'/);
  assert.doesNotMatch(staging, /printf[^\n]+\$sync_issue/);
  assert.match(staging, /CRO_NO_REAL_AGGREGATE_SOURCE/);
  assert.match(staging, /CRO_READMODEL_UNHEALTHY/);
  assert.match(staging, /test "\$GSC_AUTH_MODE" = service_account/);
  assert.match(staging, /https:\/\/www\.googleapis\.com\/auth\/webmasters\.readonly/);
  assert.match(staging, /\.token_uri == "https:\/\/oauth2\.googleapis\.com\/token"/);
  assert.match(staging, /gsc_restricted_connect_proxy\.mjs/);
  assert.match(staging, /--gsc-live-preflight --dry-run --no-write/);
  assert.match(staging, /seo-intel:gsc-sync --window=90 --search-types=web --full-window/);
  assert.match(staging, /analytics:refresh-seo-conversion-daily[^\n]+--dry-run/);
  assert.match(staging, /\.attempted_rows > 0/);
  assert.match(staging, /\.unmapped_rows == 0/);
  assert.match(staging, /\.duplicate_natural_keys == 0/);
  assert.match(staging, /expected_metrics \| to_entries/);
  assert.match(staging, /SEO_INTEL_ALLOW_EXTERNAL_API_CALLS=false SEO_INTEL_WRITE_ENABLED=false/);
  assert.match(staging, /gsc_external_read_performed: true/);
  assert.match(staging, /council_runtime:[\s\S]+external_calls: 0/);
  assert.match(staging, /cms_writes: 0/);
  assert.match(staging, /url_truth_writes: 0/);
  assert.match(staging, /search_writes: 0/);
  assert.match(staging, /business_writes: 0/);
  assert.match(staging, /production_permissions: 0/);
  assert.match(staging, /execution_allowed: false/);

  assert.doesNotMatch(production, /SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON/);
  assert.doesNotMatch(production, /seo-intel:gsc-sync/);
  assert.doesNotMatch(production, /analytics:refresh-seo-conversion-daily/);
});
