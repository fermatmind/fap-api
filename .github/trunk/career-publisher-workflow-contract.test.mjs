import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");
const publisher = readFileSync(
  new URL("../../backend/scripts/operations/career_current_authority_publish.php", import.meta.url),
  "utf8",
);

test("CI cannot emit a green exact-SHA receipt when required Career package scan is absent", () => {
  assert.match(ci, /career-publisher-parity:/);
  assert.match(ci, /career\.current_authority_package_scan\.v1/);
  assert.match(ci, /CAREER_PARITY_RESULT/);
  assert.match(ci, /publisher:\{required:\$classification\[0\]\.operations\.publisher_required/);
  assert.match(ci, /\$required and \.status == "pass"/);
  assert.match(ci, /publisher:\{required:/);
});

test("deploy consumes the CI decision and leaves staging on its public cohort", () => {
  assert.match(deploy, /career_current="\$\(jq -r '\.publisher\.required == true'/);
  assert.doesNotMatch(deploy, /startsWith\("backend\/content_assets\/career\/current\/"\)/);
  assert.doesNotMatch(deploy, /Run staging zero-write 2092-page Career parity/);
  assert.match(deploy, /career_current_parity_required/);
  assert.match(deployer, /career:current-authority-production-preactivation-parity/);
  assert.match(deployer, /after\('artisan:config:cache', 'career:current-authority-production-preactivation-parity'\)/);
  assert.match(deployer, /after\('career:current-authority-production-preactivation-parity', 'guard:sitemap-authority'\)/);
});

test("production publisher is bound to the preactivation receipt digest", () => {
  assert.match(deploy, /CAREER_CURRENT_PUBLISH_PRODUCTION_PARITY_RECEIPT_DIGEST/);
  assert.match(deploy, /\.parity\.production_preactivation_receipt_digest == env\.PRODUCTION_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /CAREER_CURRENT_PUBLISH_PRODUCTION_PARITY_RECEIPT_DIGEST/);
  assert.doesNotMatch(publisher, /CAREER_CURRENT_PUBLISH_STAGING_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /CareerCurrentAuthorityParity::CONTRACT_VERSION/);
  assert.match(publisher, /CareerJobDetailCanonicalCacheReader::compilerDigest\(\)/);
  assert.match(publisher, /CareerJobDetailCanonicalCacheReader::codecDigest\(\)/);
});
