import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { readCandidateRows } from './iq-public-article-package.mjs';
import { closeOwnedBrowser } from './eq-new-source-online-qa.mjs';

const clean = value => String(value).replace(/\s+/gu, ' ').trim();
const bodyPlain = value => clean(value).replace(/\s/gu, '');
const markdownText = text => text.replace(/^\s*(?:>\s*)+/, '').replace(/^\s*(?:#{1,6}\s+|[-*+]\s+|[0-9]+[.)]\s+)/, '')
  .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1').replace(/[*`_]/g, '');
// Validate the bound SEO CTA caller before treating attribution as equivalent.
const trackingKeys = new Set(['entry_surface','source_page_type','source_route_family','source_slug','content_id','topic_id','target_action','test_slug','target_test_slug','cta_id','landing_path','entrypoint']);
function canonicalLinkTarget(href, context = null) {
  try {
    const url = new URL(href, 'https://fermatmind.com');
    if (![...url.searchParams.keys()].some(key => trackingKeys.has(key))) {
      if (context && ['article','topic'].includes(context.surface) && url.origin === 'https://fermatmind.com'
        && /^\/(?:zh|en)\/tests\/[a-z0-9-]+(?:\/take)?$/.test(url.pathname) && url.search) return null;
      return url.href;
    }
    const route = /^\/(zh|en)\/tests\/([a-z0-9-]+)$/.exec(url.pathname);
    if (!context || !route || url.origin !== 'https://fermatmind.com' || url.username || url.password || url.hash
      || route[1] !== context.locale || !['article','topic'].includes(context.surface)) return null;
    const family = context.surface, detail = `${family}_detail`, sourcePath = `/${context.locale}/${family === 'article' ? 'articles' : 'topics'}/${context.slug}`;
    const target = route[2], cta = url.searchParams.get('cta_id');
    const expected = { entry_surface:`${detail}_seo_cta`, source_page_type:detail, source_route_family:family,
      source_slug:context.slug, landing_path:sourcePath, entrypoint:'seo_cta', test_slug:target, target_test_slug:target,
      target_action:`seo_cta_${cta}`, cta_id:cta };
    const cmsCta = `cms_content_${target.replaceAll('-', '_')}`;
    if (family === 'article' ? ![cmsCta,'start_test',...(target === 'iq-test-intelligence-quotient-assessment' ? ['primary_iq_test'] : [])].includes(cta)
      : !(cta === 'start_test' && target === 'eq-test-emotional-intelligence-assessment'
        || cta === 'continue_public_content' && target === 'iq-test-intelligence-quotient-assessment')) return null;
    const idKey = family === 'article' ? 'content_id' : 'topic_id';
    const keys = [...url.searchParams.keys()];
    if (new Set(keys).size !== keys.length || keys.some(key => !Object.hasOwn(expected, key) && key !== idKey)
      || Object.entries(expected).some(([key,value]) => url.searchParams.get(key) !== value)
      || !/^\d{1,16}$/.test(url.searchParams.get(idKey) ?? '')) return null;
    for (const key of keys) url.searchParams.delete(key);
    return url.href;
  } catch { return null; }
}
export function articleDom(html, surface = 'article') {
  if (!['article', 'topic', 'topic_faq', 'entry'].includes(surface)) throw new Error('IQ_ARTICLE_SSR_DOM_INVALID');
  const code = String.raw`
$doc = new DOMDocument;
libxml_use_internal_errors(true);
if (!$doc->loadHTML(stream_get_contents(STDIN), LIBXML_NONET)) throw new RuntimeException('invalid html');
$xp = new DOMXPath($doc);
$bodyQuery = match ($argv[1]) { "topic" => '//*[@id="overview"]', "topic_faq" => '//*[@id="faq"]', "entry" => '//main[@data-test-landing-read-source]', default => '//article[@data-testid="article-detail-content"]' };
$body = $xp->query($bodyQuery);
if ($body->length !== 1) throw new RuntimeException('body missing');
$visible = function($node) {
    for ($current = $node; $current !== null; $current = $current->parentNode) {
        if (!($current instanceof DOMElement)) continue;
        $style = preg_replace('/\s+/', '', strtolower($current->getAttribute('style')));
        if (in_array(strtolower($current->tagName), ['script', 'style', 'template', 'noscript'])
            || $current->hasAttribute('hidden') || $current->getAttribute('data-iq-qa-hidden') === 'true' || preg_match('/(?:^|\s)hidden(?:\s|$)/', $current->getAttribute('class'))
            || strtolower($current->getAttribute('aria-hidden')) === 'true'
            || str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden')) return false;
    }
    return true;
};
$walk = function($node) use (&$walk, $visible) {
    if (!$visible($node)) return '';
    if ($node instanceof DOMText) return $node->nodeValue;
    $text = ''; foreach ($node->childNodes as $child) $text .= ' '.$walk($child); return $text;
};
$nodes = function($query, $attribute = null) use ($xp, $walk, $visible) {
    $values = []; foreach ($xp->query($query) as $node) {
        if ($attribute === null || $attribute !== 'href' || strtolower($node->tagName) !== 'a' || $visible($node))
            $values[] = $attribute === null ? $walk($node) : $node->getAttribute($attribute);
    } return $values;
};
$rawText = function($query) use ($xp) {
    $values = []; foreach ($xp->query($query) as $node) $values[] = $node->textContent; return $values;
};
$linkLabels = []; foreach ($xp->query($bodyQuery.'//a') as $anchor) {
    if ($visible($anchor)) $linkLabels[$anchor->getAttribute('href')][] = $walk($anchor);
}
echo json_encode([
    'link_labels' => $linkLabels,
    'body' => $walk($body->item(0)), 'h1' => $nodes('//h1'), 'title' => $rawText('//title'),
    'description' => $nodes('//meta[@name="description"]', 'content'),
    'canonical' => $nodes('//link[@rel="canonical"]', 'href'),
    'robots' => $nodes('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]', 'content'),
    'crawler_robots' => $nodes('//meta[contains(translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "bot") or contains(translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "spider") or translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="slurp"]', 'content'),
    'links' => $nodes($bodyQuery.'//a', 'href'),
    'faq_questions' => $nodes($bodyQuery.'//dt'), 'faq_answers' => $nodes($bodyQuery.'//dd'),
], JSON_THROW_ON_ERROR);`;

  try { return JSON.parse(execFileSync('php', ['-r', code, surface], { input: html, encoding: 'utf8', timeout: 15000, maxBuffer: 2000000, stdio: ['pipe', 'pipe', 'pipe'] })); }
  catch { throw new Error('IQ_ARTICLE_SSR_DOM_INVALID'); }
}
export function assertSsrCandidate(html, row, meta, environment = 'production', responseRobots = null, surface = 'article') {
  if (!['article', 'topic', 'entry'].includes(surface)) throw new Error('IQ_ARTICLE_SSR_DOM_INVALID');
  const dom = articleDom(html, surface);
  const linkContext = {surface, locale:row.identity.locale === 'en' ? 'en' : 'zh', slug:row.identity.slug};
  const linkTarget = href => canonicalLinkTarget(href, linkContext);
  if (dom.links.some(href => !linkTarget(href))) throw new Error('IQ_ARTICLE_SSR_LINK_MISMATCH');
  const visible = bodyPlain(dom.body);
  let cursor = 0;
  const renderedLabels = new Map();
  for (const [href, labels] of Object.entries(dom.link_labels)) {
    const target = linkTarget(href); if (!target) continue;
    const current = renderedLabels.get(target) ?? []; renderedLabels.set(target, [...new Set([...current, ...labels.map(clean)])]);
  }
  for (const original of row.snapshot.content_md.split('\n')) {
    if (!original.trim() || /^[\s|:-]+$/.test(original)) continue;
    let variants = [markdownText(original)];
    // Registered internal links can have context-specific UI labels. Require
    // all reviewed non-link text and one actual visible anchor representation.
    for (const [href, labels] of [...renderedLabels].sort(([a], [b]) => b.length - a.length)) {
      const normalized = linkTarget(href);
      const projected = normalized?.startsWith('https://fermatmind.com/') ? normalized.slice('https://fermatmind.com'.length) : href;
      const representations = [...new Set([href, projected])].filter(value => /^\/(?:zh|en)\//.test(value));
      if (representations.some(value => variants.some(line => line.includes(value)))) {
        variants = variants.flatMap(line => [...new Set(labels.map(clean))].map(label => {
          let candidate = line;
          for (const value of representations.sort((a,b)=>b.length-a.length)) candidate = candidate.replaceAll(value, label);
          return candidate;
        }));
        if (variants.length > 128) throw new Error('IQ_ARTICLE_SSR_LINK_VARIANT_LIMIT');
      }
    }
    const ends = variants.map(line => {
      const clauses = /^\s*>/.test(original) ? line.split(/\s*[;；•]\s*/g) : [line];
      let next = cursor;
      for (const cell of clauses.flatMap(clause => clause.includes('|') ? clause.split('|') : [clause])) {
        const text = bodyPlain(cell); if (!text) continue;
        const found = visible.indexOf(text, next); if (found < 0) return null;
        next = found + text.length;
      }
      return next;
    }).filter(end => end !== null);
    if (!ends.length) throw new Error('IQ_ARTICLE_SSR_BODY_MISMATCH');
    cursor = Math.min(...ends);
  }

  if (dom.h1.length !== 1 || bodyPlain(dom.h1[0]) !== bodyPlain(row.snapshot.title)
    || dom.title.length !== 1 || !clean(dom.title[0]).startsWith(row.snapshot.seo_title)
    || dom.description.length !== 1 || dom.description[0] !== row.snapshot.seo_description) throw new Error('IQ_ARTICLE_SSR_METADATA_MISMATCH');
  const path = `/${row.identity.locale === 'en' ? 'en' : 'zh'}/${surface === 'topic' ? 'topics' : surface === 'entry' ? 'tests' : 'articles'}/${row.identity.slug}`;
  if (dom.canonical.length !== 1 || !validCanonical(dom.canonical[0], path, environment)) throw new Error('IQ_ARTICLE_SSR_CANONICAL_MISMATCH');
  const tokens = value => [...new Set(String(value).toLowerCase()
    .replace(/(?:^|,)\s*[a-z0-9_-]*(?:bot|spider)[a-z0-9_-]*:\s*/g, ',')
    .split(/[,\s]+/).filter(Boolean).flatMap(token => token === 'none' ? ['noindex', 'nofollow'] : token === 'all' ? ['index', 'follow'] : [token]))];
  const directives = value => {
    const result = tokens(value);
    if (!result.includes('noindex') && !result.includes('index')) result.push('index');
    if (!result.includes('nofollow') && !result.includes('follow')) result.push('follow');
    return result.sort().join(',');
  };
  // The deployed frontend adds its fixed non-production/uncacheable policy.
  // Production index/follow authority remains the public API's policy.
  const projectedPolicy = environment === 'staging' ? 'noindex,nofollow,noarchive,nocache'
    : tokens(meta.robots).includes('noindex') ? 'noindex,nofollow,noarchive,nocache' : meta.robots;
  if (dom.robots.length !== 1 || directives(dom.robots[0]) !== directives(projectedPolicy)) throw new Error('IQ_ARTICLE_SSR_ROBOTS_MISMATCH');
  const expected = tokens(projectedPolicy);
  for (const source of [...dom.crawler_robots, ...(responseRobots === null ? [] : [responseRobots])]) {
    // Crawler-specific/HTTP restrictions accumulate with the generic meta.
    // Permissive defaults cannot undo an existing noindex or nofollow.
    if (tokens(source).some(token => !['index', 'follow'].includes(token) && !expected.includes(token)))
      throw new Error('IQ_ARTICLE_SSR_ROBOTS_MISMATCH');
  }
  const links = [...row.snapshot.content_md.matchAll(/\[[^\]]+\]\(([^)]+)\)/g)].map(match => match[1]);
  links.push(...[...row.snapshot.content_md.matchAll(/\/(?:zh|en)\/[a-z0-9][a-z0-9/-]*/g)].map(match => match[0]));
  if (links.some(href => !linkTarget(href) || !dom.links.some(actual => linkTarget(actual) === linkTarget(href)))) throw new Error('IQ_ARTICLE_SSR_LINK_MISMATCH');
}
function validCanonical(value, path, environment) {
  try { const url = new URL(value); return url.protocol === 'https:' && (environment === 'staging' ? ['fermatmind.com', 'www.fermatmind.com', 'staging.fermatmind.com'] : ['fermatmind.com', 'www.fermatmind.com']).includes(url.hostname) && url.pathname === path && !url.port && !url.search && !url.hash && !url.username && !url.password; }
  catch { return false; }
}
function hosts(environment) {
  if (!['staging', 'production'].includes(environment)) throw new Error('IQ_ARTICLE_ONLINE_ENVIRONMENT_INVALID');
  return environment === 'staging' ? ['https://staging-api.fermatmind.com', 'https://staging.fermatmind.com'] : ['https://api.fermatmind.com', 'https://fermatmind.com'];
}
async function read(url, type, fetcher, allowAbsent = false, captureHeaders = null) {
  let response;
  try { response = await fetcher(url, { redirect: 'error', signal: AbortSignal.timeout(15000), headers: { 'Cache-Control': 'no-cache' } }); }
  catch (error) { throw new Error(['TimeoutError', 'AbortError'].includes(error?.name) ? 'IQ_ARTICLE_ONLINE_TIMEOUT' : 'IQ_ARTICLE_ONLINE_TRANSPORT_FAILED'); }
  if (allowAbsent && response.status === 404) return null;
  if (response.status !== 200 || !response.headers.get('content-type')?.includes(type)) throw new Error('IQ_ARTICLE_ONLINE_RESPONSE_INVALID');
  if (captureHeaders) captureHeaders(response.headers);
  let bytes;
  try { bytes = await response.text(); }
  catch (error) { throw new Error(['TimeoutError', 'AbortError'].includes(error?.name) ? 'IQ_ARTICLE_ONLINE_TIMEOUT' : 'IQ_ARTICLE_ONLINE_TRANSPORT_FAILED'); }
  if (bytes.length > 2000000) throw new Error('IQ_ARTICLE_ONLINE_PAYLOAD_LIMIT');
  return bytes;
}
export async function readOnlinePrestate(environment, backendRoot, fetcher = fetch) {
  const [api] = hosts(environment); const result = {};
  for (const row of readCandidateRows(backendRoot)) {
    const bytes = await read(`${api}/api/v0.5/articles/${row.identity.slug}?locale=${row.identity.locale}&org_id=0`, 'application/json', fetcher, row.operation === 'new_source_pair');
    const article = bytes === null ? null : JSON.parse(bytes).article;
    if (article && (article.slug !== row.identity.slug || article.locale !== row.identity.locale || article.org_id !== 0 || article.is_public !== true || article.status !== 'published')) throw new Error('IQ_ARTICLE_ONLINE_PRESTATE_INVALID');
    if (!article && row.operation !== 'new_source_pair') throw new Error('IQ_ARTICLE_ONLINE_PRESTATE_INVALID');
    result[`${row.identity.locale}:${row.identity.slug}`] = article ? { is_indexable: article.is_indexable, sitemap_eligible: article.sitemap_eligible, llms_eligible: article.llms_eligible } : { is_indexable: false, sitemap_eligible: false, llms_eligible: false };
  }
  return result;
}
export async function renderPage(url, { surface = 'article', verifyDOM = null } = {}) {
  const selectors = { article: ['article[data-testid="article-detail-content"]'], entry: ['main[data-test-landing-read-source]'], topic: ['#overview', '#faq'] }[surface];
  if (!Array.isArray(selectors) || (verifyDOM !== null && typeof verifyDOM !== 'function')) throw new Error('IQ_ARTICLE_ONLINE_RENDER_OPTIONS_INVALID');
  // Read the hydrated DOM and computed visibility in the same real Chrome page.
  // CDP uses this child process's private pipes; no public debugging port exists.
  const profile = mkdtempSync(join(tmpdir(), 'iq-article-online-qa-'));
  const chrome = process.env.CHROME_BIN || (process.platform === 'darwin'
    ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : 'google-chrome');
  const child = spawn(chrome, ['--headless=new', '--disable-gpu', '--disable-dev-shm-usage',
    '--no-first-run', `--user-data-dir=${profile}`, '--remote-debugging-pipe'],
  { stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe'] });
  const pending = new Map(); let id = 0; let buffer = '';
  const fail = () => { for (const request of pending.values()) request.reject(new Error('render failed')); pending.clear(); };
  child.on('error', fail); child.on('exit', fail);
  child.stdio[3].on('error', fail);
  child.stdio[4].setEncoding('utf8');
  child.stdio[4].on('data', chunk => {
    buffer += chunk;
    if (buffer.length > 4000000) { fail(); child.kill(); return; }
    let end;
    while ((end = buffer.indexOf('\0')) >= 0) {
      const raw = buffer.slice(0, end); buffer = buffer.slice(end + 1);
      try {
        const message = JSON.parse(raw); const request = pending.get(message.id);
        if (!request) continue;
        pending.delete(message.id);
        if (message.error) request.reject(new Error('CDP failed')); else request.resolve(message.result);
      } catch { fail(); }
    }
  });
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const requestId = ++id; pending.set(requestId, { resolve, reject });
    child.stdio[3].write(JSON.stringify({ id: requestId, method, params, ...(sessionId ? { sessionId } : {}) }) + '\0');
  });
  let timer;
  try {
    return await Promise.race([
      (async () => {
        const target = await send('Target.createTarget', { url: 'about:blank' });
        const { sessionId } = await send('Target.attachToTarget', { targetId: target.targetId, flatten: true });
        await send('Page.enable', {}, sessionId);
        const navigation = await send('Page.navigate', { url }, sessionId);
        if (navigation.errorText) throw new Error('navigation failed');
        const expression = `(async () => {
          if (document.readyState !== 'complete') await new Promise(resolve => window.addEventListener('load', resolve, {once:true}));
          const visible = element => {
            if (!element.getClientRects().length) return false;
            for (let node = element; node; node = node.parentElement) {
              const style = getComputedStyle(node);
              if (style.display === 'none' || ['hidden','collapse'].includes(style.visibility)
                || style.contentVisibility === 'hidden' || Number(style.opacity) === 0) return false;
            }
            return true;
          };
          const ready = () => location.href === ${JSON.stringify(url)} && ${JSON.stringify(selectors)}.every(selector =>
            [...document.querySelectorAll(selector)].filter(visible).length === 1);
          await document.fonts.ready;
          for (const element of document.querySelectorAll('html, body, body *')) {
            const style = getComputedStyle(element);
            if (style.display === 'none' || ['hidden','collapse'].includes(style.visibility)
              || style.contentVisibility === 'hidden' || Number(style.opacity) === 0)
              element.setAttribute('data-iq-qa-hidden', 'true');
            else element.removeAttribute('data-iq-qa-hidden');
          }
          return { html: document.documentElement.outerHTML, ready: ready() };
        })()`;
        let deadline = null;
        while (true) {
          const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, sessionId);
          const captured = result.result?.value;
          if (result.exceptionDetails || typeof captured?.html !== 'string' || captured.html.length > 2000000 || typeof captured.ready !== 'boolean') throw new Error('DOM failed');
          deadline ??= Date.now() + 12000;
          if (captured.ready) {
            try { await verifyDOM?.(captured.html); return captured.html; } catch {}
          }
          if (Date.now() >= deadline) {
            if (!captured.ready) throw new Error('published surface not ready');
            // The caller repeats its original strict assertion on expiry, retaining
            // the specific mismatch. Waiting never turns invalid copy into a pass.
            return captured.html;
          }
          await new Promise(resolve => setTimeout(resolve, 100));
        }
      })(),
      new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('render timeout')), 45000); }),
    ]);
  } catch {
    throw new Error('IQ_ARTICLE_ONLINE_RENDER_FAILED');
  } finally {
    clearTimeout(timer);
    try {
      // Browser.close lets the browser and its workers release the profile.
      // Reuse the same bounded lifecycle already used by EQ acceptance.
      await closeOwnedBrowser(child, () => send('Browser.close'), profile);
    } finally { fail(); }
  }
}

export async function verifyOnlineCandidates(environment, backendRoot, fetcher = fetch, renderer = renderPage, prestate) {
  const [api, web] = hosts(environment);
  if (!prestate) throw new Error('IQ_ARTICLE_ONLINE_PRESTATE_REQUIRED');
  for (const row of readCandidateRows(backendRoot)) {
    const payload = JSON.parse(await read(`${api}/api/v0.5/articles/${row.identity.slug}?locale=${row.identity.locale}&org_id=0`, 'application/json', fetcher));
    const article = payload.article;
    if (payload.ok !== true || !article || article.slug !== row.identity.slug || article.locale !== row.identity.locale || article.org_id !== 0
      || article.is_public !== true || article.status !== 'published' || !Number.isSafeInteger(article.published_revision_id) || article.published_revision_id <= 0) throw new Error('IQ_ARTICLE_ONLINE_IDENTITY_MISMATCH');
    if (['title', 'excerpt', 'content_md'].some(key => article[key] !== row.snapshot[key])) throw new Error('IQ_ARTICLE_ONLINE_BODY_MISMATCH');
    const faqs = payload.answer_surface_v1?.faq_blocks;
    if (!Array.isArray(faqs) || JSON.stringify(faqs.map(item => ({ question: item.question, answer: item.answer }))) !== JSON.stringify(row.snapshot.faq_items)) throw new Error('IQ_ARTICLE_ONLINE_FAQ_MISMATCH');
    const qualifications = prestate[`${row.identity.locale}:${row.identity.slug}`];
    if (!qualifications || Object.entries(qualifications).some(([key, value]) => typeof value !== 'boolean' || article[key] !== value)) throw new Error('IQ_ARTICLE_ONLINE_QUALIFICATION_MISMATCH');
    const path = `/${row.identity.locale === 'en' ? 'en' : 'zh'}/articles/${row.identity.slug}`;
    const seo = JSON.parse(await read(`${api}/api/v0.5/articles/${row.identity.slug}/seo?locale=${row.identity.locale}&org_id=0`, 'application/json', fetcher));
    if (seo.meta?.title !== row.snapshot.seo_title || seo.meta?.description !== row.snapshot.seo_description
      || seo.meta?.og?.title !== row.snapshot.seo_title || seo.meta?.og?.description !== row.snapshot.seo_description || !validCanonical(seo.meta?.canonical, path, environment)
      || seo.meta?.article_authority_v1?.published_revision_backed !== true
      || (!article.is_indexable && !String(seo.meta?.robots).split(/[,\s]+/).includes('noindex'))) throw new Error('IQ_ARTICLE_ONLINE_SEO_MISMATCH');
    let responseRobots = null;
    await read(`${web}${path}`, 'text/html', fetcher, false, headers => { responseRobots = headers.get('x-robots-tag'); });
    const verifyDOM = html => assertSsrCandidate(html, row, seo.meta, environment, responseRobots);
    verifyDOM(await renderer(`${web}${path}`, { verifyDOM }));
  }
  return { api_readback_count: 10, seo_readback_count: 10, ssr_readback_count: 10, environment };
}
