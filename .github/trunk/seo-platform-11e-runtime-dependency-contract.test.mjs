import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");

const runtimeKeys = [
  "SEO_INTEL_ENABLED",
  "SEO_INTEL_DB_CONNECTION",
  "SEO_INTEL_DB_HOST",
  "SEO_INTEL_DB_PORT",
  "SEO_INTEL_DB_DATABASE",
  "SEO_INTEL_DB_USERNAME",
  "SEO_INTEL_DB_PASSWORD",
  "SEO_INTEL_WRITE_ENABLED",
  "SEO_INTEL_COLLECTORS_ENABLED",
  "SEO_INTEL_DRY_RUN_DEFAULT",
  "SEO_INTEL_ALLOW_EXTERNAL_API_CALLS",
];

const migrationKeys = [
  "SEO_INTEL_MIGRATION_DB_USERNAME",
  "SEO_INTEL_MIGRATION_DB_PASSWORD",
];

test("11E binds isolated GitHub Environment runtime values to both deployment jobs", () => {
  for (const environment of ["staging", "production"]) {
    assert.match(deploy, new RegExp(`SEO_INTEL_RUNTIME_ENVIRONMENT: ${environment}`));
  }
  for (const key of runtimeKeys) {
    const source = ["SEO_INTEL_DB_USERNAME", "SEO_INTEL_DB_PASSWORD"].includes(key) ? "secrets" : "vars";
    const pattern = new RegExp(`${key}: \\$\\{\\{ ${source}\\.${key} \\}\\}`, "g");
    assert.equal((deploy.match(pattern) || []).length, 2, key);
  }
  for (const key of migrationKeys) {
    const pattern = new RegExp(`${key}: \\$\\{\\{ secrets\\.${key} \\}\\}`, "g");
    assert.equal((deploy.match(pattern) || []).length, 2, key);
  }
});

test("11E atomically injects only the approved keys before config cache and fails closed", () => {
  assert.match(deployer, /task\('runtime:configure-seo-intel'/);
  assert.match(deployer, /SEO_INTEL_PATCH_SCOPE_INVALID/);
  assert.match(deployer, /SEO_INTEL_ENVIRONMENT_RENAME_FAILED/);
  assert.match(deployer, /SEO_INTEL_ENVIRONMENT_READBACK_FAILED/);
  assert.match(deployer, /after\('crawler:configure-aggregate-runtime', 'runtime:configure-seo-intel'\)/);
  assert.match(deployer, /before\('artisan:config:cache', 'guard:seo-intel-runtime-config'\)/);
  assert.match(deployer, /DB::connection\("seo_intel"\)->selectOne\("SELECT 1 AS probe"\)/);
  assert.match(deployer, /in_array\(\$driverCode, \[1044, 1045\], true\) => "credentials"/);
  assert.match(deployer, /in_array\(\$driverCode, \[2002, 2003, 2005, 2006\], true\) => "transport"/);
  assert.doesNotMatch(deployer, /fwrite\(STDERR, \$throwable->getMessage\(\)\)/);
  assert.match(deployer, /\$seo\["database"\].+\$business\["database"\]/s);
  assert.match(deployer, /config\("seo_intel\.write_enabled"\) === false/);
  assert.match(deployer, /config\("seo_intel\.collectors_enabled"\) === false/);
  assert.match(deployer, /config\("seo_intel\.allow_external_api_calls"\) === false/);
  assert.match(deployer, /Staging SEO Intel migration and runtime accounts must be distinct/);
  assert.match(deployer, /database\.connections\.seo_intel\.username/);
  assert.match(deployer, /DB::purge\('seo_intel'\)/);
  assert.match(deployer, /\['env' => \$migration\]/);
});

test("11E read-only detector receipt never enters the materialization branch", () => {
  assert.match(deployer, /config\("seo_intel\.write_enabled"\) \? 0 : 43/);
  assert.match(deployer, /if \[ "\$detector_config_status" -eq 43 \]; then[\s\S]+?\["available", "measurement_hold"\][\s\S]+?detector_source_measurement_hold[\s\S]+?writes_attempted[\s\S]+?exit 0[\s\S]+?--materialize-detector-queues/);
});
