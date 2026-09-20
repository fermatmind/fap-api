import assert from 'node:assert/strict';
import test from 'node:test';

import { analyzeCareerContentOnly, INTENT_PATH, MANIFEST_PATH } from './classify-career-content-only.mjs';
import { applyCareerContentOnly } from './classify-release.mjs';
import { classifyPaths } from './classify-paths.mjs';

const baseSha = 'a'.repeat(40);
const headSha = 'b'.repeat(40);

function fixture(count = 1) {
  const allFiles = Array.from({ length: 2092 }, (_, index) => ({
    path: `careers/career-${String(Math.floor(index / 2) + 1).padStart(4, '0')}/${index % 2 ? 'zh-CN' : 'en'}.json`,
    canonical_slug: `career-${String(Math.floor(index / 2) + 1).padStart(4, '0')}`,
    locale: index % 2 ? 'zh-CN' : 'en',
  }));
  const manifest = {
    contract_version: 'career.content_v3_current.manifest.v1',
    schema_version: 'career.content_v3_current_manifest.v1',
    authority_path: 'content_assets/career/current',
    locales: ['en', 'zh-CN'],
    identity_aliases: { old: 'career-0001' },
    identity_scopes: { 'career-0001': { scope_type: 'exact' } },
    coverage: { files: 2092, locale_pages: 2092, locales: 2, slugs: 1046, enhanced_locale_pages: count, legacy_locale_pages: 2092 - count },
    set_hashes: { slug_set_sha256: '1'.repeat(64), locale_page_set_sha256: '2'.repeat(64), source_semantic_aggregate_sha256: '3'.repeat(64) },
    files: allFiles,
  };
  const intent = {
    contract_version: 'career.current_authority_release_intent.v1', discoverability: false,
    file_count: 2092, locale_page_count: 2092, locales: ['en', 'zh-CN'],
    manual_hold_slugs: ['software-developers'], search_submission: false, slug_count: 1046,
  };
  const pages = Array.from({ length: count }, (_, index) => {
    const slug = `career-${String(index + 1).padStart(4, '0')}`;
    const locale = index % 2 ? 'zh-CN' : 'en';
    return { path: `backend/content_assets/career/current/careers/${slug}/${locale}.json`, slug, locale };
  });
  const paths = [...pages.map(({ path }) => path), MANIFEST_PATH, INTENT_PATH].sort();
  const values = new Map();
  for (const ref of [baseSha, headSha]) {
    values.set(`${ref}:${MANIFEST_PATH}`, structuredClone(manifest));
    values.set(`${ref}:${INTENT_PATH}`, structuredClone(intent));
    for (const page of pages) values.set(`${ref}:${page.path}`, {
      subject: { canonical_slug: page.slug }, locale: page.locale,
      display: { path: `/${page.locale === 'en' ? 'en' : 'zh'}/career/jobs/${page.slug}` },
      blocks: ref === headSha ? [{ changed: true }] : [],
    });
  }
  return {
    paths, pages, values,
    input: {
      baseSha, headSha, paths,
      statuses: paths.map((path) => ({ status: 'M', path })),
      readJson: (ref, path) => structuredClone(values.get(`${ref}:${path}`)),
      hashFile: (ref, path) => `${ref[0]}${path.length.toString(16).padStart(63, '0')}`.slice(0, 64),
      treeSha: () => 'c'.repeat(40),
    },
  };
}

for (const count of [1, 100, 200]) {
  test(`accepts an identity-stable ${count}-page Career content change`, () => {
    const { input } = fixture(count);
    const receipt = analyzeCareerContentOnly(input);
    assert.equal(receipt.status, 'eligible');
    assert.equal(receipt.changed_page_count, count);
    assert.match(receipt.receipt_digest, /^[a-f0-9]{64}$/);
    const classification = applyCareerContentOnly(classifyPaths(input.paths), receipt);
    assert.equal(classification.operations.career_content_only, true);
    assert.equal(classification.operations.a08_scoped_checks, false);
  });
}

test('falls back when manifest identity, page identity, or release authority changes', () => {
  for (const mutate of [
    ({ values }) => { values.get(`${headSha}:${MANIFEST_PATH}`).identity_aliases = { changed: 'career-0001' }; },
    ({ values, pages }) => { values.get(`${headSha}:${pages[0].path}`).subject.canonical_slug = 'different'; },
    ({ values, pages }) => { values.get(`${headSha}:${pages[0].path}`).display.path = null; },
    ({ values }) => { values.get(`${headSha}:${INTENT_PATH}`).discoverability = true; },
  ]) {
    const item = fixture();
    mutate(item);
    const receipt = analyzeCareerContentOnly(item.input);
    assert.equal(receipt.status, 'ineligible');
    assert.equal(applyCareerContentOnly(classifyPaths(item.paths), receipt).operations.career_content_only, false);
  }
});

test('resolves referenced display paths while retaining manifest-derived legacy paths', () => {
  const item = fixture();
  const pagePath = item.pages[0].path;
  const before = item.values.get(`${baseSha}:${pagePath}`);
  const after = item.values.get(`${headSha}:${pagePath}`);
  before.display.path = null;
  after.display.path = { $link: 'navigation-links', block: 'navigation', entry: 'navigation-1', field: 'url' };
  after.blocks = [{ id: 'navigation', items: [{ id: 'navigation-links', data: { entries: [{
    id: 'navigation-1', url: `/en/career/jobs/${item.pages[0].slug}`,
  }] } }] }];
  assert.equal(analyzeCareerContentOnly(item.input).status, 'eligible');
});

test('falls back for additions, deletions, and mixed runtime scope', () => {
  const added = fixture();
  added.input.statuses[0].status = 'A';
  assert.equal(analyzeCareerContentOnly(added.input).reason, 'NON_MODIFICATION_CHANGE');

  const mixed = fixture();
  mixed.input.paths.push('backend/app/Services/Career/Other.php');
  mixed.input.statuses.push({ status: 'M', path: 'backend/app/Services/Career/Other.php' });
  assert.equal(analyzeCareerContentOnly(mixed.input).reason, 'PATH_SCOPE_MISMATCH');
});
