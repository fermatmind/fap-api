import assert from 'node:assert/strict';
import test from 'node:test';
import { snapshot, verify } from './career-first-publish-seo-check.mjs';

const receipt = {
  sha: 'a'.repeat(40), classification: { operations: { career_first_publish: true } },
  career_content_change: { status: 'eligible', release_mode: 'career_first_publish',
    first_published_pages: [{ slug: 'actors', locale: 'en', url_path: '/en/career/jobs/actors' }] },
};
const sitemap = (paths) => ({ ok: true, source: 'backend_sitemap_generator',
  items: paths.map((path) => ({ loc: `https://fermatmind.com${path}` })) });
const responder = (paths, seo) => async (url) => {
  const path = new URL(url);
  if (path.pathname.endsWith('/sitemap-source')) return { ok: true, json: async () => sitemap(paths) };
  const locale = path.searchParams.get('locale');
  return { ok: true, json: async () => ({ meta: {
    canonical: `/${locale === 'en' ? 'en' : 'zh'}/career/jobs/actors`, ...seo[locale],
  } }) };
};

test('checks actual noindex, sitemap and hreflang changes for only selected locale', async () => {
  const before = await snapshot(receipt, 'https://api.example.test', responder(
    ['/zh/career/jobs/actors'], {
      en: { robots: 'noindex,follow', hreflang: {} },
      'zh-CN': { robots: 'index,follow', hreflang: { 'zh-CN': '/zh/career/jobs/actors' } },
    },
  ));
  const after = await snapshot(receipt, 'https://api.example.test', responder(
    ['/zh/career/jobs/actors', '/en/career/jobs/actors'], {
      en: { robots: 'index,follow', hreflang: { en: '/en/career/jobs/actors', 'zh-CN': '/zh/career/jobs/actors' } },
      'zh-CN': { robots: 'index,follow', hreflang: { en: '/en/career/jobs/actors', 'zh-CN': '/zh/career/jobs/actors' } },
    },
  ));
  assert.deepEqual(verify(before, after), {
    status: 'pass', release_sha: receipt.sha, first_published_locale_pages: 1,
    sitemap_added: 1, seo_verified_locale_pages: 2, search_submissions: 0,
  });
  assert.throws(() => verify(before, { ...after, sitemap_paths: ['/zh/career/jobs/actors', '/en/career/jobs/stray'] }),
    /CAREER_FIRST_PUBLISH_SITEMAP_DELTA_INVALID/);
  assert.throws(() => verify(before, { ...after, seo: { ...after.seo,
    'actors|en': { ...after.seo['actors|en'], robots: 'noindex,follow' } } }),
  /CAREER_FIRST_PUBLISH_NOINDEX_DELTA_INVALID/);
});
