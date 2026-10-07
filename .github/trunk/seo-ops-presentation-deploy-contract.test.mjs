import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import test from "node:test";
import { classifyPaths, SEO_OPS_PRESENTATION_PATHS } from "./classify-paths.mjs";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");

test("ordinary release never requires operating source closeout, while CI still verifies its selected software", () => {
  assert.match(deploy, /seo_council_runtime_closeout=false/);
  assert.match(deploy, /seo_competitive_evidence=false/);
  assert.doesNotMatch(deploy, /seo_council_runtime_closeout="\$\(jq/);
  assert.match(deploy, /seo_council_runtime_closeout: \$\{\{ steps\.receipt\.outputs\.seo_council_runtime_closeout \}\}/);
  assert.match(deploy, /echo "seo_council_runtime_closeout=\$seo_council_runtime_closeout"/);
  assert.match(deploy, /\.seo_council_orchestration\.required == \.classification\.operations\.seo_council_orchestration/);
});

test("M3 source preparation stays separate from full closeout and ordinary presentation", () => {
  for (const name of ["Materialize inactive staging measurement candidate", "Verify staging measurement source readiness",
    "Upload sanitized staging measurement source receipt", "Enforce staging measurement source readiness"]) {
    const step = deploy.slice(deploy.indexOf(`      - name: ${name}`)).split(/\n      - (?:name:|uses:|id:)/)[0];
    assert.match(step, /if: \(?needs\.policy\.outputs\.seo_council_runtime_closeout == 'true' \|\| needs\.policy\.outputs\.seo_competitive_evidence == 'true'/, name);
  }
  for (const name of ["Finalize staging SEO Council closeout", "Read staging SEO Council closeout receipt"]) {
    const step = deploy.slice(deploy.indexOf(`      - name: ${name}`)).split(/\n      - (?:name:|uses:|id:)/)[0];
    assert.match(step, /if: needs\.policy\.outputs\.seo_council_runtime_closeout == 'true'\n/, name);
  }
  assert.equal(classifyPaths([...SEO_OPS_PRESENTATION_PATHS]).operations.seo_competitive_evidence, false);
  assert.equal(classifyPaths(["backend/app/Services/SeoCouncil/Platform12/Platform12RuntimeControl.php"]).operations.seo_competitive_evidence, false);
  const m3 = classifyPaths(["backend/app/Services/SeoCouncil/Platform12/Platform12EvidenceSelection.php"]);
  assert.equal(m3.operations.seo_competitive_evidence, false);
  assert.equal(m3.operations.seo_competitive_checks, true);
  assert.equal(m3.operations.a08_readonly_wiring, true);
  assert.equal((deploy.match(/council_orchestration='\$\{\{ needs\.policy\.outputs\.seo_council_runtime_closeout \}\}'/g) || []).length, 3);
  assert.doesNotMatch(deploy, /needs\.policy\.outputs\.seo_council_orchestration/);
  assert.match(deploy, /measurement_source_readiness:\$source_readiness/);
  assert.match(deploy, /council_runtime_closeout_required:\(\$council_closeout == "true"\)/);
});

test("presentation still requires privacy, RBAC, exact SHA, both environments and normal smoke", () => {
  assert.match(ci, /if: needs\.classify\.outputs\.seo_council_orchestration == 'true'/);
  assert.match(ci, /tests\/Feature\/Ops\/SeoAgentRolePresentationTest\.php/);
  assert.match(ci, /tests\/Feature\/Ops\/SeoOperationsPageTest\.php/);
  assert.match(ci, /tests\/Feature\/SeoIntel\/SeoPlatform12\*\.php/);
  assert.match(deploy, /name: Deploy staging and run repository smoke chain/);
  assert.match(deploy, /production:\n[^]*?needs: \[policy, staging\]\n\s+if: needs\.staging\.result == 'success'/);
  assert.match(deploy, /environment: staging/);
  assert.match(deploy, /environment: production/);
  assert.match(deploy, /and \.sha == \$sha/);
  assert.match(deploy, /and \.ci_run_id == \$run/);
  assert.match(deploy, /and \.result == "success"/);
  assert.match(deploy, /MEASUREMENT_PREPARE_FAILED/);
  assert.match(deploy, /staging_closeout=HOLD reason=MEASUREMENT_SOURCE_READINESS_HOLD/);
  assert.doesNotMatch(deploy, /seo:council-runtime resume|seo:council-scheduled --acceptance|workflow_dispatch:/);
});
