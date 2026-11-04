import assert from "node:assert/strict";
import {readFileSync} from "node:fs";
import test from "node:test";
const deploy = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");
const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
test("directory gzip uses the existing same-SHA staging and production deployment lane", () => {
  const start = deploy.indexOf("task('ensure:career-directory-gzip'");
  assert.ok(start > 0);
  const task = deploy.slice(start, deploy.indexOf("\ntask('", start + 10));
  assert.match(task, /deploySkipsAuthorityMutations/);
  assert.match(task, /install_career_directory_gzip\.py/);
  assert.match(task, /get\('nginx_site'\)/);
  assert.match(task, /get\('healthcheck_host'\)/);
  assert.match(deploy, /before\('reload:nginx', 'ensure:career-directory-gzip'\)/);
  assert.match(ci, /install -y --no-install-recommends nginx-light/);
  assert.match(ci, /test_career_directory_gzip\.py/);
});
