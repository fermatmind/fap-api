#!/usr/bin/env node

import { readFileSync, writeFileSync } from 'node:fs';

const assert = (value, code) => { if (!value) throw new Error(code); };
const canonical = (slug, locale) => `/${locale === 'zh-CN' ? 'zh' : 'en'}/career/jobs/${slug}`;
const careerPath = (value) => {
  try {
    const path = new URL(value).pathname;
    return /^\/(en|zh)\/career\/jobs\/[a-z0-9-]+$/.test(path) ? path : null;
  } catch { return null; }
};

export async function snapshot(receipt, baseUrl, fetcher = fetch) {
  const change = receipt.career_content_change;
  assert(change?.status === 'eligible' && change.release_mode === 'career_first_publish'
    && change.first_published_pages?.length > 0
    && receipt.classification?.operations?.career_first_publish === true,
  'CAREER_FIRST_PUBLISH_RECEIPT_INVALID');
  const slugs = [...new Set(change.first_published_pages.map(({ slug }) => slug))].sort();
  assert(slugs.length > 0 && slugs.length <= 1046
    && slugs.every((slug) => /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)
      && slug !== 'software-developers'), 'CAREER_FIRST_PUBLISH_SCOPE_INVALID');
  const get = async (path) => {
    const response = await fetcher(`${baseUrl}${path}`, { signal: AbortSignal.timeout(15000) });
    assert(response.ok, 'CAREER_FIRST_PUBLISH_HTTP_FAILED');
    return response.json();
  };
  const sitemap = await get('/api/v0.5/seo/sitemap-source');
  assert(sitemap.ok === true && sitemap.source === 'backend_sitemap_generator'
    && Array.isArray(sitemap.items), 'CAREER_FIRST_PUBLISH_SITEMAP_INVALID');
  const sitemapPaths = [...new Set(sitemap.items.map(({ loc }) => careerPath(loc)).filter(Boolean))].sort();
  const rows = slugs.flatMap((slug) => ['en', 'zh-CN'].map((locale) => ({ slug, locale })));
  const seo = {};
  let next = 0;
  await Promise.all(Array.from({ length: Math.min(24, rows.length) }, async () => {
    while (next < rows.length) {
      const { slug, locale } = rows[next++];
      const response = await get(`/api/v0.5/career-jobs/${slug}/seo?locale=${locale}`);
      const meta = response.meta;
      assert(meta?.canonical === canonical(slug, locale)
        && ['index,follow', 'noindex,follow'].includes(meta.robots)
        && meta.hreflang && typeof meta.hreflang === 'object',
      'CAREER_FIRST_PUBLISH_SEO_INVALID');
      seo[`${slug}|${locale}`] = {
        canonical: meta.canonical, robots: meta.robots,
        hreflang: Object.fromEntries(Object.entries(meta.hreflang).sort()),
      };
    }
  }));
  return { release_sha: receipt.sha, first_published_pages: change.first_published_pages,
    sitemap_paths: sitemapPaths, seo };
}

export function verify(before, after) {
  assert(before.release_sha === after.release_sha
    && JSON.stringify(before.first_published_pages) === JSON.stringify(after.first_published_pages),
  'CAREER_FIRST_PUBLISH_SNAPSHOT_BINDING_INVALID');
  const expected = new Set(before.first_published_pages.map(({ slug, locale }) => canonical(slug, locale)));
  const oldUrls = new Set(before.sitemap_paths);
  const newUrls = new Set(after.sitemap_paths);
  assert([...oldUrls].every((path) => newUrls.has(path))
    && [...newUrls].filter((path) => !oldUrls.has(path)).every((path) => expected.has(path))
    && [...expected].every((path) => newUrls.has(path)),
  'CAREER_FIRST_PUBLISH_SITEMAP_DELTA_INVALID');
  const first = new Set(before.first_published_pages.map(({ slug, locale }) => `${slug}|${locale}`));
  for (const [identity, prior] of Object.entries(before.seo)) {
    const next = after.seo[identity];
    assert(next?.canonical === prior.canonical, 'CAREER_FIRST_PUBLISH_CANONICAL_CHANGED');
    assert(first.has(identity)
      ? prior.robots === 'noindex,follow' && next.robots === 'index,follow'
      : next.robots === prior.robots,
    'CAREER_FIRST_PUBLISH_NOINDEX_DELTA_INVALID');
    const slug = identity.split('|')[0];
    const expectedAlternates = Object.fromEntries((next.robots === 'index,follow' ? ['en', 'zh-CN'] : [])
      .filter((locale) => after.seo[`${slug}|${locale}`]?.robots === 'index,follow')
      .map((locale) => [locale, canonical(slug, locale)]));
    assert(JSON.stringify(next.hreflang) === JSON.stringify(expectedAlternates),
      'CAREER_FIRST_PUBLISH_HREFLANG_DELTA_INVALID');
  }
  assert(Object.keys(before.seo).length === Object.keys(after.seo).length,
    'CAREER_FIRST_PUBLISH_SEO_SCOPE_INVALID');
  return { status: 'pass', release_sha: before.release_sha,
    first_published_locale_pages: first.size,
    sitemap_added: [...newUrls].filter((path) => !oldUrls.has(path)).length,
    seo_verified_locale_pages: Object.keys(after.seo).length,
    search_submissions: 0 };
}

if (process.argv[1]?.endsWith('career-first-publish-seo-check.mjs')) {
  const args = Object.fromEntries(process.argv.slice(3).map((argument) => {
    const [key, ...value] = argument.replace(/^--/, '').split('=');
    return [key, value.join('=')];
  }));
  const receipt = JSON.parse(readFileSync(args.receipt));
  const current = await snapshot(receipt, args.api);
  if (process.argv[2] === 'capture') writeFileSync(args.output, `${JSON.stringify(current)}\n`, { mode: 0o600 });
  else if (process.argv[2] === 'verify') {
    const result = verify(JSON.parse(readFileSync(args.before)), current);
    process.stdout.write(`${JSON.stringify(result)}\n`);
  } else throw new Error('CAREER_FIRST_PUBLISH_MODE_INVALID');
}
