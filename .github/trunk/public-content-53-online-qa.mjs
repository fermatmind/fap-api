import { execFileSync } from 'node:child_process';
import { canonical } from './iq-public-scale-package.mjs';
import { readCandidateRows } from './public-content-53-package.mjs';
import { renderPage } from './iq-public-article-online-qa.mjs';

const equal = (a,b) => JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));
const compact = x => String(x ?? '').normalize('NFKC').replace(/[\s\p{P}\p{S}]/gu,'');
const plain = x => x.replace(/!\[[^\]]*\]\([^)]*\)/g,'').replace(/\[([^\]]+)\]\([^)]*\)/g,'$1')
  .replace(/\[\^[^\]]+\]/g,'').replace(/^\s*(?:>\s*)+|^\s*(?:#{1,6}\s*|[-*+]\s+|\d+[.)]\s+)/g,'').replace(/[*`_]/g,'');
export const pathFor = row => {
  const {slug,locale} = row.identity; const segment = locale === 'en' ? 'en' : 'zh';
  if (row.kind === 'article') return `/${segment}/articles/${slug}`;
  if (row.kind === 'profile') return `/${segment}/topics/${slug}`;
  if (row.kind === 'surface') return `/${segment}/tests${slug === 'tests' ? '' : '/category/'+slug.replace('tests_category_','')}`;
  return `/${segment}/${slug.startsWith('help-') ? 'help/'+slug.slice(5) : slug}`;
};
const hosts = env => {
  if (!['staging','production'].includes(env)) throw new Error('PUBLIC_CONTENT_53_ENV_INVALID');
  return env === 'staging' ? ['https://staging-api.fermatmind.com','https://staging.fermatmind.com'] : ['https://api.fermatmind.com','https://fermatmind.com'];
};
async function read(url, fetcher) {
  const response = await fetcher(url, {redirect:'error',signal:AbortSignal.timeout(15000),headers:{'Cache-Control':'no-cache'}});
  if (response.status !== 200) throw new Error('PUBLIC_CONTENT_53_RESPONSE_INVALID');
  const bytes = await response.text();
  if (bytes.length > 2500000) throw new Error('PUBLIC_CONTENT_53_RESPONSE_LIMIT');
  return bytes;
}
export function domOf(html) {
  const code = String.raw`
$doc = new DOMDocument; libxml_use_internal_errors(true);
if (!$doc->loadHTML(stream_get_contents(STDIN), LIBXML_NONET)) throw new RuntimeException('invalid DOM');
$xp = new DOMXPath($doc);
$walk = function($node) use (&$walk) {
 if ($node instanceof DOMElement && (in_array($node->tagName,['script','style','template','noscript']) || $node->getAttribute('data-iq-qa-hidden') === 'true' || $node->hasAttribute('hidden') || $node->getAttribute('aria-hidden') === 'true')) return '';
 if ($node instanceof DOMText) return $node->nodeValue;
 $text=''; foreach($node->childNodes as $child) $text .= ' '.$walk($child); return $text;
};
$values = function($query,$attr=null) use($xp,$walk) { $out=[];foreach($xp->query($query) as $node) $out[]=$attr===null?$walk($node):$node->getAttribute($attr);return $out;};
echo json_encode(['body'=>$values('//main[not(ancestor::main)]'),'h1'=>$values('//main//h1'),'links'=>$values('//main//a','href'),
 'canonical'=>$values('//link[@rel="canonical"]','href'),'robots'=>$values('//meta[@name="robots"]','content'),
 'alternates'=>$values('//link[@rel="alternate"]','href')],JSON_THROW_ON_ERROR);`;
  return JSON.parse(execFileSync('php',['-r',code],{input:html,encoding:'utf8',maxBuffer:3000000,timeout:15000,stdio:['pipe','pipe','pipe']}));
}
export function projection(row,data) {
  const value = data[row.kind];
  if (data.ok !== true || !value || value.locale !== row.identity.locale || (['article','profile'].includes(row.kind) && value.org_id !== 0)
    || (value.slug ?? value.surface_key) !== row.identity.slug || value.status !== 'published' || value.is_public !== true) throw new Error('PUBLIC_CONTENT_53_IDENTITY_DRIFT');
  const result = Object.fromEntries(Object.keys(row.snapshot).filter(key=>Object.hasOwn(value,key)).map(key=>[key,value[key]]));
  if (row.kind === 'profile') Object.assign(result,{sections:data.sections,entry_groups:data.entry_groups,seo_text:{title:data.seo_meta?.seo_title,description:data.seo_meta?.seo_description}});
  return result;
}
const apiPath = row => `/api/v0.5/${{article:'articles',page:'content-pages',surface:'landing-surfaces',profile:'topics'}[row.kind]}/${row.identity.slug}?locale=${row.identity.locale}&org_id=0`;
export async function readOnlinePrestate(environment,backendRoot,fetcher=fetch) {
  const [api,web] = hosts(environment); const result={};
  for (const row of readCandidateRows(backendRoot).candidates) {
    const data=JSON.parse(await read(api+apiPath(row),fetcher));const value=projection(row,data);
    if (!equal(value,environment === 'staging' ? row.staging_before : row.before) && !equal(value,row.snapshot)) throw new Error(`PUBLIC_CONTENT_53_PRESTATE_DRIFT_${row.page_id}`);
    const native=data[row.kind];
    result[row.page_id]={id:native.id,flags:Object.fromEntries(['is_indexable','sitemap_eligible','llms_eligible','translation_group_id','source_locale'].filter(k=>Object.hasOwn(native,k)).map(k=>[k,native[k]])),dom:domOf(await read(web+pathFor(row),fetcher))};
  }
  return result;
}
function changedStrings(before,after) {
  if (typeof after === 'string') return before !== after ? [after] : [];
  if (!after || typeof after !== 'object') return [];
  return Object.entries(after).flatMap(([k,v])=>changedStrings(before?.[k],v));
}
export function assertDom(row,html,before,environment) {
  const dom=domOf(html); const body=compact(dom.body.join(' '));
  if (dom.body.length !== 1 || dom.h1.length !== 1 || !body) throw new Error(`PUBLIC_CONTENT_53_DOM_STRUCTURE_${row.page_id}`);
  if (row.kind !== 'surface' && compact(dom.h1[0]) !== compact(row.snapshot.title)) throw new Error(`PUBLIC_CONTENT_53_H1_${row.page_id}`);
  const copy=row.snapshot.content_md;
  const lines = copy ? copy.split('\n').filter(line=>line.trim() && !/^[\s|:-]+$/.test(line) && !/^#(?:content_md|summary|faq)\s*$/.test(line) && !/^\[\^[^\]]+\]:/.test(line)).flatMap(line=>line.split('|'))
    : row.kind === 'profile' ? row.snapshot.sections.flatMap(s=>[s.title,s.body_md]).filter(Boolean) : changedStrings(row.before,row.snapshot).filter(s=>!s.startsWith('/') && !s.startsWith('https://'));
  for (const line of lines) {
    const text=compact(plain(line));
    if (text && !body.includes(text)) throw new Error(`PUBLIC_CONTENT_53_VISIBLE_BODY_${row.page_id}`);
  }
  if (dom.canonical.length !== 1 || !equal(dom.canonical,before.dom.canonical) || !equal(dom.robots,before.dom.robots)
    || !equal(dom.alternates,before.dom.alternates)) throw new Error(`PUBLIC_CONTENT_53_DISCOVERABILITY_DRIFT_${row.page_id}`);
  const path=pathFor(row);const url=new URL(dom.canonical[0]);
  if (url.protocol !== 'https:' || !['fermatmind.com','www.fermatmind.com',...(environment === 'staging' ? ['staging.fermatmind.com']:[])].includes(url.hostname)
    || url.pathname !== path || url.search || url.hash) throw new Error(`PUBLIC_CONTENT_53_CANONICAL_${row.page_id}`);
  for (const link of copy?.matchAll(/\[[^\]]+\]\((\/(?:en|zh)\/[^)]+)\)/g) ?? []) {
    const target=new URL(link[1],'https://fermatmind.com');
    if (target.pathname.includes('\\') || !dom.links.some(href=>{try{return new URL(href,'https://fermatmind.com').pathname === target.pathname;}catch{return false;}})) throw new Error(`PUBLIC_CONTENT_53_LINK_${row.page_id}`);
  }
  if (row.page_id === 'PAGE-37') {
    const cta=dom.links.map(href=>new URL(href,'https://fermatmind.com')).find(u=>u.searchParams.get('cta_id') === 'start_test');
    if (!cta || cta.pathname !== '/en/tests/big-five-personality-test-ocean-model' || cta.searchParams.get('test_slug') !== 'big-five-personality-test-ocean-model'
      || cta.searchParams.get('target_test_slug') !== 'big-five-personality-test-ocean-model' || cta.searchParams.get('source_slug') !== 'big-five') throw new Error('PUBLIC_CONTENT_53_BIG5_TRACKING');
  }
}
export async function verifyOnlineCandidates(environment,backendRoot,fetcher=fetch,renderer=renderPage,prestate) {
  const [api,web]=hosts(environment);if(!prestate) throw new Error('PUBLIC_CONTENT_53_PRESTATE_REQUIRED');
  for (const row of readCandidateRows(backendRoot).candidates) {
    const data=JSON.parse(await read(api+apiPath(row),fetcher));const before=prestate[row.page_id];const native=data[row.kind];
    if(!before || !equal(projection(row,data),row.snapshot) || native.id !== before.id || Object.entries(before.flags).some(([k,v])=>!equal(native[k],v))) throw new Error(`PUBLIC_CONTENT_53_NATIVE_READBACK_${row.page_id}`);
    // One publication only. Existing 300-second public fetch caches can settle
    // while exact candidate DOM assertions remain strict and bounded.
    const deadline=Date.now()+360000;let last;
    while (true) {
      try { const verifyDOM=html=>assertDom(row,html,before,environment);verifyDOM(await renderer(web+pathFor(row),{surface:'public_page',verifyDOM}));break; }
      catch(error) {last=error;if(Date.now()>=deadline) throw last;await new Promise(resolve=>setTimeout(resolve,10000));}
    }
  }
  return {environment,api_readback_count:50,dom_readback_count:50};
}
