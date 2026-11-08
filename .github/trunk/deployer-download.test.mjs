import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync, mkdirSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

const workflow = readFileSync(new URL('../workflows/deploy.yml', import.meta.url), 'utf8');
const steps = [...workflow.matchAll(/      - name: Install pinned Deployer\n        run: \|\n((?:          [^\n]*\n)+)/g)]
  .map(match => match[1].trimEnd().split('\n').map(line => line.slice(10)).join('\n'));

function run(script, env) {
  return new Promise((resolve, reject) => {
    const child = spawn('bash', ['-e', '-c', script], { env: { ...process.env, NO_PROXY: '127.0.0.1', ...env }, stdio: 'ignore' });
    child.on('error', reject);
    child.on('exit', code => resolve(code));
  });
}

test('staging download retains transient recovery, checksum and terminal-error rejection', async t => {
  assert.equal(steps.length, 2);
  assert.match(workflow, /DEPLOYER_VERSION: "7\.5\.12"/);
  assert.match(workflow, /DEPLOYER_ASSET_ID: "230685193"/);
  assert.match(workflow, /DEPLOYER_SHA256: "b55c6609653e888c672d327c407f8bba6324b9c9cc24f9dcfb3f4b3922760632"/);
  const fixture = Buffer.from('fixed deployment tool fixture\n');
  const checksum = createHash('sha256').update(fixture).digest('hex');
  const directory = mkdtempSync(join(tmpdir(), 'deployer-download-'));
  assert.doesNotMatch(directory, /[\s'"`$\\]/);
  t.after(() => rmSync(directory, { recursive: true, force: true }));
  let requests = 0, failureStatus = 504;
  const server = createServer((_request, response) => {
    requests += 1;
    if (requests === 1 && failureStatus === 302) {
      response.writeHead(302, { Location: '/redirected-asset.phar' });
      response.end();
      return;
    }
    const failed = requests === 1 && failureStatus !== 200;
    response.statusCode = failed ? failureStatus : 200;
    response.end(failed ? 'synthetic transport error' : fixture);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  t.after(() => server.close());
  const url = `http://127.0.0.1:${server.address().port}/deployer.phar`;
  for (const step of steps.slice(0, 1)) {
    assert.match(step, /curl --retry 3 --retry-delay 2 --retry-max-time 120 --connect-timeout 15 --max-time 60 -fsSL/);
    assert.match(step, /https:\/\/api\.github\.com\/repos\/deployphp\/deployer\/releases\/assets\/\$\{DEPLOYER_ASSET_ID\}/);
    assert.match(step, /-H "Accept: application\/octet-stream"/);
    assert.match(step, /-H "X-GitHub-Api-Version: 2022-11-28"/);
    assert.match(step, /\| sha256sum -c -$/);
    assert.doesNotMatch(step, /--insecure|--retry-all-errors|\|\| true/);
    const output = join(directory, 'dep.phar');
    const script = step.replace(/https:\/\/api\.github\.com\/repos\/deployphp\/deployer\/releases\/assets\/\$\{DEPLOYER_ASSET_ID\}/g, url)
      .replaceAll('/tmp/dep.phar', output);
    requests = 0; failureStatus = 504;
    assert.equal(await run(script, { DEPLOYER_SHA256: checksum }), 0);
    assert.equal(requests, 2);
    assert.deepEqual(readFileSync(output), fixture);
    requests = 0; failureStatus = 302;
    assert.equal(await run(script, { DEPLOYER_SHA256: checksum }), 0);
    assert.equal(requests, 2);
    assert.deepEqual(readFileSync(output), fixture);
    requests = 0; failureStatus = 200;
    assert.notEqual(await run(script, { DEPLOYER_SHA256: '0'.repeat(64) }), 0);
    assert.equal(requests, 1);
    requests = 0; failureStatus = 404;
    assert.notEqual(await run(script, { DEPLOYER_SHA256: checksum }), 0);
    assert.equal(requests, 1);
  }
});


test('production consumes only the same-run staging artifact and rechecks the pinned bytes without another release download', async t => {
  assert.equal(steps.length, 2);
  const name = 'pinned-deployer-${{ env.DEPLOYER_VERSION }}-${{ env.DEPLOY_SHA }}-${{ github.run_id }}-${{ github.run_attempt }}';
  assert.equal(workflow.split(`name: ${name}`).length - 1, 2);
  const upload = workflow.slice(workflow.indexOf('      - name: Retain the checked pinned Deployer for production'), workflow.indexOf('      - name: Configure staging SSH identities'));
  assert.match(upload, /uses: actions\/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a/);
  assert.match(upload, /path: \/tmp\/dep\.phar\n          if-no-files-found: error/);
  const download = workflow.slice(workflow.indexOf('      - name: Download the staging-checked pinned Deployer'), workflow.lastIndexOf('      - name: Install pinned Deployer'));
  assert.match(download, /uses: actions\/download-artifact@37930b1c2abaa49bbe596cd826c3c89aef350131/);
  assert.doesNotMatch(download, /run-id:|repository:|pattern:|github-token:/);
  assert.match(download, /path: \$\{\{ runner\.temp \}\}\/pinned-deployer/);
  assert.doesNotMatch(steps[1], /curl|https?:|\|\| true/);
  assert.match(steps[1], /sha256sum -c -$/);
  const directory = mkdtempSync(join(tmpdir(), 'deployer-transfer-'));
  t.after(() => rmSync(directory, { recursive: true, force: true }));
  const source = join(directory, 'pinned-deployer', 'dep.phar');
  mkdirSync(join(directory, 'pinned-deployer'));
  const fixture = Buffer.from('one checked staging tool\n');
  const checksum = createHash('sha256').update(fixture).digest('hex');
  const script = steps[1].replaceAll('/tmp/dep.phar', join(directory, 'installed.phar'));
  const env = { RUNNER_TEMP: directory, DEPLOYER_SHA256: checksum };
  assert.notEqual(await run(script, env), 0); // Missing artifacts cannot trigger a network fallback.
  writeFileSync(source, fixture);
  assert.equal(await run(script, env), 0);
  assert.deepEqual(readFileSync(join(directory, 'installed.phar')), fixture);
  writeFileSync(source, 'wrong artifact bytes');
  assert.notEqual(await run(script, env), 0);
});
