import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import test from "node:test";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");

test("11D through 11L extend only the permanent CI and deploy control plane", () => {
  const workflows = readdirSync(new URL("../workflows", import.meta.url)).filter((name) => name.endsWith(".yml")).sort();
  assert.deepEqual(workflows, ["ci.yml", "deploy.yml", "nightly.yml", "recovery.yml"]);
  assert.match(ci, /seo-council-orchestration:/);
  assert.match(ci, /seo:council-closeout --expected-sha="\$GITHUB_SHA"/);
  assert.match(ci, /seo\.council_closeout\.v2/);
  assert.match(ci, /SEO-PLATFORM-11E/);
  assert.match(ci, /ready_for_11F/);
  assert.match(ci, /SEO-PLATFORM-11H/);
  assert.match(ci, /SEO-PLATFORM-11I/);
  assert.match(ci, /SEO-PLATFORM-11J/);
  assert.match(ci, /SEO-PLATFORM-11K/);
  assert.match(ci, /SEO-PLATFORM-11L/);
  assert.match(ci, /seo\.platform11_closeout\.v1/);
  assert.match(ci, /ready_for_12/);
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
  assert.match(deploy, /if \.classification\.operations\.seo_council_orchestration == true/);
  assert.match(deploy, /if \[ "\$private_result_authority_publish_required" = false \]; then\s+#[^\n]*\n\s+#[^\n]*\n\s+career_current=false\s+career_current_full_scan=false/);
});

test("11D through 11L deployment stays disabled and writes immutable exact-SHA closeout receipts only", () => {
  assert.equal((deployer.match(/task\('seo:council-orchestration-closeout'/g) || []).length, 1);
  assert.match(deployer, /set\('seo_council_orchestration', false\)/);
  assert.match(deployer, /"\$\{runner\[@\]\}" seo:council-closeout --expected-sha="\$expected_sha"/);
  assert.match(deployer, /seo\.council_closeout\.v2/);
  assert.match(deployer, /SEO-PLATFORM-11E/);
  assert.match(deployer, /ready_for_11F/);
  assert.match(deployer, /SEO-PLATFORM-11H/);
  assert.match(deployer, /SEO-PLATFORM-11I/);
  assert.match(deployer, /SEO-PLATFORM-11J/);
  assert.match(deployer, /SEO-PLATFORM-11K/);
  assert.match(deployer, /SEO-PLATFORM-11L/);
  assert.match(deployer, /seo\.platform11_closeout\.v1/);
  assert.match(deployer, /ready_for_12/);
  assert.match(deployer, /after\('seo:competitive-evidence-finalize', 'seo:council-orchestration-closeout'\)/);
  assert.match(deployer, /seo\.technical_diagnosis_closeout\.v2/);
  assert.match(deployer, /closeout-environment=\{\{technical_closeout_environment\}\}/);
  assert.doesNotMatch(deployer, /after\('scheduler:wait-natural-heartbeat', 'seo:council-orchestration-closeout'\)/);
  assert.match(deployer, /set\('private_result_authority_publish_required', true\)/);
  assert.equal((deployer.match(/get\('private_result_authority_publish_required', true\)/g) || []).length, 4);
  assert.match(deployer, /"unavailable_dependency_refs" => \$unavailableRefs/);
  assert.match(deployer, /SEO Council safe source diagnostic/);
  assert.match(deployer, /"url_truth_query_available" => false/);
  assert.match(deployer, /"technical_health_read_available" => false/);
  assert.doesNotMatch(deployer, /seo\.council_closeout\.v1/);
  assert.match(deployer, /release-receipts\/seo-council-orchestration/);
  assert.match(deployer, /task\('healthcheck:seo-council-anonymous'/);
  assert.doesNotMatch(`${ci}\n${deploy}`, /seo-agent:/);
});

test("A08 runtime persistence uses the existing isolated writer and keeps generic SEO writes disabled", () => {
  assert.equal((deployer.match(/task\('seo:council-runtime-db-access'/g) || []).length, 1);
  assert.doesNotMatch(deployer, /GRANT (?:SELECT|ALL|DELETE)/);
  assert.match(deployer, /SEO_COUNCIL_DB_CONNECTION' => 'seo_council'/);
  assert.match(deployer, /'council' => 'SEO_COUNCIL_APPROVED'/);
  assert.match(deployer, /'runtime' => 'SEO_COUNCIL_RUNTIME'/);
  assert.match(deployer, /'migration' => 'SEO_COUNCIL_MIGRATION'/);
  assert.match(
    deployer,
    /SEO_COUNCIL_RUNTIME_WRITER_UNAVAILABLE: configure SEO_COUNCIL_DB_USERNAME and SEO_COUNCIL_DB_PASSWORD/,
  );
  assert.match(deployer, /SEO Council unavailable writer aliases removed/);
  assert.match(deployer, /\.seo-council-env-scrub-/);
  assert.match(deployer, /'council' => 'SEO_COUNCIL_APPROVED_DB_USERNAME'/);
  assert.match(deployer, /'council' => 'SEO_COUNCIL_APPROVED_DB_PASSWORD'/);
  assert.equal((deploy.match(/SEO_COUNCIL_DB_USERNAME: \$\{\{ secrets\.SEO_COUNCIL_DB_USERNAME \}\}/g) || []).length, 3);
  assert.equal((deploy.match(/SEO_COUNCIL_DB_PASSWORD: \$\{\{ secrets\.SEO_COUNCIL_DB_PASSWORD \}\}/g) || []).length, 3);
  assert.match(deployer, /seo_intel_writer@/);
  assert.match(deployer, /config\('seo_intel\.write_enabled'\) !== false/);
  assert.match(deployer, /\$council->beginTransaction\(\)/);
  assert.match(deployer, /\$council->rollBack\(\)/);
  assert.match(deployer, /SEO_COUNCIL_RUNTIME_DB_WRITE_PRIVILEGE_MISSING/);
  assert.match(deployer, /after\('guard:no-pending-seo-intel-migrations', 'seo:council-runtime-db-access'\)/);
});
