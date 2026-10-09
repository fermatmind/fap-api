import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { assertSsrCandidate, verifyOnlineCandidates, readCandidateRows } from './iq-public-scale-online-qa.mjs';

const row = { key: 'en', identity: {locale:'en'}, metadata: {title:'Reviewed IQ title',description:'Reviewed IQ description'}, patch: {
  landing_copy: 'Understand this visual reasoning exercise.',
  why_choose: { intro: 'Use raw results carefully.', items: [
    { title: 'Example', body: '| A | B |\n| --- | --- |\n| 2 | 4 |\n\nRead [the method](https://fermatmind.com/en/articles/iq-method) before **starting**.' },
  ] },
  faq: [{ q: 'Is this a clinical IQ score?', a: 'No. The Beta indicator is simulated.' }],
} };
const html = '<html><head><meta charset="utf-8"><title>Reviewed IQ title</title><meta name="description" content="Reviewed IQ description"><meta name="robots" content="index,follow"><link rel="canonical" href="https://fermatmind.com/en/tests/iq-test-intelligence-quotient-assessment"></head><body><h1>Free IQ Test</h1><main data-test-landing-read-source="fresh"><p>Understand this visual reasoning exercise.</p><p>Use raw results carefully.</p><h2>Example</h2><table><tr><td>A</td><td>B</td></tr><tr><td>2</td><td>4</td></tr></table><p>Read <a href="https://fermatmind.com/en/articles/iq-method">the method</a> before <strong>starting</strong>.</p><details><summary>Is this a clinical IQ score?</summary><p>No. The Beta indicator is simulated.</p></details><a href="/en/tests/iq-test-intelligence-quotient-assessment/take?form=IQ_OWNER_ORIGINAL_30">Start current form</a></main></body></html>';

test('SSR acceptance reads table cells, Markdown text and collapsed FAQ content', () => {
  assert.doesNotThrow(() => assertSsrCandidate(html, row));
});
test('matching navigation or structured data cannot substitute for a missing visible body', () => {
  assert.throws(() => assertSsrCandidate(`<script type="application/ld+json">${JSON.stringify(row)}</script>`, row), /IQ_SSR_(?:BODY_MISMATCH|MAIN_INVALID|DOM_INVALID)/);
});
test('a stale FAQ answer fails even when every other paragraph matches', () => {
  assert.throws(() => assertSsrCandidate(html.replace('No. The Beta indicator is simulated.', 'A standardized clinical score.'), row), /IQ_SSR_BODY_MISMATCH/);
});
test('a missing table cell fails', () => {
  assert.throws(() => assertSsrCandidate(html.replace('<td>4</td>', ''), row), /IQ_SSR_BODY_MISMATCH/);
});

test('hidden content cannot replace the visible assessment body', () => {
  assert.throws(() => assertSsrCandidate(html.replace('<main ', '<main hidden '), row), /IQ_SSR_BODY_MISMATCH/);
  assert.throws(() => assertSsrCandidate(html.replace('<main ', '<main style="display: none" '), row), /IQ_SSR_BODY_MISMATCH/);
  assert.throws(() => assertSsrCandidate(html.replace('<main ', '<main class="hidden" '), row), /IQ_SSR_BODY_MISMATCH/);
});
test('matching text outside the actual main surface does not satisfy acceptance', () => {
  assert.throws(() => assertSsrCandidate(html.replace('<main ', '<footer ').replace('</main>', '</footer>') + '<main data-test-landing-read-source="fresh">Old entry</main>', row), /IQ_SSR_BODY_MISMATCH/);
});

test('html/body ancestors and computed CSS markers cannot hide a passing body', () => {
  for(const [before,after] of [['<html>','<html hidden>'],['<body>','<body style="display:none">'],['<body>','<body data-iq-qa-hidden="true">']])
    assert.throws(()=>assertSsrCandidate(html.replace(before,after),row),/IQ_SSR_BODY_MISMATCH/);
});
test('ordered repeated copy must appear the reviewed number of times', () => {
  const repeated=structuredClone(row);repeated.patch.landing_copy+='\nUnderstand this visual reasoning exercise.';
  assert.throws(()=>assertSsrCandidate(html,repeated),/IQ_SSR_BODY_MISMATCH/);
  assert.throws(()=>assertSsrCandidate(html.replace('Use raw results carefully.','Use raw results carefully. Understand this visual reasoning exercise.'),repeated),/IQ_SSR_BODY_MISMATCH/);
});
test('canonical, metadata, crawler/HTTP restrictions and enabled original form CTA are mandatory', () => {
  for(const text of [html.replace('/en/tests/iq-test-intelligence-quotient-assessment"','/en/articles/wrong"'),html.replace('Reviewed IQ description','Wrong description'),html.replace('index,follow','noindex,nofollow'),html.replace('<head>','<head><meta name="googlebot" content="noindex">'),html.replace('IQ_OWNER_ORIGINAL_30','IQ_BETA_50_ORIGINAL')])
    assert.throws(()=>assertSsrCandidate(text,row),/IQ_SSR_(?:CANONICAL|METADATA|ROBOTS|CTA)_MISMATCH/);
  assert.throws(()=>assertSsrCandidate(html,row,{robots:'index,follow'},'production','all,noindex'),/IQ_SSR_ROBOTS_MISMATCH/);
});
test('staging uses the bound frontend noindex policy; that policy cannot authorize production', () => {
  const stage=html.replace('index,follow','noindex,nofollow,noarchive,nocache');
  assert.doesNotThrow(()=>assertSsrCandidate(stage,row,{robots:'index,follow'},'staging','noindex,nofollow,noarchive'));
  assert.throws(()=>assertSsrCandidate(stage,row,{robots:'index,follow'},'production'),/IQ_SSR_ROBOTS_MISMATCH/);
});
test('the actual API/render entry path awaits both locales and reviewed full copy with metadata and CTA', async()=>{
  const root=fileURLToPath(new URL('../../backend/',import.meta.url));const rows=readCandidateRows(root);const calls=[];let indexable=true;
  const esc=value=>String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
  const fetcher=async url=>{const u=new URL(url);if(u.hostname==='fermatmind.com')return new Response('<html/>',{headers:{'content-type':'text/html'}});const row=rows.find(r=>r.identity.locale===u.searchParams.get('locale'));return Response.json({ok:true,primary_slug:'iq-test-intelligence-quotient-assessment',scale_code:'IQ_RAVEN',is_public:true,is_indexable:indexable,content_i18n_json:{[row.key]:row.patch}});};
  const renderer=async url=>{await Promise.resolve();calls.push(url);const row=rows.find(r=>url.includes('/'+r.key+'/'));const text=[row.patch.landing_copy,row.patch.why_choose.intro,...row.patch.why_choose.items.flatMap(i=>[i.title,i.body]),...row.patch.faq.flatMap(i=>[i.q,i.a])].join('\n').replace(/\[([^\]]+)\]\([^)]+\)/g,'$1').replace(/[*`_]/g,'').replace(/^\s*(?:#{1,6}\s+|[-*+]\s+|[0-9]+[.)]\s+)/gm,'');const links=[...JSON.stringify(row.patch).matchAll(/\[([^\]]+)\]\(([^)]+)\)/g)].map(m=>`<a href="${esc(m[2])}">${esc(m[1])}</a>`).join('');return `<html><head><meta charset="utf-8"><title>${esc(row.metadata.title)}</title><meta name="description" content="${esc(row.metadata.description)}"><meta name="robots" content="${indexable?'index,follow':'noindex,nofollow,noarchive,nocache'}"><link rel="canonical" href="https://fermatmind.com/${row.key}/tests/iq-test-intelligence-quotient-assessment"></head><body><h1>${row.key==='en'?'Free IQ Test':'IQ智商免费测试'}</h1><main data-test-landing-read-source="fresh">${esc(text)}${links}<a href="/${row.key}/tests/iq-test-intelligence-quotient-assessment/take?form=IQ_OWNER_ORIGINAL_30">Start form</a></main></body></html>`;};
  for(indexable of [true,false]){const result=await verifyOnlineCandidates('production',root,fetcher,renderer);assert.equal(result.api_readback_count,2);assert.equal(result.ssr_readback_count,2);}assert.equal(calls.length,4);
});

test('entry CTA rejects unknown and duplicate query parameters and userinfo',()=>{
  for(const suffix of ['&unexpected=1','&form=IQ_OWNER_ORIGINAL_30','&entrypoint=WRONG'])
    assert.throws(()=>assertSsrCandidate(html.replace('form=IQ_OWNER_ORIGINAL_30','form=IQ_OWNER_ORIGINAL_30'+suffix),row),/IQ_SSR_(?:CTA|LINK)_MISMATCH/);
  assert.throws(()=>assertSsrCandidate(html.replace('href="/en/tests/iq-test-intelligence-quotient-assessment/take?',
    'href="https://attacker@fermatmind.com/en/tests/iq-test-intelligence-quotient-assessment/take?'),row),/IQ_SSR_(?:CTA|LINK)_MISMATCH/);
});
test('non-indexable production entry projects the bound nofollow frontend policy',()=>{
  const projected=html.replace('index,follow','noindex,nofollow,noarchive,nocache');
  assert.doesNotThrow(()=>assertSsrCandidate(projected,row,{robots:'noindex,nofollow'},'production'));
  assert.throws(()=>assertSsrCandidate(projected.replace('noindex,nofollow','noindex,follow'),row,{robots:'noindex,nofollow'},'production'),/IQ_SSR_ROBOTS_MISMATCH/);
});

test('another valid CTA cannot hide a malformed current-form CTA',()=>{
  const extra='<a href="/en/tests/iq-test-intelligence-quotient-assessment/take?form=IQ_OWNER_ORIGINAL_30&amp;unexpected=1">Other CTA</a>';
  assert.throws(()=>assertSsrCandidate(html.replace('</main>',extra+'</main>'),row),/IQ_SSR_CTA_MISMATCH/);
});

test('entry canonical rejects another HTTPS port',()=>{
  assert.throws(()=>assertSsrCandidate(html.replace('href="https://fermatmind.com/en/tests/',
    'href="https://fermatmind.com:8443/en/tests/'),row),/IQ_SSR_CANONICAL_MISMATCH/);
});
