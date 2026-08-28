import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const publisher = readFileSync(
  new URL("../../backend/scripts/operations/career_current_authority_publish.php", import.meta.url),
  "utf8",
);

test("CI cannot emit a green exact-SHA receipt when required Career parity is absent", () => {
  assert.match(ci, /career-publisher-parity:/);
  assert.match(ci, /CAREER_PARITY_RESULT/);
  assert.match(ci, /publisher:\{required:\$classification\[0\]\.operations\.publisher_required/);
  assert.match(ci, /\$required and \.status == "pass"/);
  assert.match(ci, /publisher:\{required:/);
});

test("deploy consumes the CI publisher decision and runs staging parity before production", () => {
  assert.match(deploy, /career_current="\$\(jq -r '\.publisher\.required == true'/);
  assert.doesNotMatch(deploy, /startsWith\("backend\/content_assets\/career\/current\/"\)/);
  assert.match(deploy, /-o IdentitiesOnly=yes \\\n\s+-i "\$DEPLOY_IDENTITY_FILE_STG"/);
  const stagingParity = deploy.indexOf("Run staging zero-write 2092-page Career parity");
  const production = deploy.indexOf("production:\n");
  assert.ok(stagingParity > 0 && production > stagingParity);
});

test("production publisher is bound to the staging receipt digest", () => {
  assert.match(deploy, /CAREER_CURRENT_PUBLISH_STAGING_PARITY_RECEIPT_DIGEST/);
  assert.match(deploy, /\.parity\.staging_receipt_digest == env\.STAGING_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /CAREER_CURRENT_PUBLISH_STAGING_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /CareerJobDetailCanonicalCacheReader::compilerDigest\(\)/);
  assert.match(publisher, /CareerJobDetailCanonicalCacheReader::codecDigest\(\)/);
});
