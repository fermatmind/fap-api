import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, realpathSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const backend = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const patchPath = path.join(backend, 'patches/braces@3.0.3.patch');
const patchHash = '276d438aeecf9b15aa52d7b3afabed2d2850d29443a76222c149fe273d2f9846';
const files = {
  'lib/compile.js': ['dc98f22eee3d511785d92a00758d5f0d48efed5f5813bdecc2de430c529b5c9f', '24e22b382578decec2e8a8d1f28d513d15fc66a03146e4063295a97b6431cc41'],
  'lib/depth.js': [null, 'c595951422c340325678768bc3f380312d366cbd2c0243a111f625c600c5be77'],
  'lib/expand.js': ['41ccc196ebfa7b7781a634e721eb744e4e7bcb54cba427a7e3d6806a1b9e58f7', '259d58eccf4c5c69a02a32fcf51e818b8c37bc07be400065fc35242a8702ea5c'],
  'lib/parse.js': ['e572166565f15fa6ad9865ae49d678218e32aabfd1b3720f6d0d43d39800d310', '71f633443d6f7db14b8bc28812436a574b761a899bd18d821f00accb41aa7c63'],
  'lib/stringify.js': ['379f22d77bfa1478341ccd49c5e4267464aabcbba03558bab332aac23fc6f23a', '1c7f946eddf99be15d4a959f4a3ab481f2608daed0ffe12be850e9142f61e7f7'],
  'lib/utils.js': ['b5a7596aa67730412b3c029ef09e84e6b67b8e445cffd35d1d295549c89066c7', '464cdfd4a27aab867fe8b69add53eee580290e7708f5838da8f3402f0e03d709'],
};
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const fail = message => { throw new Error(`BRACES_PATCH_HOLD: ${message}`); };

function patchSections(text) {
  const sections = new Map();
  for (const section of text.split(/(?=^diff --git )/m).filter(Boolean)) {
    const header = /^diff --git a\/(\S+) b\/(\S+)$/m.exec(section);
    if (!header || header[1] !== header[2] || !Object.hasOwn(files, header[1])) fail('unexpected patch target');
    if (sections.has(header[1])) fail('duplicate patch target');
    sections.set(header[1], section);
  }
  if (sections.size !== Object.keys(files).length) fail('incomplete patch');
  return sections;
}

function patchedText(original, section) {
  const input = original === '' ? [] : original.slice(0, -1).split('\n');
  const output = [];
  let cursor = 0;
  const hunks = section.split(/(?=^@@ )/m).slice(1);
  for (const hunk of hunks) {
    const lines = (hunk.endsWith('\n') ? hunk.slice(0, -1) : hunk).split('\n');
    const header = /^@@ -(\d+)(?:,\d+)? \+\d+(?:,\d+)? @@/.exec(lines.shift());
    if (!header) fail('invalid patch hunk');
    const start = Math.max(0, Number(header[1]) - 1);
    if (start < cursor) fail('overlapping patch hunk');
    output.push(...input.slice(cursor, start));
    cursor = start;
    for (const line of lines) {
      const operation = line === '' ? ' ' : line[0];
      const value = line.slice(1);
      if (operation === ' ' || operation === '-') {
        if (input[cursor++] !== value) fail('patch context mismatch');
      }
      if (operation === ' ' || operation === '+') output.push(value);
      if (![' ', '+', '-'].includes(operation)) fail('unsupported patch operation');
    }
  }
  output.push(...input.slice(cursor));
  return `${output.join('\n')}\n`;
}

function installedBraces(modules) {
  const found = new Set();
  const visited = new Set();
  const visit = directory => {
    if (!existsSync(directory)) return;
    const physical = realpathSync(directory);
    if (visited.has(physical)) return;
    visited.add(physical);
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      if (entry.name.startsWith('.')) continue;
      const location = path.join(directory, entry.name);
      if (entry.name.startsWith('@')) { visit(location); continue; }
      const manifest = path.join(location, 'package.json');
      if (existsSync(manifest) && JSON.parse(readFileSync(manifest, 'utf8')).name === 'braces') found.add(realpathSync(location));
      visit(path.join(location, 'node_modules'));
    }
  };
  visit(modules);
  return found;
}

export function applyBracesDepthPatch(root = backend, { omitDev = false, checkOnly = false, patchFile = patchPath } = {}) {
  const patch = readFileSync(patchFile, 'utf8');
  if (hash(patch) !== patchHash) fail('patch digest mismatch');
  const sections = patchSections(patch);
  const lock = JSON.parse(readFileSync(path.join(root, 'package-lock.json'), 'utf8'));
  const targets = Object.entries(lock.packages ?? {}).filter(([name]) => /(?:^|\/)node_modules\/braces$/.test(name));
  if (targets.length === 0) fail('missing locked braces dependency');
  const modules = path.join(root, 'node_modules');
  const physicalModules = existsSync(modules) ? realpathSync(modules) : modules;
  const packages = new Set();
  const changes = [];
  for (const [relative, metadata] of targets) {
    if (metadata.version !== '3.0.3') fail('unsupported locked version');
    const location = path.resolve(root, relative);
    if (!location.startsWith(`${modules}${path.sep}`)) fail('invalid lockfile path');
    if (!existsSync(location)) {
      if (omitDev && metadata.dev === true) continue;
      fail('missing installed braces dependency');
    }
    const physical = realpathSync(location);
    if (!physical.startsWith(`${physicalModules}${path.sep}`)) fail('escaping package symlink');
    if (packages.has(physical)) continue;
    packages.add(physical);
    const manifest = JSON.parse(readFileSync(path.join(physical, 'package.json'), 'utf8'));
    if (manifest.name !== 'braces' || manifest.version !== '3.0.3') fail('unsupported installed identity');
    for (const [name, [before, after]] of Object.entries(files)) {
      const file = path.join(physical, name);
      const exists = existsSync(file);
      if (exists && !realpathSync(file).startsWith(`${physical}${path.sep}`)) fail('escaping source symlink');
      const current = exists ? readFileSync(file, 'utf8') : null;
      const currentHash = current === null ? null : hash(current);
      if (currentHash === after) continue;
      if (currentHash !== before) fail('unknown installed source bytes');
      if (checkOnly) fail('unpatched installed dependency');
      const next = patchedText(current ?? '', sections.get(name));
      if (hash(next) !== after) fail('patched source digest mismatch');
      changes.push([file, next]);
    }
  }
  const actual = installedBraces(modules);
  if (actual.size !== packages.size || [...actual].some(location => !packages.has(location))) fail('unlocked braces instance');
  // Validate every entity before writing; unknown sources never produce a partial patch.
  for (const [file, content] of changes) {
    mkdirSync(path.dirname(file), { recursive: true });
    writeFileSync(file, content);
  }
  return { instances: packages.size, changedFiles: changes.length };
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const omitDev = !process.argv.includes('--required') && /(?:^|[\s,])dev(?:$|[\s,])/.test(process.env.npm_config_omit ?? '');
    const result = applyBracesDepthPatch(backend, { omitDev, checkOnly: process.argv.includes('--check') });
    console.log(`[braces-depth-patch] verified ${result.instances} instance(s); updated ${result.changedFiles} file(s)`);
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
