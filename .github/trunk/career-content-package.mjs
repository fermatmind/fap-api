#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync, copyFileSync, mkdtempSync, readdirSync, lstatSync, rmSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';

export const PACKAGE_SCHEMA = 'fermatmind.career-content-package.v1';
const MANIFEST = 'backend/content_assets/career/current/manifest.json';
const INTENT = 'backend/content_assets/career/career_current_authority_release.v1.json';
const HEX40 = /^[0-9a-f]{40}$/;
const HEX64 = /^[0-9a-f]{64}$/;

const stable = (value) => {
  if (Array.isArray(value)) return value.map(stable);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, stable(value[key])]));
  }
  return value;
};

const json = (value) => `${JSON.stringify(stable(value))}\n`;
const hash = (bytes) => createHash('sha256').update(bytes).digest('hex');
const readJson = (path) => JSON.parse(readFileSync(path, 'utf8'));
const assert = (condition, code) => { if (!condition) throw new Error(code); };
const git = (root, args, encoding = 'utf8') => execFileSync('git', args, {
  cwd: root, encoding, maxBuffer: 256 * 1024 * 1024,
});
const safePath = (path) => typeof path === 'string'
  && !path.startsWith('/') && !path.includes('\\')
  && path.split('/').every((part) => part !== '' && part !== '.' && part !== '..');

export function preparePackage({ root, classificationPath, parityPath, output }) {
  const classification = readJson(classificationPath);
  const change = classification.career_content_change;
  const parity = readJson(parityPath);
  assert(classification.operations?.career_content_only === true
    || classification.operations?.career_first_publish === true, 'PACKAGE_CLASSIFICATION_NOT_ELIGIBLE');
  assert(change?.contract_version === 'fermatmind.career-content-only-change.v1'
    && change.status === 'eligible', 'PACKAGE_CHANGE_RECEIPT_INVALID');
  assert(HEX40.test(change.base_sha) && HEX40.test(change.head_sha)
    && HEX40.test(change.candidate_tree_sha), 'PACKAGE_GIT_BINDING_INVALID');
  assert(git(root, ['rev-parse', 'HEAD']).trim() === change.head_sha, 'PACKAGE_HEAD_MISMATCH');
  assert(git(root, ['rev-parse', 'HEAD^{tree}']).trim() === change.candidate_tree_sha, 'PACKAGE_TREE_MISMATCH');
  assert(parity.contract_version === 'career.current_authority_package_scan.v1'
    && parity.status === 'pass' && parity.release_sha === change.head_sha, 'PACKAGE_PARITY_INVALID');
  const index = parity.full_scan?.projection_index;
  assert(Array.isArray(index) && index.length === 2092
    && HEX64.test(parity.full_scan?.projection_index_sha256 ?? ''), 'PACKAGE_PROJECTION_INDEX_INVALID');
  assert(index.every((row) => safePath(row.path) && HEX64.test(row.source_sha256)
    && HEX64.test(row.projection_sha256) && HEX64.test(row.codec_sha256)), 'PACKAGE_PROJECTION_ROW_INVALID');

  const paths = [...new Set([...(change.changed_pages ?? []).map(({ path }) => path), MANIFEST, INTENT])].sort();
  assert(paths.length === (change.changed_page_count + 2)
    && paths.every(safePath), 'PACKAGE_FILE_SET_INVALID');
  const diffFields = git(root, ['diff', '--no-renames', '--name-status', '-z', change.base_sha, change.head_sha])
    .split('\0').filter(Boolean);
  assert(diffFields.length === paths.length * 2, 'PACKAGE_GIT_DIFF_INVALID');
  const diffPaths = [];
  for (let index = 0; index < diffFields.length; index += 2) {
    assert(diffFields[index] === 'M' && safePath(diffFields[index + 1]), 'PACKAGE_GIT_DIFF_INVALID');
    diffPaths.push(diffFields[index + 1]);
  }
  assert(JSON.stringify(diffPaths.sort()) === JSON.stringify(paths), 'PACKAGE_GIT_DIFF_SCOPE_MISMATCH');
  const files = paths.map((path) => {
    const before = git(root, ['show', `${change.base_sha}:${path}`], null);
    const after = readFileSync(join(root, path));
    const record = { path, before_sha256: hash(before), after_sha256: hash(after), bytes: after.length };
    const declared = change.changed_pages.find((page) => page.path === path);
    if (declared) {
      assert(declared.before_sha256 === record.before_sha256
        && declared.after_sha256 === record.after_sha256, 'PACKAGE_CHANGED_PAGE_HASH_MISMATCH');
    }
    const destination = join(output, 'payload', path);
    mkdirSync(dirname(destination), { recursive: true });
    copyFileSync(join(root, path), destination);
    return record;
  });
  mkdirSync(output, { recursive: true });
  writeFileSync(join(output, 'projection-index.json'), json({
    schema_version: PACKAGE_SCHEMA,
    count: index.length,
    sha256: parity.full_scan.projection_index_sha256,
    pages: index,
  }), { mode: 0o600 });
  const changedPages = change.changed_pages.map(({ slug, locale, after_sha256 }) => ({ slug, locale, after_sha256 }));
  const changedPagesBytes = json({
    schema_version: 'fermatmind.career-content-changed-pages.v1',
    head_sha: change.head_sha,
    changed_page_set_sha256: change.changed_page_set_sha256,
    pages: changedPages,
  });
  writeFileSync(join(output, 'changed-pages.json'), changedPagesBytes, { mode: 0o600 });
  const binding = {
    schema_version: PACKAGE_SCHEMA,
    base_sha: change.base_sha,
    head_sha: change.head_sha,
    candidate_tree_sha: change.candidate_tree_sha,
    compiler_digest: parity.package.compiler_digest,
    codec_digest: parity.package.codec_digest,
    authority_digest: parity.package.digest,
    authority_projection_digest: parity.package.projection_digest,
    ci_package_scan_receipt_digest: parity.receipt_digest,
    projection_index_sha256: parity.full_scan.projection_index_sha256,
    changed_page_set_sha256: change.changed_page_set_sha256,
    changed_pages_file_sha256: hash(changedPagesBytes),
    changed_page_count: change.changed_page_count,
    payload_file_count: files.length,
    files,
    no_deletions: true,
  };
  binding.binding_digest = hash(json(binding));
  writeFileSync(join(output, 'binding.json'), json(binding), { mode: 0o600 });
  return binding;
}

export function finalizePackage({ staging, archive, receipt }) {
  const binding = readJson(join(staging, 'binding.json'));
  const bytes = readFileSync(archive);
  const result = {
    schema_version: PACKAGE_SCHEMA,
    status: 'ready',
    base_sha: binding.base_sha,
    head_sha: binding.head_sha,
    candidate_tree_sha: binding.candidate_tree_sha,
    compiler_digest: binding.compiler_digest,
    codec_digest: binding.codec_digest,
    binding_digest: binding.binding_digest,
    projection_index_sha256: binding.projection_index_sha256,
    changed_page_set_sha256: binding.changed_page_set_sha256,
    payload_file_count: binding.payload_file_count,
    archive_sha256: hash(bytes),
    archive_bytes: bytes.length,
  };
  result.receipt_digest = hash(json(result));
  writeFileSync(receipt, json(result), { mode: 0o600 });
  return result;
}

const walk = (root, current = root) => readdirSync(current).flatMap((name) => {
  const absolute = join(current, name);
  const stat = lstatSync(absolute);
  assert(!stat.isSymbolicLink(), 'PACKAGE_SYMLINK_FORBIDDEN');
  return stat.isDirectory() ? walk(root, absolute) : [relative(root, absolute).split(sep).join('/')];
});

export function verifyPackage({ archive, receipt, expected = {} }) {
  const packageReceipt = readJson(receipt);
  const archiveBytes = readFileSync(archive);
  assert(packageReceipt.schema_version === PACKAGE_SCHEMA && packageReceipt.status === 'ready', 'PACKAGE_RECEIPT_INVALID');
  const receiptProjection = { ...packageReceipt };
  delete receiptProjection.receipt_digest;
  assert(HEX64.test(packageReceipt.receipt_digest ?? '')
    && hash(json(receiptProjection)) === packageReceipt.receipt_digest, 'PACKAGE_RECEIPT_DIGEST_MISMATCH');
  assert(hash(archiveBytes) === packageReceipt.archive_sha256, 'PACKAGE_ARCHIVE_HASH_MISMATCH');
  for (const [key, value] of Object.entries(expected)) {
    if (value !== undefined && value !== '') assert(packageReceipt[key] === value, `PACKAGE_${key.toUpperCase()}_MISMATCH`);
  }
  const temporary = mkdtempSync(join(tmpdir(), 'career-content-package-'));
  const directory = join(temporary, 'extracted');
  try {
    execFileSync('python3', [
      fileURLToPath(new URL('../../backend/scripts/deploy/extract_career_content_package.py', import.meta.url)),
      '--archive', archive, '--destination', directory,
      '--archive-sha', packageReceipt.archive_sha256,
      '--base', packageReceipt.base_sha, '--head', packageReceipt.head_sha,
      '--tree', packageReceipt.candidate_tree_sha,
    ], { stdio: 'pipe' });
    const binding = readJson(join(directory, 'binding.json'));
    const bindingProjection = { ...binding };
    delete bindingProjection.binding_digest;
    assert(hash(json(bindingProjection)) === binding.binding_digest
      && binding.binding_digest === packageReceipt.binding_digest, 'PACKAGE_BINDING_DIGEST_MISMATCH');
    assert(binding.base_sha === packageReceipt.base_sha && binding.head_sha === packageReceipt.head_sha
      && binding.candidate_tree_sha === packageReceipt.candidate_tree_sha
      && binding.compiler_digest === packageReceipt.compiler_digest
      && binding.codec_digest === packageReceipt.codec_digest, 'PACKAGE_BINDING_RECEIPT_MISMATCH');
    const index = readJson(join(directory, 'projection-index.json'));
    assert(index.schema_version === PACKAGE_SCHEMA && index.count === 2092
      && index.pages.length === 2092 && index.sha256 === binding.projection_index_sha256,
    'PACKAGE_PROJECTION_INDEX_INVALID');
    const changedPagesBytes = readFileSync(join(directory, 'changed-pages.json'));
    assert(hash(changedPagesBytes) === binding.changed_pages_file_sha256,
      'PACKAGE_CHANGED_PAGES_FILE_HASH_MISMATCH');
    const changedPages = JSON.parse(changedPagesBytes);
    assert(changedPages.schema_version === 'fermatmind.career-content-changed-pages.v1'
      && changedPages.head_sha === binding.head_sha
      && changedPages.changed_page_set_sha256 === binding.changed_page_set_sha256
      && changedPages.pages.length === binding.changed_page_count
      && JSON.stringify(changedPages.pages.map(({ slug, locale, after_sha256 }) =>
        `backend/content_assets/career/current/careers/${slug}/${locale}.json|${after_sha256}`).sort())
        === JSON.stringify(binding.files.filter(({ path }) => /\/careers\/[^/]+\/(en|zh-CN)\.json$/.test(path))
          .map(({ path, after_sha256 }) => `${path}|${after_sha256}`).sort()),
    'PACKAGE_CHANGED_PAGES_FILE_INVALID');
    const expectedFiles = ['binding.json', 'projection-index.json', 'changed-pages.json', ...binding.files.map(({ path }) => `payload/${path}`)].sort();
    assert(JSON.stringify(walk(directory).sort()) === JSON.stringify(expectedFiles), 'PACKAGE_ARCHIVE_FILE_SET_MISMATCH');
    for (const file of binding.files) {
      assert(hash(readFileSync(join(directory, 'payload', file.path))) === file.after_sha256,
        'PACKAGE_PAYLOAD_HASH_MISMATCH');
    }
    return { receipt: packageReceipt, binding };
  } finally {
    rmSync(temporary, { recursive: true, force: true });
  }
}

const args = Object.fromEntries(process.argv.slice(3).map((item) => {
  const [key, ...rest] = item.replace(/^--/, '').split('=');
  return [key, rest.join('=')];
}));
const mode = process.argv[2];
if (mode === 'prepare') {
  preparePackage({ root: resolve(args.root), classificationPath: args.classification, parityPath: args.parity, output: args.output });
} else if (mode === 'finalize') {
  finalizePackage({ staging: args.staging, archive: args.archive, receipt: args.receipt });
} else if (mode === 'verify') {
  verifyPackage({ archive: args.archive, receipt: args.receipt, expected: {
    head_sha: args.head, base_sha: args.base, candidate_tree_sha: args.tree,
    compiler_digest: args.compiler, codec_digest: args.codec,
  } });
} else if (process.argv[1]?.endsWith('career-content-package.mjs')) {
  throw new Error('PACKAGE_MODE_INVALID');
}
