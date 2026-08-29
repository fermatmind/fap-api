import assert from "node:assert/strict";
import { mkdtempSync, readFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { spawnSync } from "node:child_process";
import test from "node:test";

const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");
const workflow = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");

function taskBlock(name) {
  const marker = `task('${name}'`;
  const start = deployer.indexOf(marker);
  assert.notEqual(start, -1, `missing Deployer task ${name}`);
  const next = deployer.indexOf("\ntask('", start + marker.length);
  return deployer.slice(start, next === -1 ? deployer.length : next);
}

function callbackBlock(variable, firstTask) {
  const start = deployer.indexOf(`$${variable} = function`);
  assert.notEqual(start, -1, `missing callback ${variable}`);
  const end = deployer.indexOf(`task('${firstTask}'`, start);
  assert.notEqual(end, -1, `missing callback binding ${firstTask}`);
  return deployer.slice(start, end);
}

test("rollback healthchecks use an isolated current-release task graph", () => {
  assert.match(deployer, /after\('rollback', 'bootstrap-cache:rebuild-current'\);/);
  assert.match(deployer, /after\('bootstrap-cache:rebuild-current', 'rollback:healthcheck'\);/);

  const aggregate = taskBlock("rollback:healthcheck");
  assert.doesNotMatch(aggregate, /'healthcheck:public'/);
  assert.match(aggregate, /'rollback:healthcheck:public'/);
  assert.match(aggregate, /'rollback:healthcheck:sitemap-source'/);
  assert.match(aggregate, /'rollback:healthcheck:public-dns'/);
  assert.match(aggregate, /'rollback:healthcheck:auth-guest-contract'/);
  assert.match(aggregate, /'rollback:healthcheck:public-static-media-assets'/);
  assert.match(aggregate, /'rollback:healthcheck:ops-entry-contract'/);
  assert.doesNotMatch(aggregate, /(?<!rollback:)'healthcheck:/);

  const reachableTasks = [
    "bootstrap-cache:rebuild-current",
    "rollback:healthcheck",
    "reload:php-fpm",
    "reload:nginx",
    "rollback:healthcheck:public",
    "rollback:healthcheck:sitemap-source",
    "rollback:healthcheck:public-dns",
    "rollback:healthcheck:auth-guest-contract",
    "rollback:healthcheck:public-static-media-assets",
    "rollback:healthcheck:ops-entry-contract",
  ];
  for (const task of reachableTasks) {
    assert.doesNotMatch(taskBlock(task), /\{\{release_path\}\}/, `${task} reaches candidate release_path`);
  }
  const sharedCallbacks = [
    ["authGuestContractHealthcheck", "healthcheck:auth-guest-contract"],
    ["publicStaticMediaAssetsHealthcheck", "healthcheck:public-static-media-assets"],
    ["opsEntryContractHealthcheck", "healthcheck:ops-entry-contract"],
  ];
  for (const [callback, task] of sharedCallbacks) {
    assert.doesNotMatch(callbackBlock(callback, task), /\{\{release_path\}\}/, `${callback} reaches candidate release_path`);
    assert.match(deployer, new RegExp(`task\\('rollback:${task}', \\$${callback}\\);`));
  }
  assert.match(deployer, /after\('healthcheck:ops-entry-contract', 'seo:ledger-production-closeout'\);/);
  assert.doesNotMatch(aggregate, /'healthcheck:ops-entry-contract'/);
});

test("normal deploy keeps release-bound public health ordering", () => {
  assert.match(deployer, /after\('deploy:symlink', 'healthcheck:public'\);/);
  assert.match(deployer, /after\('healthcheck:public', 'healthcheck:sitemap-source'\);/);
  assert.match(deployer, /after\('healthcheck:sitemap-source', 'healthcheck:public-dns'\);/);
  assert.match(
    taskBlock("healthcheck:public-dns"),
    /runProductionPublicDnsBusinessEvidence\('\{\{release_path\}\}'\)/,
  );
  assert.match(
    taskBlock("guard:public-dns-health"),
    /runProductionPublicDnsBusinessEvidence\('\{\{release_path\}\}'\)/,
  );
  assert.doesNotMatch(deployer, /runProductionPublicDnsBusinessEvidence\(\)/);
  assert.match(
    taskBlock("rollback:healthcheck:public-dns"),
    /runProductionPublicDnsBusinessEvidence\('\{\{current_path\}\}'\)/,
  );
});

test("rollback always re-reads REVISION and emits a sanitized exact-SHA receipt", () => {
  const rollbackStart = workflow.indexOf("php /tmp/dep.phar rollback production");
  assert.notEqual(rollbackStart, -1);
  const rollbackFlow = workflow.slice(rollbackStart, workflow.indexOf("      - name: Read production SEO Evidence", rollbackStart));

  assert.match(rollbackFlow, /rollback_rc=\$\?/);
  assert.match(rollbackFlow, /restored=.*current\/REVISION/);
  assert.match(rollbackFlow, /safe_restored=""/);
  assert.match(rollbackFlow, /\[\[ "\$restored" =~ \^\[0-9a-f\]\{40\}\$ \]\]/);
  assert.match(rollbackFlow, /\[ "\$safe_restored" = "\$lkg_sha" \]/);
  assert.match(rollbackFlow, /if \[ "\$restoration_completed" != true \]/);
  assert.match(rollbackFlow, /if \[ "\$rollback_rc" -ne 0 \]/);
  assert.match(rollbackFlow, /exit "\$deploy_rc"/);

  const receiptStart = rollbackFlow.indexOf("jq -n \\");
  const receiptEnd = rollbackFlow.indexOf("> lkg-rollback-receipt.json", receiptStart);
  assert.notEqual(receiptStart, -1);
  assert.notEqual(receiptEnd, -1);
  const receipt = rollbackFlow.slice(receiptStart, receiptEnd);
  assert.match(receipt, /fermatmind\.deploy_lkg_rollback\.v1/);
  assert.match(receipt, /candidate_sha:\$candidate_sha,lkg_sha:\$lkg_sha/);
  assert.match(receipt, /restored_sha:/);
  assert.match(receipt, /restoration_completed:\$restoration_completed/);
  assert.match(receipt, /restoration_mismatch:\$restoration_mismatch/);
  assert.match(receipt, /sanitized:true/);
  assert.doesNotMatch(receipt, /DEPLOY_HOST|DEPLOY_PATH|SSH_|log|output|server|path/i);
  assert.match(workflow, /lkg-rollback-receipt\.json/);

  const receiptCommand = rollbackFlow.slice(
    receiptStart,
    receiptEnd + "> lkg-rollback-receipt.json".length,
  );
  const root = mkdtempSync(join(tmpdir(), "lkg-rollback-receipt-"));
  const candidateSha = "a".repeat(40);
  const lkgSha = "b".repeat(40);
  const generated = spawnSync("bash", ["-c", receiptCommand], {
    cwd: root,
    encoding: "utf8",
    env: {
      ...process.env,
      candidate_sha: candidateSha,
      lkg_sha: lkgSha,
      safe_restored: lkgSha,
      status: "restored",
      GITHUB_RUN_ID: "123",
      GITHUB_RUN_ATTEMPT: "2",
      rollback_command_succeeded: "true",
      rollback_healthchecks_succeeded: "true",
      restoration_completed: "true",
      restoration_mismatch: "false",
    },
  });
  assert.equal(generated.status, 0, generated.stderr);
  const payload = JSON.parse(readFileSync(join(root, "lkg-rollback-receipt.json"), "utf8"));
  assert.deepEqual(Object.keys(payload).sort(), [
    "candidate_sha",
    "incident",
    "lkg_sha",
    "restored_sha",
    "rollback",
    "sanitized",
    "schema_version",
    "status",
    "workflow",
  ]);
  assert.equal(payload.candidate_sha, candidateSha);
  assert.equal(payload.lkg_sha, lkgSha);
  assert.equal(payload.restored_sha, lkgSha);
  assert.equal(payload.rollback.restoration_completed, true);
  assert.equal(payload.incident.restoration_mismatch, false);
  assert.equal(payload.sanitized, true);
});
