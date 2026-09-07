import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
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

test("production API HTTP convergence is atomic and fail-closed", () => {
  const task = taskBlock("ensure:nginx-api-http-redirect");

  assert.match(task, /currentHost\(\)->getAlias\(\) !== 'production'/);
  assert.match(task, /site_backup="\$\(mktemp \/tmp\/fap-api-http-vhost-backup/);
  assert.match(task, /restore_api_http_vhost\(\)/);
  assert.match(task, /cp -p "\$site_backup" "\$site_path"/);
  assert.match(task, /\/usr\/sbin\/nginx -t/);
  assert.match(task, /systemctl reload nginx/);
  assert.match(task, /probe_redirect GET/);
  assert.match(task, /probe_redirect HEAD/);
  assert.match(task, /probe_redirect POST/);
  assert.match(task, /probe_redirect GET .* origin/);
  assert.match(task, /--resolve "\$\{api_host\}:80:127\.0\.0\.1"/);
  assert.match(task, /for attempt in 1 2 3 4 5/);
  assert.match(task, /tolower\(\$1\) == "location:"/);
  assert.match(task, /location_match=/);
  assert.match(task, /challenge_status/);
  assert.match(task, /expected 404/);
});

test("infrastructure releases verify Certbot renewal through the protected deploy connection", () => {
  const task = taskBlock("ensure:nginx-api-http-redirect");

  assert.match(workflow, /infrastructure: \$\{\{ steps\.receipt\.outputs\.infrastructure \}\}/);
  assert.match(workflow, /\.classification\.flags\.infrastructure_deployment/);
  assert.match(workflow, /verify_api_certbot_renewal='\$\{\{ needs\.policy\.outputs\.infrastructure \}\}'/);
  assert.match(task, /systemctl is-enabled --quiet certbot\.timer/);
  assert.match(task, /systemctl is-active --quiet certbot\.timer/);
  assert.match(task, /NextElapseUSecRealtime/);
  assert.match(task, /authenticator.*webroot/);
  assert.match(task, /renewal-hooks\/deploy/);
  assert.match(task, /certbot renew[\s\S]*--cert-name "\$api_host" --dry-run --non-interactive/);
  assert.match(task, /timeout --signal=TERM --kill-after=15s 600s sudo -n \/usr\/bin\/certbot renew/);
  assert.match(task, /--no-random-sleep-on-renew/);
  assert.match(task, /> "\$tmp_certbot" 2>&1/);
});

test("the API redirect runs before the ordinary Nginx reload", () => {
  assert.match(deployer, /after\('ensure:nginx-public-static-media-route', 'ensure:nginx-api-http-redirect'\);/);
  assert.match(deployer, /after\('ensure:nginx-api-http-redirect', 'reload:nginx'\);/);
});

test("both deploy targets use strict GitHub SSH over the reachable TLS port", () => {
  const command = "ssh -o BatchMode=yes -o IdentitiesOnly=no -o StrictHostKeyChecking=yes -o Hostname=ssh.github.com -o Port=443 -o HostKeyAlias=github.com -o ConnectTimeout=10 -o ConnectionAttempts=3";

  assert.equal(deployer.split(`->set('git_ssh_command', '${command}')`).length - 1, 2);
  assert.equal(deployer.split("->setSshArguments(['-o ServerAliveInterval=15', '-o ServerAliveCountMax=8', '-o TCPKeepAlive=yes'])").length - 1, 2);
});
