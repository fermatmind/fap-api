import {tmpdir} from 'node:os';
import assert from "node:assert/strict";
import { execFileSync, spawnSync } from "node:child_process";
import { readFileSync, readdirSync, mkdtempSync, writeFileSync, rmSync } from "node:fs";
import test from "node:test";
import { classifyPaths } from "./classify-paths.mjs";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");

test("daily read-only software uses existing code-only deployment without A08 proofs", () => {
  const classification = classifyPaths([
    "backend/app/Services/SeoCouncil/Platform12/Platform12RuntimeControl.php",
    "backend/app/Services/SeoCouncil/Platform12/Platform12EvidenceSelection.php",
    ".github/workflows/ci.yml", ".github/workflows/deploy.yml",
  ]);
  assert.equal(classification.deploy_mode, 'code_only');
  assert.equal(classification.operations.seo_council_orchestration, false);
  assert.ok(Object.keys(classification.operations).every(key => !key.startsWith('a08_')));
  assert.doesNotMatch(ci + deploy, /a08_scoped_checks|a08_gate_only|a08-scoped-checks/);
  assert.match(deploy, /deploy_task=deploy:code-only\n            deploy_mode=code_only/);
});

test("deployment rejects a success result for an unselected Council job", () => {
  const predicate = '.seo_council_orchestration.required == false and .seo_council_orchestration.result == "skipped"';
  assert.ok(deploy.includes('and .seo_council_orchestration.result == "skipped"'));
  for (const result of ['success', 'skipped', 'failure', 'cancelled']) {
    const run = spawnSync('jq', ['-e', predicate], {encoding: 'utf8',
      input: JSON.stringify({seo_council_orchestration: {required: false, result}})});
    assert.equal(run.status === 0, result === 'skipped');
  }
});

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
  assert.match(deploy, /\.classification\.operations\.enneagram_private_result_authority_release == true/);
  assert.match(deploy, /if \.classification\.operations\.seo_council_orchestration == true/);
  assert.doesNotMatch(deploy, /career_current=false|career_current_full_scan=false/);
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
  assert.equal((deployer.match(/get\('private_result_authority_publish_required', true\)/g) || []).length, 1);
  assert.match(deployer, /"unavailable_dependency_refs" => \$unavailableRefs/);
  assert.match(deployer, /SEO Council safe source diagnostic/);
  assert.match(deployer, /"url_truth_query_available" => false/);
  assert.match(deployer, /"technical_health_read_available" => false/);
  assert.doesNotMatch(deployer, /seo\.council_closeout\.v1/);
  assert.match(deployer, /release-receipts\/seo-council-orchestration/);
  assert.match(deployer, /task\('healthcheck:seo-council-anonymous'/);
  assert.doesNotMatch(`${ci}\n${deploy}`, /seo-agent:/);
});

test("Natural runtime persistence uses the existing isolated writer and keeps generic SEO writes disabled", () => {
  assert.equal((deployer.match(/task\('seo:council-runtime-db-access'/g) || []).length, 1);
  assert.doesNotMatch(deployer, /GRANT (?:SELECT|ALL|DELETE)/);
  assert.match(deployer, /SEO_COUNCIL_DB_CONNECTION' => 'seo_council'/);
  assert.match(deployer, /SEO_COUNCIL_APPROVED_DB_USERNAME/);
  assert.match(deployer, /SEO_COUNCIL_APPROVED_DB_PASSWORD/);
  assert.match(deployer, /'staging' => 'seo_intel_staging_writer'/);
  assert.match(deployer, /'production' => 'seo_intel_writer'/);
  assert.match(deployer, /'staging' => 'seo_intel_staging_migrator'/);
  assert.match(deployer, /'production' => 'seo_intel_migrator'/);
  assert.match(deployer, /SEO_INTEL_DB_ENVIRONMENT_BOUNDARY_INVALID/);
  assert.match(deployer, /SEO_INTEL_MIGRATION_ENVIRONMENT_BOUNDARY_INVALID/);
  assert.doesNotMatch(deployer, /SEO_COUNCIL_RUNTIME_DB_USERNAME/);
  assert.doesNotMatch(deployer, /SEO_COUNCIL_MIGRATION_DB_USERNAME/);
  assert.match(
    deployer,
    /SEO_COUNCIL_RUNTIME_WRITER_UNAVAILABLE: verify SEO_COUNCIL_DB credentials and SEO_INTEL_DB target/,
  );
  assert.match(deployer, /SEO Council unavailable writer aliases removed/);
  assert.match(deployer, /\.seo-council-env-scrub-/);
  assert.match(deployer, /"reader" => "SEO_INTEL_RUNTIME_READER"/);
  assert.match(deployer, /"writer" => "SEO_COUNCIL_RUNTIME_WRITER"/);
  assert.match(deployer, /_GUARD_FAILED_/);
  assert.equal((deploy.match(/SEO_COUNCIL_DB_USERNAME: \$\{\{ secrets\.SEO_COUNCIL_DB_USERNAME \}\}/g) || []).length, 3);
  assert.equal((deploy.match(/SEO_COUNCIL_DB_PASSWORD: \$\{\{ secrets\.SEO_COUNCIL_DB_PASSWORD \}\}/g) || []).length, 3);
  assert.match(deployer, /preg_quote\(\$expectedUsername/);
  assert.match(deployer, /config\('seo_intel\.write_enabled'\) !== false/);
  assert.match(deployer, /\$council->beginTransaction\(\)/);
  assert.match(deployer, /\$council->rollBack\(\)/);
  assert.match(deployer, /SEO_COUNCIL_RUNTIME_DB_WRITE_PRIVILEGE_MISSING/);
  assert.match(deployer, /after\('guard:no-pending-seo-intel-migrations', 'seo:council-runtime-db-access'\)/);
});


test("Council-only changes retain the full shared validation without A08 artifacts", () => {
  const classification = classifyPaths(["backend/lang/en/ops.php"]);
  assert.equal(classification.operations.seo_council_orchestration, true);
  assert.doesNotMatch(ci, /a08_scoped|A08_SCOPES/);
});
test('HMAC bootstrap atomically installs the fixed pair and refuses existing-key rotation',()=>{
 const deployer=readFileSync(new URL('../../deploy.php',import.meta.url),'utf8');
 const section=deployer.slice(deployer.indexOf('function deployInstallSeoIntelRuntimeEnvironment'),deployer.indexOf("task('runtime:configure-seo-intel'"));
 const script=section.match(/\$script = <<<'PHP'\n([\s\S]+?)\nPHP;/)[1];
 const dir=mkdtempSync(`${tmpdir()}/a08-hmac-`), env=`${dir}/env`, patch=`${dir}/patch`;
 const pair={SEO_AGENT_EVIDENCE_QUERY_HMAC_KEY:'a'.repeat(64),SEO_AGENT_EVIDENCE_QUERY_HMAC_KEY_VERSION:'v1-test'};
 const run=()=>execFileSync('php',['-d','display_errors=0','-r',script,env,patch],{stdio:['pipe','pipe','pipe']});
 try {
  writeFileSync(env,'UNRELATED="preserved"\n');writeFileSync(patch,JSON.stringify(pair));
  run();const initialized=readFileSync(env,'utf8');assert.match(initialized,/UNRELATED="preserved"/);
  run();assert.equal(readFileSync(env,'utf8'),initialized);
  writeFileSync(patch,JSON.stringify({...pair,SEO_AGENT_EVIDENCE_QUERY_HMAC_KEY:'b'.repeat(64)}));
  assert.throws(run);assert.equal(readFileSync(env,'utf8'),initialized);
  writeFileSync(patch,JSON.stringify({...pair,UNAPPROVED:'value'}));assert.throws(run);
  assert.equal(readFileSync(env,'utf8'),initialized);
 } finally {rmSync(dir,{recursive:true,force:true});}
 const deploy=readFileSync(new URL('../workflows/deploy.yml',import.meta.url),'utf8');
 for(const key of Object.keys(pair)) assert.equal(deploy.split(`${key}: \${{ secrets.${key} }}`).length-1,3);
});

test('code-only keeps ordinary full-inventory and cache-maintenance skips executable',()=>{
 for(const name of ['seo:url-truth-reconciliation-receipt','healthcheck:sitemap-source','career:warm-public-authority-cache']) {
  const start=deployer.indexOf(`task('${name}'`);assert.ok(start>=0,name);
  const end=deployer.indexOf('\n    }\n',start);assert.ok(end>start,name);
  const prefix=deployer.slice(start,end+'\n    }\n'.length);
  const script=`namespace Deployer; function deployMode(){return getenv('TEST_MODE');} function deployIsCodeOnly(){return deployMode()==='code_only';} function writeln($text){} function task($name,$callback){$callback();} ${prefix} throw new \\RuntimeException('FULL_MAINTENANCE_REACHED'); });`;
  for(const mode of ['standard','code_only','schema_only']) {
   const r=spawnSync('php',['-r',script],{env:{...process.env,TEST_MODE:mode},encoding:'utf8'});
   assert.equal(r.status===0,mode!=='schema_only',`${name}/${mode}: ${r.stderr}`);
  }
 }
});
