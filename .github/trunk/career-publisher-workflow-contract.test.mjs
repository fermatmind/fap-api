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
  assert.match(deployer, /after\('artisan:config:cache', 'seo:competitive-evidence-preactivation'\)/);
  assert.doesNotMatch(deployer, /seo:competitive-measurement-refresh/);
  assert.match(deployer, /after\('seo:competitive-evidence-preactivation', 'career:current-authority-production-preactivation-parity'\)/);
  assert.match(deployer, /after\('career:current-authority-production-preactivation-parity', 'guard:sitemap-authority'\)/);
});

test("Career data recovery is classifier-bound while recommendation publication requires production authority", () => {
  assert.match(ci, /career_data_recovery=\$\(jq -r \.operations\.career_data_recovery/);
  assert.match(deploy, /career_data_recovery="\$\(jq -r '\.classification\.operations\.career_data_recovery == true'/);
  assert.equal((deploy.match(/-o career_data_recovery=/g) ?? []).length, 2);
  assert.match(deployer, /task\('career:recover-data'/);
  assert.match(deployer, /career:recover-guide-locale-corruption --execute --json/);
  assert.match(deployer, /career:compile-recommendation-subjects --no-interaction/);
  assert.match(deployer, /set\('career_recommendation_publish_required', true\)/);
  assert.match(deployer, /set\('career_recommendation_publish_required', false\)/);
  assert.match(deployer, /if \(\(bool\) get\('career_recommendation_publish_required'\)\)/);
  assert.match(deployer, /task\('healthcheck:career-data-recovery'/);
  assert.match(deployer, /after\('healthcheck:public-dns', 'healthcheck:career-data-recovery'\)/);
});

test("production publisher is bound to the preactivation receipt digest", () => {
  assert.match(deploy, /needs\.policy\.outputs\.career_current_release == 'true'/);
  assert.match(deploy, /CAREER_CURRENT_PUBLISH_PRODUCTION_PARITY_RECEIPT_DIGEST/);
  assert.doesNotMatch(deploy, /needs\.production\.outputs\.career_parity_digest/);
  assert.match(deploy, /Download exact production Career parity receipt/);
  assert.match(deploy, /trunk-production-\$\{\{ github\.event\.workflow_run\.head_sha \}\}/);
  assert.match(deploy, /production_parity_receipt="artifacts\/career-current-production-parity\/career-current-authority-production-preactivation-parity\.json"/);
  assert.match(deploy, /PRODUCTION_PARITY_RECEIPT_DIGEST="\$\(jq -r \.receipt_digest "\$production_parity_receipt"\)"/);
  assert.match(deploy, /\.validation_scope\.canonical_slugs == \["accountants-and-auditors"\]/);
  assert.match(deploy, /\.validation_scope\.locale_page_count == 2/);
  assert.match(deploy, /\.parity\.production_preactivation_receipt_digest == env\.PRODUCTION_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /CAREER_CURRENT_PUBLISH_PRODUCTION_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /validation_scope\.canonical_slugs/);
  assert.doesNotMatch(publisher, /CAREER_CURRENT_PUBLISH_STAGING_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /CareerCurrentAuthorityParity::CONTRACT_VERSION/);
  assert.match(publisher, /CareerJobDetailCanonicalCacheReader::compilerDigest\(\)/);
  assert.match(publisher, /CareerJobDetailCanonicalCacheReader::codecDigest\(\)/);
});
