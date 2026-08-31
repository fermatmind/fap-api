import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");
const workflow = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const ops = readFileSync(new URL("../../backend/config/ops.php", import.meta.url), "utf8");
const queueSmoke = readFileSync(
  new URL("../../backend/scripts/deploy/verify_queue_smoke.sh", import.meta.url),
  "utf8",
);
const deliverySmoke = readFileSync(
  new URL("../../backend/scripts/deploy/verify_staging_big_five_report_delivery.sh", import.meta.url),
  "utf8",
);
const provision = readFileSync(
  new URL("../../backend/scripts/deploy/provision_staging_reports_worker_once.sh", import.meta.url),
  "utf8",
);
const sharedPermissionPaths = readFileSync(
  new URL("../../backend/scripts/deploy/shared_permissions_paths.txt", import.meta.url),
  "utf8",
);

test("staging requires only the database reports worker before activation", () => {
  const start = deployer.indexOf("$stagingHost = host('staging')");
  const end = deployer.indexOf("if ($stagingIdentityFile", start);
  assert.ok(start > 0 && end > start);
  const staging = deployer.slice(start, end);

  assert.doesNotMatch(staging, /queue_reload_required', false/);
  assert.match(staging, /'queue_supervisor_required_programs'/);
  assert.match(staging, /'fap-queue-reports'/);
  assert.doesNotMatch(staging, /'fap-queue-default-high'/);
  assert.match(deployer, /before\('deploy:symlink', 'guard:queue-reload-capability'\);/);
  assert.match(deployer, /queue capability preflight requires a recoverable supervisor program/);
});

test("completed one-time provision remains as the exact one-process reports topology", () => {
  assert.match(provision, /\[program:fap-queue-reports\]/);
  assert.match(provision, /directory=\$\{deploy_root\}\/current\/backend/);
  assert.match(
    provision,
    /command=\/usr\/bin\/php artisan queue:work database_reports --queue=reports --sleep=1 --tries=3 --timeout=180 --max-time=3600/,
  );
  assert.match(provision, /numprocs=1/);
  assert.match(provision, /user=www-data/);
  assert.match(provision, /stdout_logfile=\$\{shared_log_dir\}\/fap-queue-reports\.log/);
  assert.match(provision, /apt-get install -y -qq --no-install-recommends supervisor/);
  assert.match(provision, /systemctl enable --now supervisor/);
  assert.match(provision, /shared\/backend\/storage\/framework\/cache\/data/);
  assert.match(provision, /sudo -n -u www-data -- test -w "\$shared_cache_data_dir"/);
  assert.match(sharedPermissionPaths, /^backend\/storage\/framework\/cache\/data$/m);
  assert.match(provision, /where\('status', 'ready'\)/);
  assert.match(provision, /ready_since >= pending_before/);
  assert.doesNotMatch(provision, /queue:work database_reports --queue=reports --once/);

  assert.doesNotMatch(workflow, /name: Provision staging reports worker once/);
  assert.doesNotMatch(workflow, /provision_staging_reports_worker_once\.sh/);
});

test("sync skips only the default queue while reports remain fail closed", () => {
  assert.match(queueSmoke, /\$defaultSkipped = \$defaultDriver === 'sync';/);
  assert.match(queueSmoke, /'skip_reason' => \$defaultSkipped \? 'sync_connection' : null/);
  assert.doesNotMatch(queueSmoke, /non_redis_queue_driver/);
  assert.match(queueSmoke, /\$reportsConnection !== 'database_reports'/);
  assert.match(queueSmoke, /\$reportsQueue !== 'reports'/);
  assert.match(queueSmoke, /\(string\) \(\$reportsConfig\['driver'\] \?\? ''\) !== 'database'/);
  assert.match(queueSmoke, /\$reportsAfter > \$reportsMaxDepth/);
  assert.match(queueSmoke, /\$oldestReportsSeconds > \$reportsMaxOldestSeconds/);
  assert.match(queueSmoke, /\$stalePendingSnapshots > 0/);

  for (const expected of [
    "OPS_DEPLOY_QUEUE_SMOKE_WAIT_SECONDS', 10",
    "OPS_DEPLOY_REPORTS_QUEUE_MAX_DEPTH', 3",
    "OPS_DEPLOY_REPORTS_QUEUE_MAX_GROWTH', 1",
    "OPS_DEPLOY_REPORTS_QUEUE_MAX_OLDEST_SECONDS', 180",
    "OPS_DEPLOY_REPORT_SNAPSHOT_MAX_PENDING_SECONDS', 180",
  ]) {
    assert.ok(ops.includes(expected), `missing queue threshold ${expected}`);
  }
});

test("worker reload precedes queue and Big Five delivery smoke", () => {
  assert.doesNotMatch(deployer, /after\('deploy:symlink', 'healthcheck:queue-smoke'\);/);
  assert.match(deployer, /after\('queue:reload-workers', 'healthcheck:queue-smoke'\);/);
  assert.match(
    deployer,
    /after\('healthcheck:queue-smoke', 'healthcheck:staging-big-five-report-delivery'\);/,
  );
  assert.match(
    queueSmoke,
    /exec \/usr\/bin\/sudo -n -u www-data -- \/usr\/bin\/bash "\$0"/,
  );
  assert.match(
    deliverySmoke,
    /exec \/usr\/bin\/sudo -n -u www-data -- \/usr\/bin\/env/,
  );
  assert.match(deployer, /seo_council_closeout_deferred/);
  assert.match(deployer, /Defer SEO Council orchestration closeout to the owning workflow/);
});

test("remote Composer scripts have a bounded deployment timeout", () => {
  assert.match(
    deployer,
    /task\('deploy:vendors',[\s\S]+COMPOSER_PROCESS_TIMEOUT=900 \{\{bin\/composer\}\} install/,
  );
});

test("staging Big Five delivery smoke is bounded and redacts private identity", () => {
  for (const endpoint of [
    "/api/v0.3/auth/guest",
    "/api/v0.3/attempts/start",
    "/api/v0.3/scales/BIG5_OCEAN/questions",
    "/api/v0.3/attempts/submit",
    "/submission",
    "/result",
    "/report",
  ]) {
    assert.ok(deliverySmoke.includes(endpoint), `missing delivery endpoint ${endpoint}`);
  }

  assert.match(deliverySmoke, /codex_probe_/);
  assert.match(deliverySmoke, /deadline=\$\(\(SECONDS \+ 90\)\)/);
  assert.match(deliverySmoke, /snapshot_status" == ready/);
  assert.match(deliverySmoke, /where\("attempt_id", \$attemptId\)->value\("status"\)/);
  assert.doesNotMatch(deliverySmoke, /where\("org_id", 0\).*where\("attempt_id"/);
  assert.match(deliverySmoke, /public_result=200/);
  assert.match(deliverySmoke, /staging_big_five_report_smoke=timeout submission_http=/);
  assert.doesNotMatch(deliverySmoke, /echo .*fm_token/);
  assert.doesNotMatch(deliverySmoke, /printf 'attempt_id=/);
  assert.doesNotMatch(deliverySmoke, /printf 'anon_id=/);
  assert.doesNotMatch(deliverySmoke, /printf\s+['"][^'"\n]*(attempt_id|anon_id|fm_token)/);
  assert.doesNotMatch(deliverySmoke, /set -x/);
});
