import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { classifyPaths } from './classify-paths.mjs';

const workflow = readFileSync(new URL('../workflows/deploy.yml', import.meta.url), 'utf8');
const steps = [...workflow.matchAll(/      - name: Install pinned Deployer\n        run: \|\n((?:          [^\n]*\n)+)/g)]
  .map(match => match[1].trimEnd().split('\n').map(line => line.slice(10)).join('\n'));
const digest = bytes => createHash('sha256').update(bytes).digest('hex');

function run(script, env, cwd) {
  return new Promise((resolve, reject) => {
    const child = spawn('bash', ['-e', '-c', script], { cwd, env: { ...process.env, ...env }, stdio: 'ignore' });
    child.on('error', reject);
    child.on('exit', code => resolve(code));
  });
}

test('the official offline tool retains the existing version, digest, license and deployment classification', () => {
  const version = workflow.match(/DEPLOYER_VERSION: "([^"]+)"/)?.[1];
  const expected = workflow.match(/DEPLOYER_SHA256: "([a-f0-9]{64})"/)?.[1];
  assert.equal(version, '7.5.12');
  assert.equal(expected, 'b55c6609653e888c672d327c407f8bba6324b9c9cc24f9dcfb3f4b3922760632');
  assert.equal(digest(readFileSync(new URL(`../tools/deployer-${version}.phar`, import.meta.url))), expected);
  assert.match(readFileSync(new URL('../tools/deployer-LICENSE', import.meta.url), 'utf8'), /Copyright © 2013 Anton Medvedev/);
  const classification = classifyPaths([`.github/tools/deployer-${version}.phar`]);
  assert.equal(classification.deploy, true);
  assert.ok(classification.categories.includes('infrastructure_deployment'));
});

test('both exact-SHA jobs install checked local bytes and reject missing or altered tools without a network fallback', async t => {
  assert.equal(steps.length, 2);
  assert.equal(steps[0], steps[1]);
  assert.doesNotMatch(workflow, /Retain the checked pinned Deployer|Download the staging-checked pinned Deployer|DEPLOYER_ASSET_ID/);
  const directory = mkdtempSync(join(tmpdir(), 'deployer-offline-'));
  t.after(() => rmSync(directory, { recursive: true, force: true }));
  mkdirSync(join(directory, '.github', 'tools'), { recursive: true });
  const fixture = Buffer.from('fixed deployment tool fixture\n');
  const env = { DEPLOYER_VERSION: '7.5.12', DEPLOYER_SHA256: digest(fixture) };
  const source = join(directory, '.github', 'tools', 'deployer-7.5.12.phar');
  const output = join(directory, 'installed.phar');
  for (const step of steps) {
    assert.match(step, /cp "\.github\/tools\/deployer-\$\{DEPLOYER_VERSION\}\.phar" \/tmp\/dep\.phar/);
    assert.match(step, /sha256sum -c -$/);
    assert.doesNotMatch(step, /curl|https?:|--insecure|\|\| true/);
    const script = step.replaceAll('/tmp/dep.phar', output);
    rmSync(source, { force: true });
    assert.notEqual(await run(script, env, directory), 0);
    writeFileSync(source, fixture);
    assert.equal(await run(script, env, directory), 0);
    assert.deepEqual(readFileSync(output), fixture);
    writeFileSync(source, 'altered bytes');
    assert.notEqual(await run(script, env, directory), 0);
    writeFileSync(source, fixture);
    assert.notEqual(await run(script, { ...env, DEPLOYER_SHA256: '0'.repeat(64) }, directory), 0);
  }
});
