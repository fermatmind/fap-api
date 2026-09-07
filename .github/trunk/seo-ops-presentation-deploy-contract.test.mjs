import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import test from "node:test";
import { classifyPaths, SEO_OPS_PRESENTATION_PATHS } from "./classify-paths.mjs";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");

test("runtime closeout selection consumes the verified CI receipt and defaults old receipts to full checks", () => {
  const expression = deploy.match(/seo_council_runtime_closeout="\$\(jq -r '([^']+)' "\$receipt"\)"/s)?.[1];
  assert.ok(expression);
  const required = (operations) => execFileSync("jq", ["-r", expression], {
    input: JSON.stringify({ classification: { operations } }), encoding: "utf8",
  }).trim();
  const presentation = classifyPaths([...SEO_OPS_PRESENTATION_PATHS]);
  assert.equal(required(presentation.operations), "false");
  assert.equal(required(classifyPaths([...presentation.paths, "backend/config/seo_council.php"]).operations), "true");
  for (const value of [undefined, null, false, "true", 1]) {
    assert.equal(required({ seo_council_orchestration: true, seo_ops_presentation_only: value }), "true");
  }
  assert.match(deploy, /seo_council_runtime_closeout: \$\{\{ steps\.receipt\.outputs\.seo_council_runtime_closeout \}\}/);
  assert.match(deploy, /echo "seo_council_runtime_closeout=\$seo_council_runtime_closeout"/);
  assert.match(deploy, /\.seo_council_orchestration\.required == \.classification\.operations\.seo_council_orchestration/);
});

test("one selection gates the entire live readiness chain, never just the failure enforcement", () => {
  for (const name of ["Materialize inactive staging measurement candidate", "Verify staging measurement source readiness",
    "Upload sanitized staging measurement source receipt", "Enforce staging measurement source readiness",
    "Finalize staging SEO Council closeout", "Read staging SEO Council closeout receipt"]) {
    const step = deploy.slice(deploy.indexOf(`      - name: ${name}`)).split(/\n      - (?:name:|uses:|id:)/)[0];
    assert.match(step, /if: needs\.policy\.outputs\.seo_council_runtime_closeout == 'true'/, name);
  }
  assert.equal((deploy.match(/council_orchestration='\$\{\{ needs\.policy\.outputs\.seo_council_runtime_closeout \}\}'/g) || []).length, 2);
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
  assert.match(deploy, /GSC_RESTRICTED_EGRESS_TRANSPORT_FAILED/);
  assert.match(deploy, /staging_closeout=HOLD reason=MEASUREMENT_SOURCE_READINESS_HOLD/);
  assert.doesNotMatch(deploy, /seo:council-runtime resume|seo:council-scheduled --acceptance|workflow_dispatch:/);
});
