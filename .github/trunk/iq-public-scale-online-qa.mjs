import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { readFileSync } from 'node:fs';
import { isDeepStrictEqual } from 'node:util';
import { packageSha256, packagePath } from './iq-public-scale-package.mjs';
import { articleDom, assertSsrCandidate as assertPublicSurface, renderPage } from './iq-public-article-online-qa.mjs';

export { renderPage };
const slug = 'iq-test-intelligence-quotient-assessment';
const headings = { en: 'Free IQ Test', zh: 'IQ智商免费测试' }; // Bound product UI heading; reviewed SEO comes from the immutable package.
export function ssrMainText(html) {
  try { return articleDom(html, 'entry').body; }
  catch { throw new Error('IQ_SSR_MAIN_INVALID'); }
}
export function assertSsrCandidate(html, row, meta = { robots: 'index,follow' }, environment = 'production', responseRobots = null) {
  // The illustrated caller renders why_choose.intro once; landing_copy is the
  // same transport paragraph in this exact package. Preserve repetitions inside
  // either reviewed field; remove only this known cross-field duplicate.
  const intro = row.patch.landing_copy === row.patch.why_choose.intro
    ? [row.patch.why_choose.intro] : [row.patch.landing_copy, row.patch.why_choose.intro];
  const content = [...intro,
    ...row.patch.why_choose.items.flatMap(item => [item.title, item.body]),
    ...row.patch.faq.flatMap(item => [item.q, item.a])].join('\n');
  if (!row.metadata || !headings[row.key]) throw new Error('IQ_SSR_METADATA_MISMATCH');
  try {
    assertPublicSurface(html, { identity: { locale: row.identity.locale, slug }, snapshot: {
      title: headings[row.key], content_md: content, seo_title: row.metadata.title, seo_description: row.metadata.description,
    } }, meta, environment, responseRobots, 'entry');
  } catch (error) {
    throw new Error(error.message.replace('IQ_ARTICLE_SSR_', 'IQ_SSR_'));
  }
  const dom = articleDom(html, 'entry');
  const path = `/${row.key}/tests/${slug}/take`;
  const candidates = dom.links.filter(href => { try { return new URL(href, 'https://fermatmind.com').pathname === path; } catch { return false; } });
  const valid = href => {
    try { const url = new URL(href, 'https://fermatmind.com'); return url.origin === 'https://fermatmind.com'
      && url.searchParams.get('form') === 'IQ_OWNER_ORIGINAL_30' && [...url.searchParams.keys()].length === 1
      && !url.username && !url.password && !url.hash; }
    catch { return false; }
  };
  // The reviewed body also links to the unparameterized default take route.
  // Preserve that exact reading link; it cannot replace the explicit 30Q CTA.
  const reviewedBare = JSON.stringify(row.patch).includes(`](${path})`);
  const exactReviewedBare = href => { try { const url = new URL(href, 'https://fermatmind.com'); return reviewedBare
    && url.origin === 'https://fermatmind.com' && !url.search && !url.hash && !url.username && !url.password; } catch { return false; } };
  if (!candidates.length || candidates.some(href => !valid(href) && !exactReviewedBare(href))
    || !candidates.some(href => valid(href) && dom.link_labels[href]?.some(label => label.trim()))) throw new Error('IQ_SSR_CTA_MISMATCH');
}
export function readCandidateRows(backendRoot) {
  // The PHP reader validates all exact package bytes before metadata is read.
  const code = 'require $argv[1]."/app/Services/ContentPromotion/PromotionContextFactory.php"; require $argv[1]."/app/Services/ContentPromotion/IqPublicEntryPackage.php"; echo json_encode((new App\\Services\\ContentPromotion\\IqPublicEntryPackage)->read($argv[1],$argv[2]), JSON_THROW_ON_ERROR);';
  const root = resolve(backendRoot);
  const rows = JSON.parse(execFileSync('php', ['-r', code, root, packageSha256], { encoding: 'utf8', timeout: 30000, maxBuffer: 262144, stdio: ['pipe', 'pipe', 'pipe'] }));
  return rows.map(row => ({ ...row, metadata: JSON.parse(readFileSync(resolve(root, packagePath, `IQ-01-${row.identity.locale}.json`), 'utf8')).metadata }));
}
export async function verifyOnlineCandidates(environment, backendRoot, fetcher = fetch, renderer = renderPage) {
  if (!['staging', 'production'].includes(environment)) throw new Error('IQ_ONLINE_ENVIRONMENT_INVALID');
  const api = environment === 'staging' ? 'https://staging-api.fermatmind.com' : 'https://api.fermatmind.com';
  const web = environment === 'staging' ? 'https://staging.fermatmind.com' : 'https://fermatmind.com';
  const read = async (url, type) => {
    // Match the existing bounded public scale smoke budget. A cold public read
    // must not be treated as failed at the shorter backend request budget.
    const surface = type === 'application/json' ? 'API' : 'PAGE';
    let response, bytes;
    try {
      response = await fetcher(url, { redirect: 'error', signal: AbortSignal.timeout(40000), headers: { 'Cache-Control': 'no-cache' } });
      if (response.status !== 200 || !response.headers.get('content-type')?.includes(type)) throw new Error('IQ_ONLINE_RESPONSE_INVALID');
      bytes = await response.text();
    } catch (error) {
      if (error?.message === 'IQ_ONLINE_RESPONSE_INVALID') throw error;
      const timeout = ['TimeoutError', 'AbortError'].includes(error?.name);
      throw new Error(`IQ_ONLINE_${surface}_${timeout ? 'TIMEOUT' : 'TRANSPORT_FAILED'}`);
    }
    if (bytes.length > 2000000) throw new Error('IQ_ONLINE_PAYLOAD_LIMIT');
    return { bytes, robots: response.headers.get('x-robots-tag') };
  };
  for (const row of readCandidateRows(backendRoot)) {
    const payload = JSON.parse((await read(`${api}/api/v0.3/scales/lookup?slug=${slug}&locale=${row.identity.locale}`, 'application/json')).bytes);
    if (payload.ok !== true || payload.primary_slug !== slug || payload.scale_code !== 'IQ_RAVEN' || payload.is_public !== true
      || typeof payload.is_indexable !== 'boolean') throw new Error('IQ_ONLINE_IDENTITY_MISMATCH');
    for (const leaf of ['landing_copy', 'why_choose', 'faq']) {
      if (!isDeepStrictEqual(payload.content_i18n_json?.[row.key]?.[leaf], row.patch[leaf])) throw new Error('IQ_ONLINE_BODY_MISMATCH');
    }
    const page = `${web}/${row.key}/tests/${slug}`;
    const response = await read(page, 'text/html');
    assertSsrCandidate(await renderer(page), row, { robots: payload.is_indexable ? 'index,follow' : 'noindex,nofollow' }, environment, response.robots);
  }
  return { api_readback_count: 2, ssr_readback_count: 2, seo_readback_count: 2, cta_readback_count: 2, environment };
}
