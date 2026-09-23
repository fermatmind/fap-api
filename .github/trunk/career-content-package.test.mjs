import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, copyFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { preparePackage, finalizePackage, verifyPackage } from './career-content-package.mjs';

const sha = (character) => character.repeat(64);
const run = (cwd, command, args, encoding = 'utf8') => {
  const result = execFileSync(command, args, {
    cwd, encoding, env: { ...process.env, COPYFILE_DISABLE: '1' },
  });
  return typeof result === 'string' ? result.trim() : result;
};

function fixture() {
  const root = mkdtempSync(join(tmpdir(), 'career-package-test-'));
  const page = 'backend/content_assets/career/current/careers/actors/en.json';
  const manifest = 'backend/content_assets/career/current/manifest.json';
  const intent = 'backend/content_assets/career/career_current_authority_release.v1.json';
  for (const path of [page, manifest, intent]) mkdirSync(join(root, path, '..'), { recursive: true });
  writeFileSync(join(root, page), '{"value":"before"}\n');
  writeFileSync(join(root, manifest), '{"value":"before-manifest"}\n');
  writeFileSync(join(root, intent), '{"value":"before-intent"}\n');
  run(root, 'git', ['init', '-q']);
  run(root, 'git', ['config', 'user.email', 'test@example.com']);
  run(root, 'git', ['config', 'user.name', 'Test']);
  run(root, 'git', ['add', '.']);
  run(root, 'git', ['commit', '-qm', 'before']);
  const base = run(root, 'git', ['rev-parse', 'HEAD']);
  writeFileSync(join(root, page), '{"value":"after"}\n');
  writeFileSync(join(root, manifest), '{"value":"after-manifest"}\n');
  writeFileSync(join(root, intent), '{"value":"after-intent"}\n');
  run(root, 'git', ['add', '.']);
  run(root, 'git', ['commit', '-qm', 'after']);
  const head = run(root, 'git', ['rev-parse', 'HEAD']);
  const tree = run(root, 'git', ['rev-parse', 'HEAD^{tree}']);
  const fileHash = (ref, path) => run(root, 'git', ['show', `${ref}:${path}`], 'buffer');
  const digest = (bytes) => createHash('sha256').update(bytes).digest('hex');
  const changed = { path: page, slug: 'actors', locale: 'en', before_sha256: digest(fileHash(base, page)), after_sha256: digest(readFileSync(join(root, page))) };
  const classification = {
    operations: { career_content_only: true },
    career_content_change: {
      contract_version: 'fermatmind.career-content-only-change.v1', status: 'eligible', base_sha: base,
      head_sha: head, candidate_tree_sha: tree, changed_page_count: 1,
      changed_page_set_sha256: digest(`${changed.slug}\t${changed.locale}\t${changed.after_sha256}\n`), changed_pages: [changed],
    },
  };
  const pages = Array.from({ length: 2092 }, (_, index) => ({
    slug: `career-${index}`, locale: index % 2 ? 'zh-CN' : 'en',
    path: `backend/content_assets/career/current/careers/career-${index}/${index % 2 ? 'zh-CN' : 'en'}.json`,
    source_sha256: sha('b'), projection_sha256: sha('c'), codec_sha256: sha('d'),
  }));
  const parity = {
    contract_version: 'career.current_authority_package_scan.v1', status: 'pass', release_sha: head,
    package: { compiler_digest: sha('e'), codec_digest: sha('f'), digest: sha('1'), projection_digest: sha('2') },
    full_scan: { projection_index: pages, projection_index_sha256: sha('3') }, receipt_digest: sha('4'),
  };
  const classificationPath = join(root, 'classification.json');
  const parityPath = join(root, 'parity.json');
  writeFileSync(classificationPath, JSON.stringify(classification));
  writeFileSync(parityPath, JSON.stringify(parity));
  return { root, base, head, tree, classificationPath, parityPath, parity };
}

test('content package preparation is deterministic and verification rejects tampering', () => {
  const value = fixture();
  const first = join(value.root, 'package-first');
  const second = join(value.root, 'package-second');
  const bindingA = preparePackage({ ...value, output: first });
  const bindingB = preparePackage({ ...value, output: second });
  assert.deepEqual(bindingA, bindingB);

  const archive = join(value.root, 'package.tar.gz');
  run(value.root, 'tar', ['-czf', archive, '-C', first, '.']);
  const receipt = join(value.root, 'package-receipt.json');
  finalizePackage({ staging: first, archive, receipt });
  verifyPackage({ archive, receipt, expected: {
    head_sha: value.head, base_sha: value.base, candidate_tree_sha: value.tree,
    compiler_digest: value.parity.package.compiler_digest, codec_digest: value.parity.package.codec_digest,
  } });
  for (const [field, value] of [
    ['head_sha', '0'.repeat(40)],
    ['base_sha', '1'.repeat(40)],
    ['candidate_tree_sha', '2'.repeat(40)],
    ['compiler_digest', '3'.repeat(64)],
    ['codec_digest', '4'.repeat(64)],
  ]) {
    assert.throws(
      () => verifyPackage({ archive, receipt, expected: { [field]: value } }),
      new RegExp(`PACKAGE_${field.toUpperCase()}_MISMATCH`),
    );
  }

  const tampered = join(value.root, 'tampered.tar.gz');
  copyFileSync(archive, tampered);
  writeFileSync(tampered, Buffer.concat([readFileSync(tampered), Buffer.from('tamper')]));
  assert.throws(() => verifyPackage({ archive: tampered, receipt }), /PACKAGE_ARCHIVE_HASH_MISMATCH/);
});
