import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { mkdtempSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:http';
import { readCandidateRows } from './iq-public-article-package.mjs';
import { assertSsrCandidate, articleDom, readOnlinePrestate, verifyOnlineCandidates, renderPage } from './iq-public-article-online-qa.mjs';

const row = { identity: { locale: 'en', slug: 'iq-test-tool-guide' }, snapshot: {
  title: 'IQ practice guide', seo_title: 'IQ Practice and Limits', seo_description: 'Use reasoning practice with clear limits.',
  content_md: '## Practice safely\n\nKeep the task limits visible.\n\n> First clause; second clause.\n\n| Task | Meaning |\n| --- | --- |\n| Matrix | Practice only |\n\nUse /en/tests/iq-test-intelligence-quotient-assessment.\n\n[Source](https://example.invalid/source)\n\n## FAQ\n\n### Can this diagnose?\n\nNo diagnosis is provided.' } };
const html = () => '<html><head><meta charset="utf-8"><title>IQ Practice and Limits | FermatMind</title><meta name="description" content="Use reasoning practice with clear limits."><meta name="robots" content="noindex, nofollow, noarchive, nocache"><link rel="canonical" href="https://fermatmind.com/en/articles/iq-test-tool-guide"></head><body><h1>IQ practice guide</h1><article data-testid="article-detail-content"><h2>Practice safely</h2><p>Keep the task limits visible.</p><blockquote><p>First clause</p><p>second clause.</p></blockquote><table><tr><td>Task</td><td>Meaning</td></tr><tr><td>Matrix</td><td>Practice only</td></tr></table><p>Use <a href="/en/tests/iq-test-intelligence-quotient-assessment">Start IQ practice</a>.</p><a href="https://example.invalid/source">Source</a><h2>FAQ</h2><h3>Can this diagnose?</h3><p>No diagnosis is provided.</p></article></body></html>';
const meta = { robots: 'noindex,follow' };
test('actual DOM parser preserves tables, FAQ and existing blockquote and internal-link representations', () => {
  assertSsrCandidate(html(), row, meta);
  assert.match(articleDom(html()).body, /No diagnosis is provided/);
});
test('hidden copy and scripts cannot satisfy a missing visible body or final FAQ', () => {
  for (const hidden of ['<span hidden>No diagnosis is provided.</span>', '<span style="display:none">No diagnosis is provided.</span>', '<script>No diagnosis is provided.</script>']) {
    assert.throws(() => assertSsrCandidate(html().replace('<p>No diagnosis is provided.</p>', hidden), row, meta), /IQ_ARTICLE_SSR_BODY_MISMATCH/);
  }
});
test('stale metadata, missing canonical, wrong robots and wrong title fail', () => {
  assert.throws(() => assertSsrCandidate(html().replace('name="description" content="Use reasoning practice with clear limits."', 'name="description" content="Old description"'), row, meta), /IQ_ARTICLE_SSR_METADATA_MISMATCH/);
  assert.throws(() => assertSsrCandidate(html().replace('/en/articles/iq-test-tool-guide', '/en/articles/unrelated'), row, meta), /IQ_ARTICLE_SSR_CANONICAL_MISMATCH/);
  assert.throws(() => assertSsrCandidate(html().replace('content="noindex, nofollow, noarchive, nocache"', 'content="index, follow"'), row, meta), /IQ_ARTICLE_SSR_ROBOTS_MISMATCH/);
  assert.throws(() => assertSsrCandidate(html().replace('<h1>IQ practice guide</h1>', '<h1>Other title</h1>'), row, meta), /IQ_ARTICLE_SSR_METADATA_MISMATCH/);
});
test('a link label cannot substitute for a missing reviewed destination', () => {
  assert.throws(() => assertSsrCandidate(html().replace('href="https://example.invalid/source"', 'href="https://example.invalid/other"'), row, meta), /IQ_ARTICLE_SSR_LINK_MISMATCH/);
});

const backend = fileURLToPath(new URL('../../backend/', import.meta.url));
const rows = readCandidateRows(backend);
test('prestate transport and timeout failures stay failed without exposing raw diagnostics or retrying publication', async () => {
  for (const [failure, code] of [[new Error('private transport response'), 'IQ_ARTICLE_ONLINE_TRANSPORT_FAILED'],
    [new DOMException('private timeout response', 'TimeoutError'), 'IQ_ARTICLE_ONLINE_TIMEOUT']]) {
    let attempts = 0;
    await assert.rejects(readOnlinePrestate('production', backend, async (_url, options) => {
      attempts++; assert.equal(options.redirect, 'error'); assert.ok(options.signal); throw failure;
    }), error => error.message === code);
    assert.equal(attempts, 1);
  }
});
const escapeHtml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
function renderedFixture(candidate) {
  const path = `/${candidate.identity.locale === 'en' ? 'en' : 'zh'}/articles/${candidate.identity.slug}`;
  const body = candidate.snapshot.content_md.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1').replace(/[*`_]/g, '').replace(/^\s*(?:>\s*)+/gm, '').replace(/^\s*(?:#{1,6}\s+|[-+]\s+|[0-9]+[.)]\s+)/gm, '');
  const links = [...candidate.snapshot.content_md.matchAll(/\[[^\]]+\]\(([^)]+)\)/g)].map(match => match[1]);
  links.push(...[...candidate.snapshot.content_md.matchAll(/\/(?:zh|en)\/[a-z0-9][a-z0-9/-]*/g)].map(match => match[0]));
  return `<html><head><meta charset="utf-8"><title>${escapeHtml(candidate.snapshot.seo_title)}</title><meta name="description" content="${escapeHtml(candidate.snapshot.seo_description)}"><meta name="robots" content="noindex,nofollow,noarchive,nocache"><link rel="canonical" href="https://fermatmind.com${path}"></head><body><h1>${escapeHtml(candidate.snapshot.title)}</h1><article data-testid="article-detail-content">${escapeHtml(body)}${links.map(href => `<a href="${escapeHtml(href)}">${escapeHtml(href)}</a>`).join('')}</article></body></html>`;
}
function onlineFetcher(mutate = () => {}, before = false) {
  return async (value, init) => {
    assert.equal(init.redirect, 'error');
    const url = new URL(value);
    const candidate = rows.find(item => url.pathname.includes(item.identity.slug) && url.searchParams.get('locale') === item.identity.locale);
    if (url.hostname === 'fermatmind.com') return new Response('<html></html>', { headers: { 'content-type': 'text/html' } });
    assert.ok(candidate);
    if (before && candidate.operation === 'new_source_pair') return new Response('', { status: 404 });
    let payload;
    if (url.pathname.endsWith('/seo')) {
      payload = { meta: { title: candidate.snapshot.seo_title, description: candidate.snapshot.seo_description,
        og: { title: candidate.snapshot.seo_title, description: candidate.snapshot.seo_description }, robots: 'noindex,follow',
        canonical: `https://fermatmind.com/${candidate.identity.locale === 'en' ? 'en' : 'zh'}/articles/${candidate.identity.slug}`,
        article_authority_v1: { published_revision_backed: true } } };
    } else {
      payload = { ok: true, article: { ...candidate.identity, title: candidate.snapshot.title, excerpt: candidate.snapshot.excerpt, content_md: candidate.snapshot.content_md,
        is_public: true, status: 'published', published_revision_id: 100, is_indexable: false, sitemap_eligible: false, llms_eligible: false },
        answer_surface_v1: { faq_blocks: candidate.snapshot.faq_items } };
    }
    mutate(payload, candidate, url);
    return new Response(JSON.stringify(payload), { headers: { 'content-type': 'application/json' } });
  };
}
test('online acceptance traverses all ten exact API, SEO and rendered candidates after fixed prestate', async () => {
  const prestate = await readOnlinePrestate('production', backend, onlineFetcher(undefined, true));
  let renderCount = 0;
  const result = await verifyOnlineCandidates('production', backend, onlineFetcher(), value => {
    renderCount++;
    const url = new URL(value); const locale = url.pathname.startsWith('/en/') ? 'en' : 'zh-CN';
    return renderedFixture(rows.find(item => item.identity.locale === locale && url.pathname.endsWith(item.identity.slug)));
  }, prestate);
  assert.deepEqual(result, { api_readback_count: 10, seo_readback_count: 10, ssr_readback_count: 10, environment: 'production' });
  assert.equal(renderCount, 10);
});
test('the last locale cannot pass with truncated FAQ, authority drift or changed qualifications', async () => {
  const prestate = await readOnlinePrestate('production', backend, onlineFetcher(undefined, true));
  const renderer = value => {
    const url = new URL(value); return renderedFixture(rows.find(item => item.identity.locale === (url.pathname.startsWith('/en/') ? 'en' : 'zh-CN') && url.pathname.endsWith(item.identity.slug)));
  };
  for (const field of ['faq', 'qualification', 'authority']) {
    await assert.rejects(verifyOnlineCandidates('production', backend, onlineFetcher((payload, candidate) => {
      if (candidate.page_id !== 'IQ-06' || candidate.identity.locale !== 'en') return;
      if (field === 'faq' && payload.article) payload.answer_surface_v1.faq_blocks = payload.answer_surface_v1.faq_blocks.slice(0, 6);
      if (field === 'qualification' && payload.article) payload.article.is_indexable = true;
      if (field === 'authority' && payload.meta) payload.meta.article_authority_v1.published_revision_backed = false;
    }), renderer, prestate), /IQ_ARTICLE_ONLINE_(?:FAQ|QUALIFICATION|SEO)_MISMATCH/);
  }
});

test('production rejects a staging canonical in the actual DOM acceptance', () => {
  assert.throws(() => assertSsrCandidate(html().replace('https://fermatmind.com/en/articles/', 'https://staging.fermatmind.com/en/articles/'), row, meta, 'production'), /IQ_ARTICLE_SSR_CANONICAL_MISMATCH/);
});

test('hidden ancestor wrappers cannot satisfy body or visible links', () => {
  for (const attribute of ['hidden', 'aria-hidden="true"', 'class="hidden"', 'style="display:none"', 'style="visibility:hidden"', 'style="display : none"', 'class="layout hidden content"']) {
    const hidden = html().replace('<article ', `<div ${attribute}><article `).replace('</article>', '</article></div>');
    assert.throws(() => assertSsrCandidate(hidden, row, meta), /IQ_ARTICLE_SSR_BODY_MISMATCH/);
    const hiddenLink = html().replace('<a href="https://example.invalid/source">', `<span ${attribute}><a href="https://example.invalid/source">`).replace('Source</a>', 'Source</a></span>');
    assert.throws(() => assertSsrCandidate(hiddenLink, row, meta), /IQ_ARTICLE_SSR_(?:BODY|LINK)_MISMATCH/);
  }
});
test('robots compares all directives without order or case dependence', () => {
  assertSsrCandidate(html().replace('content="noindex, nofollow, noarchive, nocache"', 'content="NOFOLLOW, NOINDEX, NOCACHE, NOARCHIVE"'), row, meta);
  assertSsrCandidate(html().replace('content="noindex, nofollow, noarchive, nocache"', 'content="noindex,nofollow,noarchive,nocache"'), row, meta);
  for (const value of ['noindex,follow', 'index,nofollow', 'noindex,nofollow,noarchive']) {
    assert.throws(() => assertSsrCandidate(html().replace('content="noindex, nofollow, noarchive, nocache"', `content="${value}"`), row, meta), /IQ_ARTICLE_SSR_ROBOTS_MISMATCH/);
  }
});

test('body order and duplicate occurrences must survive the rendered projection', () => {
  const ordered = { ...row, snapshot: { ...row.snapshot, content_md: 'First step\nSecond step\nFirst step' } };
  const replaceBody = body => html().replace(/(<article[^>]+>)[\s\S]*<\/article>/, `$1${body}</article>`);
  assertSsrCandidate(replaceBody('<p>First step</p><p>Second step</p><p>First step</p>'), ordered, meta);
  for (const body of ['<p>Second step</p><p>First step</p>', '<p>First step</p><p>Second step</p>', '<p>First step</p><p>First step</p><p>Second step</p>'])
    assert.throws(() => assertSsrCandidate(replaceBody(body), ordered, meta), /IQ_ARTICLE_SSR_BODY_MISMATCH/);
});
test('computed html or body visibility and crawler-specific restrictions are verified', () => {
  for (const tag of ['html', 'body']) assert.throws(() => assertSsrCandidate(html().replace(`<${tag}>`, `<${tag} data-iq-qa-hidden="true">`), row, meta), /IQ_ARTICLE_SSR_BODY_MISMATCH/);
  for (const name of ['googlebot', 'bingbot', 'baiduspider', 'slurp']) {
    const crawler = html().replace('</head>', `<meta name="${name}" content="noimageindex"></head>`);
    assert.throws(() => assertSsrCandidate(crawler, row, meta), /IQ_ARTICLE_SSR_ROBOTS_MISMATCH/);
  }
  assert.throws(() => assertSsrCandidate(html(), row, meta, 'production', 'googlebot: noimageindex'), /IQ_ARTICLE_SSR_ROBOTS_MISMATCH/);
  assertSsrCandidate(html(), row, meta, 'production', 'noindex');
});

test('computed invisibility of the document title does not hide its metadata value',()=>{
  assert.doesNotThrow(()=>assertSsrCandidate(html().replace('<title>','<title data-iq-qa-hidden="true">'),row,meta));
  assert.throws(()=>assertSsrCandidate(html().replace('<h1>','<h1 data-iq-qa-hidden="true">'),row,meta),/IQ_ARTICLE_SSR_METADATA_MISMATCH/);
});

test('bound CTA attribution validates source, target, fixed values and unique allowed parameters',()=>{
  const base='/en/tests/iq-test-intelligence-quotient-assessment';
  const params = new URLSearchParams({entry_surface:'article_detail_seo_cta',source_page_type:'article_detail',source_route_family:'article',
    source_slug:row.identity.slug,content_id:'999',target_action:'seo_cta_cms_content_iq_test_intelligence_quotient_assessment',
    test_slug:'iq-test-intelligence-quotient-assessment',target_test_slug:'iq-test-intelligence-quotient-assessment',
    cta_id:'cms_content_iq_test_intelligence_quotient_assessment',landing_path:'/en/articles/'+row.identity.slug,entrypoint:'seo_cta'});
  const withHref=href=>html().replace(`href="${base}"`,`href="${escapeHtml(href)}"`);
  assert.doesNotThrow(()=>assertSsrCandidate(withHref(base+'?'+params),row,meta));
  for (const [key,value] of [['entrypoint','WRONG'],['target_test_slug','OTHER'],['source_slug','other'],['landing_path','/en/articles/other'],
    ['source_page_type','topic_detail'],['entry_surface','topic_detail_seo_cta'],['target_action','wrong'],['cta_id','wrong'],['content_id','invalid!']]) {
    const changed=new URLSearchParams(params);changed.set(key,value);
    assert.throws(()=>assertSsrCandidate(withHref(base+'?'+changed),row,meta),/IQ_ARTICLE_SSR_LINK_MISMATCH/);
  }
  for(const href of [base+'?form=another-form',base+'?unknown_control=1',base+'?'+params+'&entrypoint=seo_cta',
    base+'?'+params+'&unexpected=1',base+'?'+params+'&constructor=1','/en/tests/eq-test-emotional-intelligence-assessment'])
    assert.throws(()=>assertSsrCandidate(withHref(href),row,meta),/IQ_ARTICLE_SSR_(?:LINK|BODY)_MISMATCH/);
});

test('canonical identity rejects another HTTPS port in SSR and actual API readback',async()=>{
  for(const env of ['production','staging']) assert.throws(()=>assertSsrCandidate(html().replace('href="https://fermatmind.com/en/articles/',
    'href="https://fermatmind.com:8443/en/articles/'),row,meta,env),/IQ_ARTICLE_SSR_CANONICAL_MISMATCH/);
  const pre=await readOnlinePrestate('production',backend,onlineFetcher(undefined,true));
  await assert.rejects(verifyOnlineCandidates('production',backend,onlineFetcher(payload=>{if(payload.meta)payload.meta.canonical=payload.meta.canonical.replace('fermatmind.com/','fermatmind.com:8443/');}),()=>'',pre),/IQ_ARTICLE_ONLINE_SEO_MISMATCH/);
});

for (const mountedShell of [false, true]) for (const surface of ['article', 'entry', 'topic']) test(`real Chrome waits for the visible ${surface} copy ${mountedShell ? 'inside an already visible shell' : 'after the initial document loads'}`, async () => {
  const content = surface === 'article'
    ? '<article data-testid="article-detail-content"><p>Reviewed article copy.</p></article>'
    : surface === 'entry'
      ? '<main data-test-landing-read-source="cms"><p>Reviewed entry copy.</p></main>'
      : '<main><section id="overview"><p>Reviewed topic copy.</p></section><section id="faq"><p>Reviewed topic FAQ.</p></section></main>';
  const server = createServer((_request, response) => {
    response.writeHead(200, { 'Content-Type': 'text/html' });
    response.end(`<html><body><div id="mount">${mountedShell ? content.replace(/Reviewed[^<]+/g, 'Loading copy.') : 'Loading copy'}</div><script>setTimeout(() => document.getElementById('mount').innerHTML = ${JSON.stringify(content)}, 2500)</script></body></html>`);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    const verifyDOM = html => {
      assert.match(articleDom(html, surface).body, new RegExp(`Reviewed ${surface} copy`));
      if (surface === 'topic') assert.match(articleDom(html, 'topic_faq').body, /Reviewed topic FAQ/);
    };
    const actual = await renderPage(`http://127.0.0.1:${server.address().port}/document`, { surface, verifyDOM });
    assert.match(articleDom(actual, surface).body, new RegExp(`Reviewed ${surface} copy`));
    if (surface === 'topic') assert.match(articleDom(actual, 'topic_faq').body, /Reviewed topic FAQ/);
  } finally {
    await new Promise(resolve => server.close(resolve));
  }
});


test('visible but permanently wrong copy expires into the original strict online rejection', async () => {
  const server = createServer((_request, response) => {
    response.writeHead(200, { 'Content-Type': 'text/html' });
    response.end(renderedFixture(rows[0]).replace(/(<article[^>]*>)[\s\S]*?(<\/article>)/, '$1Wrong published body.$2'));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    const prestate = await readOnlinePrestate('production', backend, onlineFetcher(undefined, true));
    let renderCount = 0;
    await assert.rejects(verifyOnlineCandidates('production', backend, onlineFetcher(), (_url, options) => {
      renderCount++;
      return renderPage(`http://127.0.0.1:${server.address().port}/document`, options);
    }, prestate), /IQ_ARTICLE_SSR_BODY_MISMATCH/);
    assert.equal(renderCount, 1);
  } finally {
    await new Promise(resolve => server.close(resolve));
  }
});

test('IQ renderer closes its owned browser before profile cleanup on success and render failure', async () => {
  const directory = mkdtempSync(join(tmpdir(), 'iq-browser-lifecycle-test-'));
  const previous = process.env.CHROME_BIN;
  try {
    for (const failed of [false, true]) {
      const marker = join(directory, failed ? 'failed.json' : 'success.json');
      const executable = join(directory, 'fake-browser');
      const source = `#!${process.execPath}
const fs = require('node:fs');
const profile = process.argv.find(x => x.startsWith('--user-data-dir=')).split('=')[1];
const input = fs.createReadStream(null, { fd: 3 }), output = fs.createWriteStream(null, { fd: 4 });
let buffer = '';
process.on('SIGTERM', () => { fs.writeFileSync(${JSON.stringify(marker)}, JSON.stringify({ graceful: false, profile })); process.exit(0); });
input.on('data', chunk => {
 buffer += chunk;
 let end;
 while ((end = buffer.indexOf('\\0')) >= 0) {
  const message = JSON.parse(buffer.slice(0, end)); buffer = buffer.slice(end + 1);
  if (message.method === 'Browser.close') {
   fs.writeFileSync(${JSON.stringify(marker)}, JSON.stringify({ graceful: true, profile }));
   output.end(JSON.stringify({ id: message.id, result: {} }) + '\\0', () => process.exit(0)); return;
  }
  let result = message.method === 'Target.createTarget' ? { targetId: 'target' } : message.method === 'Target.attachToTarget' ? { sessionId: 'session' } : {};
  if (message.method === 'Runtime.evaluate') result = ${failed} ? { exceptionDetails: {} } : { result: { value: { html: '<html>Rendered</html>', ready: true } } };
  output.write(JSON.stringify({ id: message.id, result }) + '\\0');
 }
});`;
      writeFileSync(executable, source, { mode: 0o700 }); process.env.CHROME_BIN = executable;
      if (failed) await assert.rejects(renderPage('https://example.invalid'), /IQ_ARTICLE_ONLINE_RENDER_FAILED/);
      else assert.equal(await renderPage('https://example.invalid'), '<html>Rendered</html>');
      const state = JSON.parse(readFileSync(marker, 'utf8'));
      assert.equal(state.graceful, true); assert.equal(existsSync(state.profile), false);
    }
  } finally {
    if (previous === undefined) delete process.env.CHROME_BIN; else process.env.CHROME_BIN = previous;
    rmSync(directory, { recursive: true, force: true });
  }
});
