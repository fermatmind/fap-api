import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { classifyRelease } from './classify-release.mjs';

const workflow = readFileSync(new URL('../workflows/ci.yml', import.meta.url), 'utf8');
const step = workflow.slice(workflow.indexOf('      - name: Require every selected validation to pass'));
const script = step.match(/        run: \|\n([\s\S]*?)(?=      - uses:)/)[1]
  .split('\n').map((line) => line.replace(/^ {10}/, '')).join('\n');
const sha = 'a'.repeat(40), base = 'b'.repeat(40), tree = 'c'.repeat(40), digest = 'd'.repeat(64);

function fixture(required = true) {
  const root = mkdtempSync(join(tmpdir(), 'career-validation-receipt-'));
  const write = (path, value) => writeFileSync(join(root, path), JSON.stringify(value));
  mkdirSync(join(root, 'career-parity'));
  mkdirSync(join(root, 'career-content-package'));
  const change = {
    contract_version: 'fermatmind.career-content-only-change.v1', status: 'eligible',
    release_mode: 'career_first_publish', base_sha: base, head_sha: sha,
    candidate_tree_sha: tree, receipt_digest: digest, changed_page_set_sha256: digest,
    first_published_pages: [{ slug: 'career-0001', locale: 'zh-CN' }],
    changed_page_count: 1046,
    changed_pages: Array.from({ length: 1046 }, (_, index) => ({
      slug: `career-${String(index + 1).padStart(4, '0')}`, locale: 'zh-CN',
      path: `backend/content_assets/career/current/careers/career-${index + 1}/zh-CN.json`,
      before_sha256: digest, after_sha256: digest,
    })),
    invariants: { indexability_unchanged: false, manual_hold_slugs: ['software-developers'],
      discoverability: false, search_submission: false }, read_only: true,
  };
  assert.ok(Buffer.byteLength(JSON.stringify(change)) > 256 * 1024);
  write('career-content-change-receipt.json', change);
  write('trunk-path-classification.json', {
    deploy: true, tests_changed:false, flags:{content_assets:true},
    operations: { content_pack_checks:false,big5_tests:false,personality_current_authority_release:false,publisher_required: required, career_first_publish: required,
      career_content_only: false, a08_scoped_checks: false },
    scope: { validation_base_sha: base }, career_content_change: change,
  });
  write('career-parity/career-current-authority-parity.json', {
    release_sha: sha, status: required ? 'pass' : 'skipped', receipt_digest: digest,
    package: { compiler_digest: digest, codec_digest: digest },
  });
  write('career-content-package/package-receipt.json', {
    schema_version: 'fermatmind.career-content-package.v1', status: 'ready',
    head_sha: sha, base_sha: base, candidate_tree_sha: tree,
    compiler_digest: digest, codec_digest: digest, archive_sha256: digest,
    binding_digest: digest, projection_index_sha256: digest, receipt_digest: digest,
  });
  const env = { ...process.env, GITHUB_SHA: sha, GITHUB_RUN_ID: '123' };
  for (const name of ['CLASSIFY', 'HYGIENE', 'SUPPLY_CHAIN', 'CONTENT_PACK', 'VERIFY_MBTI', 'VERIFY_BIGFIVE', 'CAREER_PARITY']) env[`${name}_RESULT`] = 'success';
  env.CONTENT_PACK_RESULT='skipped';
  env.VERIFY_BIGFIVE_RESULT='skipped';
  for (const name of ['SEO_PLATFORM_11A_CLOSEOUT', 'SEO_AGENT_EVIDENCE_BOUNDARY', 'SEO_AGENT_POLICY_GATEWAY', 'SEO_COUNCIL_ORCHESTRATION', 'SEO_COMPETITIVE_EVIDENCE']) env[`${name}_RESULT`] = 'skipped';
  return { root, write, change, run: () => spawnSync('bash', ['-c', script], { cwd: root, env, encoding: 'utf8' }) };
}

test('real CI aggregation preserves all 1046 change rows beyond argv limits', () => {
  const item = fixture();
  try {
    const result = item.run();
    assert.equal(result.status, 0, result.stderr);
    const receipt = JSON.parse(readFileSync(join(item.root, 'trunk-validation.json'), 'utf8'));
    assert.equal(receipt.schema_version, 'fermatmind.trunk-validation.v1');
    assert.equal(receipt.sha, sha);
    assert.deepEqual(receipt.career_content_change, item.change);
    assert.equal(receipt.career_content_change.changed_pages.length, 1046);
  } finally { rmSync(item.root, { recursive: true, force: true }); }
});

test('non-Career aggregation retains the existing not-applicable object', () => {
  const item = fixture(false);
  try {
    const result = item.run();
    assert.equal(result.status, 0, result.stderr);
    const receipt = JSON.parse(readFileSync(join(item.root, 'trunk-validation.json'), 'utf8'));
    assert.deepEqual(receipt.career_content_change, { status: 'not_applicable' });
    assert.deepEqual(receipt.career_content_package, { status: 'not_applicable' });
  } finally { rmSync(item.root, { recursive: true, force: true }); }
});

test('wrong-SHA large Career receipts still fail before output', () => {
  const item = fixture();
  try {
    item.write('career-content-change-receipt.json', { ...item.change, head_sha: base });
    assert.notEqual(item.run().status, 0);
    assert.equal(existsSync(join(item.root, 'trunk-validation.json')), false);
  } finally { rmSync(item.root, { recursive: true, force: true }); }
});

test('CI transport correction carries the unreleased Career batch into deployment', () => {
  const result = classifyRelease({
    pushBase: base, head: sha, baseline: { sha: tree, runId: 7 }, isAncestor: () => true,
    diffPaths: (from) => from === base
      ? ['.github/workflows/ci.yml', '.github/trunk/career-validation-receipt.test.mjs']
      : ['backend/content_assets/career/current/careers/preschool-teachers/zh-CN.json',
        'backend/content_assets/career/current/manifest.json',
        'backend/content_assets/career/career_current_authority_release.v1.json'],
  });
  assert.equal(result.deploy, true);
  assert.equal(result.scope.validation_base_sha, tree);
  assert.equal(result.flags.content_assets, true);
  assert.equal(result.operations.publisher_required, true);
  assert.equal(result.operations.career_current_authority_release, true);
});
