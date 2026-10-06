import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';
import { afterEach, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { applyBracesDepthPatch } from '../../scripts/dependencies/apply-braces-depth-patch.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const temporary = [];
const hash = file => createHash('sha256').update(readFileSync(file)).digest('hex');
const patchFile = path.resolve(here, '../../patches/braces@3.0.3.patch');
function fixture() {
  const root = mkdtempSync(path.join(os.tmpdir(), 'braces-depth-patch-'));
  temporary.push(root);
  const location = path.join(root, 'node_modules/braces');
  cpSync(path.join(here, 'fixtures/braces-3.0.3'), location, { recursive: true });
  // Reconstruct upstream's second EOF newline without storing a whitespace violation.
  const stringify = path.join(location, 'lib/stringify.js');
  writeFileSync(stringify, readFileSync(stringify, 'utf8') + '\n');
  writeFileSync(path.join(location, 'package.json'), JSON.stringify({ name: 'braces', version: '3.0.3' }));
  const lock = { lockfileVersion: 3, packages: { 'node_modules/braces': { version: '3.0.3', dev: true } } };
  writeFileSync(path.join(root, 'package-lock.json'), JSON.stringify(lock));
  return { root, location, lock };
}
afterEach(() => { for (const root of temporary.splice(0)) rmSync(root, { recursive: true, force: true }); });

test('applies the common patch, verifies it and remains idempotent', () => {
  const { root, location } = fixture();
  assert.deepEqual(applyBracesDepthPatch(root), { instances: 1, changedFiles: 5 });
  assert.deepEqual(applyBracesDepthPatch(root), { instances: 1, changedFiles: 0 });
  assert.deepEqual(applyBracesDepthPatch(root, { checkOnly: true }), { instances: 1, changedFiles: 0 });
  const depth = createRequire(path.join(location, 'package.json'))('./lib/depth.js');
  let node = { type: 'text', value: 'x' };
  for (let i = 0; i < 128; i++) node = { type: 'custom', nodes: new Set([node]) };
  assert.doesNotThrow(() => depth.assertAstDepth({ type: 'root', nodes: [node] }));
  assert.throws(() => depth.assertAstDepth({ type: 'root', nodes: [{ type: 'custom', nodes: [node] }] }), { name: 'SyntaxError', code: 'BRACES_MAX_DEPTH_EXCEEDED' });
});

test('refuses unpatched dependencies in check-only mode', () => {
  const { root, location } = fixture();
  const before = hash(path.join(location, 'lib/parse.js'));
  assert.throws(() => applyBracesDepthPatch(root, { checkOnly: true }), /unpatched installed dependency/);
  assert.equal(hash(path.join(location, 'lib/parse.js')), before);
});

test('rejects an unknown later entity before writing the first entity', () => {
  const { root, location, lock } = fixture();
  const nested = path.join(root, 'node_modules/parent/node_modules/braces');
  cpSync(location, nested, { recursive: true });
  writeFileSync(path.join(nested, 'lib/stringify.js'), 'unknown source\n');
  lock.packages['node_modules/parent/node_modules/braces'] = { version: '3.0.3', dev: true };
  writeFileSync(path.join(root, 'package-lock.json'), JSON.stringify(lock));
  const before = hash(path.join(location, 'lib/compile.js'));
  assert.throws(() => applyBracesDepthPatch(root), /unknown installed source bytes/);
  assert.equal(hash(path.join(location, 'lib/compile.js')), before);
  assert.equal(existsSync(path.join(location, 'lib/depth.js')), false);
});

test('patches all locked nested instances', () => {
  const { root, location, lock } = fixture();
  const nested = path.join(root, 'node_modules/parent/node_modules/braces');
  cpSync(location, nested, { recursive: true });
  lock.packages['node_modules/parent/node_modules/braces'] = { version: '3.0.3', dev: true };
  writeFileSync(path.join(root, 'package-lock.json'), JSON.stringify(lock));
  assert.deepEqual(applyBracesDepthPatch(root), { instances: 2, changedFiles: 10 });
  assert.equal(hash(path.join(location, 'lib/depth.js')), hash(path.join(nested, 'lib/depth.js')));
});

test('rejects changed package identity and changed locked versions', () => {
  const { root, location, lock } = fixture();
  writeFileSync(path.join(location, 'package.json'), JSON.stringify({ name: 'braces', version: '3.0.4' }));
  assert.throws(() => applyBracesDepthPatch(root), /unsupported installed identity/);
  lock.packages['node_modules/braces'].version = '2.3.2';
  writeFileSync(path.join(root, 'package-lock.json'), JSON.stringify(lock));
  assert.throws(() => applyBracesDepthPatch(root), /unsupported locked version/);
});

test('only permits missing dev instances when dev dependencies were explicitly omitted', () => {
  const { root, location, lock } = fixture();
  rmSync(location, { recursive: true });
  assert.throws(() => applyBracesDepthPatch(root), /missing installed braces dependency/);
  assert.deepEqual(applyBracesDepthPatch(root, { omitDev: true }), { instances: 0, changedFiles: 0 });
  lock.packages['node_modules/braces'].dev = false;
  writeFileSync(path.join(root, 'package-lock.json'), JSON.stringify(lock));
  assert.throws(() => applyBracesDepthPatch(root, { omitDev: true }), /missing installed braces dependency/);
});

test('rejects an unlocked instance, including a renamed package identity', () => {
  const { root, location } = fixture();
  const extra = path.join(root, 'node_modules/renamed-pattern');
  cpSync(location, extra, { recursive: true });
  assert.throws(() => applyBracesDepthPatch(root), /unlocked braces instance/);
  assert.equal(existsSync(path.join(location, 'lib/depth.js')), false);
});

test('rejects a damaged patch without writing dependencies', () => {
  const { root, location } = fixture();
  const damaged = path.join(root, 'damaged.patch');
  writeFileSync(damaged, readFileSync(patchFile, 'utf8') + '\n');
  assert.throws(() => applyBracesDepthPatch(root, { patchFile: damaged }), /patch digest mismatch/);
  assert.equal(existsSync(path.join(location, 'lib/depth.js')), false);
});

test('keeps installation and the real ops build wired to the mandatory patch entry', () => {
  const pkg = JSON.parse(readFileSync(path.resolve(here, '../../package.json'), 'utf8'));
  assert.equal(pkg.scripts.postinstall, 'node scripts/dependencies/apply-braces-depth-patch.mjs');
  assert.equal(pkg.scripts['prebuild:ops-theme'], 'node scripts/dependencies/apply-braces-depth-patch.mjs --required');
  assert.match(pkg.scripts['build:ops-theme'], /node_modules\/tailwindcss-3\/lib\/cli\.js/);
});
