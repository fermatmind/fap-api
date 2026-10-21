import { execFileSync } from 'node:child_process';
import { canonical } from './iq-public-scale-package.mjs';
import { readCandidateRows } from './public-content-53-package.mjs';
import { renderPage } from './iq-public-article-online-qa.mjs';

const equal = (a,b) => JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));
const compact = x => String(x ?? '').normalize('NFKC').replace(/[\s\p{P}\p{S}]/gu,'');
const plain = x => /^\s*```[\w-]*\s*$/.test(x) ? '' : x.replace(/!\[[^\]]*\]\([^)]*\)/g,'').replace(/\[([^\]]+)\]\([^)]*\)/g,'$1')
  .replace(/^\s*\[\^([0-9A-Za-z-]+)\]:\s*/,'$1 ').replace(/\[\^([0-9A-Za-z-]+)\]/g,'$1')
  .replace(/^\s*(?:>\s*)+|^\s*(?:#{1,6}\s*|[-*+]\s+|\d+[.)]\s+)/g,'').replace(/[*`_]/g,'');
export const pathFor = row => {
  const {slug,locale} = row.identity; const segment = locale === 'en' ? 'en' : 'zh';
  if (row.kind === 'guide') return `/${segment}/career/guides/${slug}`;
  if (row.kind === 'article') return `/${segment}/articles/${slug}`;
  if (row.kind === 'profile') return `/${segment}/topics/${slug}`;
  if (row.kind === 'surface') return slug === 'home' ? `/${segment}` : `/${segment}/tests${slug === 'tests' ? '' : '/category/'+slug.replace('tests_category_','')}`;
  return `/${segment}/${slug.startsWith('help-') ? 'help/'+slug.slice(5) : slug}`;
};
const hosts = env => {
  if (!['staging','production'].includes(env)) throw new Error('PUBLIC_CONTENT_53_ENV_INVALID');
  return env === 'staging' ? ['https://staging-api.fermatmind.com','https://staging.fermatmind.com'] : ['https://api.fermatmind.com','https://fermatmind.com'];
};
export async function read(url, fetcher, allowStagingBootstrap404 = false) {
  let response, bytes;
  try { response = await fetcher(url, {redirect:'error',signal:AbortSignal.timeout(15000),headers:{'Cache-Control':'no-cache'}}); }
  catch (error) {
    const code = error?.cause?.code ?? error?.name;
    const known = new Set(['UND_ERR_SOCKET','UND_ERR_CONNECT_TIMEOUT','UND_ERR_HEADERS_TIMEOUT','ECONNRESET','ENOTFOUND','TimeoutError','AbortError']);
    throw new Error('PUBLIC_CONTENT_53_API_TRANSPORT_' + (known.has(code) ? code.toUpperCase() : 'FAILED'));
  }
  if (response.status !== 200 && !(response.status === 404 && allowStagingBootstrap404)) throw new Error('PUBLIC_CONTENT_53_RESPONSE_INVALID');
  try { bytes = await response.text(); }
  catch { throw new Error('PUBLIC_CONTENT_53_API_BODY_TRANSPORT_FAILED'); }
  if (bytes.length > 2500000) throw new Error('PUBLIC_CONTENT_53_RESPONSE_LIMIT');
  if (response.status === 404) {
    let data;
    try { data=JSON.parse(bytes); } catch { throw new Error('PUBLIC_CONTENT_53_API_JSON_INVALID'); }
    if (data.ok !== false || data.error_code !== 'NOT_FOUND') throw new Error('PUBLIC_CONTENT_53_RESPONSE_INVALID');
    return null;
  }
  return bytes;
}
const parseApi = bytes => {
  try { return JSON.parse(bytes); } catch { throw new Error('PUBLIC_CONTENT_53_API_JSON_INVALID'); }
};
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
$labels=[]; foreach($xp->query('//main//a') as $anchor) { $label=$walk($anchor); if (trim($label)!=='') $labels[$anchor->getAttribute('href')][]=$label; }
$values = function($query,$attr=null) use($xp,$walk) { $out=[];foreach($xp->query($query) as $node) $out[]=$attr===null?$walk($node):$node->getAttribute($attr);return $out;};
$quickstart=[]; foreach($xp->query('//script[@type="application/ld+json" and @id="tests-hub-quickstart-en"]') as $node) $quickstart[]=$node->textContent;
$homeJsonLd=[]; foreach($xp->query('//script[@type="application/ld+json"]') as $node) $homeJsonLd[]=$node->textContent;
echo json_encode(['link_labels'=>$labels,'body'=>$values('//main[not(ancestor::main)]'),'h1'=>$values('//main//h1'),'links'=>$values('//main//a','href'),
 'home_jsonld'=>$homeJsonLd,'home_iq_cards'=>$values('//main//article[.//a[contains(@href,"iq-test-intelligence-quotient-assessment")]]'),
 'tests_quickstart_jsonld'=>$quickstart,'article_body'=>$values('//main//article[@data-testid="article-detail-content"]'),
 'title'=>$values('//head/title'),'description'=>$values('//meta[@name="description"]','content'),
 'og_title'=>$values('//meta[@property="og:title"]','content'),'og_description'=>$values('//meta[@property="og:description"]','content'),
 'canonical'=>$values('//link[@rel="canonical"]','href'),'robots'=>$values('//meta[@name="robots"]','content'),
 'alternates'=>$values('//link[@rel="alternate"]','href')],JSON_THROW_ON_ERROR);`;
  return JSON.parse(execFileSync('php',['-r',code],{input:html,encoding:'utf8',maxBuffer:3000000,timeout:15000,stdio:['pipe','pipe','pipe']}));
}
export function projection(row,data) {
  const value = data[row.kind];
  if (data.ok !== true || !value || value.locale !== row.identity.locale || (['article','profile','guide'].includes(row.kind) && value.org_id !== 0)
    || (value.slug ?? value.surface_key) !== row.identity.slug || (row.kind !== 'guide' && value.status !== 'published') || value.is_public !== true) throw new Error('PUBLIC_CONTENT_53_IDENTITY_DRIFT');
  if (row.kind === 'guide' && value.guide_code !== 'from-mbti-to-job-fit') throw new Error('PUBLIC_CONTENT_53_IDENTITY_DRIFT');
  const result = Object.fromEntries(Object.keys(row.snapshot).filter(key=>Object.hasOwn(value,key)).map(key=>[key,value[key]]));
  if (row.kind === 'surface' && row.snapshot.block_copy) {
    result.block_copy=Object.fromEntries(Object.keys(row.snapshot.block_copy).map(key=>{
      const blocks=(value.page_blocks ?? []).filter(block=>block.block_key === key && block.is_enabled === true);
      if(blocks.length !== 1) throw new Error('PUBLIC_CONTENT_53_BLOCK_IDENTITY_DRIFT');
      return [key,blocks[0].payload_json];
    }));
  }
  if (row.kind === 'article' && row.snapshot.seo_text) result.seo_text=Object.fromEntries(Object.keys(row.snapshot.seo_text).filter(key=>Object.hasOwn(value.seo_meta ?? {},key)).map(key=>[key,value.seo_meta[key]]));
  if (row.kind === 'profile') Object.assign(result,{sections:data.sections,entry_groups:data.entry_groups,seo_text:{title:data.seo_meta?.seo_title,description:data.seo_meta?.seo_description}});
  return result;
}
const apiPath = row => `/api/v0.5/${{article:'articles',page:'content-pages',surface:'landing-surfaces',profile:'topics',guide:'career-guides'}[row.kind]}/${row.identity.slug}?locale=${row.identity.locale}&org_id=0`;
export async function readOnlinePrestate(environment,backendRoot,fetcher=fetch,renderer=renderPage,packageSha256) {
  const [api,web] = hosts(environment); const result={};
  const rows = readCandidateRows(backendRoot,packageSha256).candidates.map(row=>environment === 'staging' && row.staging_snapshot ? {...row,snapshot:row.staging_snapshot} : row);
  for (const row of rows) {
    const bytes=await read(api+apiPath(row),fetcher,environment === 'staging' && row.page_id === 'PAGE-60' && !!row.staging_bootstrap);
    if (bytes === null) { result[row.page_id]={missing:true};continue; }
    const data=parseApi(bytes);const value=projection(row,data);
    if (!equal(value,environment === 'staging' ? row.staging_before : row.before) && !equal(value,row.snapshot)) throw new Error(`PUBLIC_CONTENT_53_PRESTATE_DRIFT_${row.page_id}`);
    const native=data[row.kind];
    // Metadata-only Article updates compare the unchanged reader body before and
    // after publication. Raw Next.js streams can contain only its initial chunk;
    // capture the same hydrated, visibility-aware DOM used by post-publication QA.
    const html=row.kind === 'article' && row.snapshot.seo_text
      ? await renderer(web+pathFor(row),{surface:'article'}) : await read(web+pathFor(row),fetcher);
    result[row.page_id]={id:native.id,flags:Object.fromEntries(['is_indexable','sitemap_eligible','llms_eligible','translation_group_id','source_locale'].filter(k=>Object.hasOwn(native,k)).map(k=>[k,native[k]])),dom:domOf(html)};
  }
  if (environment === 'staging' && result['PAGE-60']?.missing && result['PAGE-59']) result['PAGE-59'].counterpartWasMissing=true;
  return result;
}
function changedStrings(before,after) {
  if (typeof after === 'string') return before !== after ? [after] : [];
  if (!after || typeof after !== 'object') return [];
  return Object.entries(after).flatMap(([k,v])=>changedStrings(before?.[k],v));
}
// Match only the three documented field headings/prefixes removed by
// ContentPageTemplate. Ordinary headings and their reader copy remain required.
function readerLine(line, kind) {
  if (kind !== 'page') return line;
  if (/^\s*<!--[A-Za-z0-9:_ /-]+-->\s*$/.test(line)) return '';
  const markers = new Set(['content_md','content_html','visible_faq_items']);
  const normalize = value => value.trim().replace(/^[`*_]+|[`*_]+$/g,'').toLowerCase().replace(/[\s-]+/g,'_');
  const heading = /^\s*#{1,6}\s+(.+?)\s*$/.exec(line);
  if (heading && markers.has(normalize(heading[1]))) return '';
  const prefix = /^\s*([A-Za-z][A-Za-z0-9_-]*)\s*:\s*(.*)$/.exec(line);
  return prefix && markers.has(normalize(prefix[1])) ? prefix[2].trim() : line;
}
function internalTarget(href, environment) {
  try {
    const url = new URL(href,'https://fermatmind.com');
    if (url.protocol !== 'https:' || url.username || url.password
      || !['fermatmind.com','www.fermatmind.com',...(environment === 'staging' ? ['staging.fermatmind.com']:[])].includes(url.hostname)) return null;
    return url;
  } catch { return null; }
}
function readerVariants(line, row, dom, environment) {
  let variants = [plain(line)];
  // Bare internal paths are rendered as human anchor labels. Require the
  // corresponding visible anchor, its same public route, and every non-link word.
  const paths = [...new Set(variants[0].match(/(?<![A-Za-z0-9_./:@?=&%#-])\/(?:(?:zh|en)\/)?(?:articles|tests|science|career|personality|topics|method-boundaries|reliability-validity|item-design-notes|common-misconceptions|data-privacy|help|support|business)(?:\/[A-Za-z0-9._~:/?#@!$&'*+,;=%-]+)?/g) ?? [])]
    .map(path=>path.replace(/[.。．，,、；;：:！？!?）)\]】》>]+$/g,''));
  for (const path of paths) {
    const expected = internalTarget(path,environment);
    if (!expected) throw new Error(`PUBLIC_CONTENT_53_LINK_${row.page_id}`);
    const localized = /^\/(?:en|zh)(?:\/|$)/.test(expected.pathname) ? expected.pathname : `/${row.identity.locale === 'en' ? 'en' : 'zh'}${expected.pathname}`;
    const labels = Object.entries(dom.link_labels).flatMap(([href, values])=>{
      const actual = internalTarget(href,environment);
      return actual && [expected.pathname,localized].includes(actual.pathname) && actual.hash === expected.hash ? values : [];
    }).filter(label=>compact(label));
    if (!labels.length) throw new Error(`PUBLIC_CONTENT_53_LINK_${row.page_id}`);
    variants = variants.flatMap(text=>[...new Set(labels)].map(label=>text.replaceAll(path,label)));
    if (variants.length > 128) throw new Error(`PUBLIC_CONTENT_53_LINK_VARIANT_LIMIT_${row.page_id}`);
  }
  return variants;
}
export function assertDom(row,html,before,environment) {
  const dom=domOf(html); const body=compact(dom.body.join(' '));
  if (dom.body.length !== 1 || dom.h1.length !== 1 || !body) throw new Error(`PUBLIC_CONTENT_53_DOM_STRUCTURE_${row.page_id}`);
  if (row.kind !== 'surface' && row.snapshot.title && compact(dom.h1[0]) !== compact(row.snapshot.title)) throw new Error(`PUBLIC_CONTENT_53_H1_${row.page_id}`);
  if (row.kind === 'article' && row.snapshot.seo_text) {
    const seo=row.snapshot.seo_text;
    if (!equal(dom.title.map(compact),[compact(`${seo.seo_title} | FermatMind`)])
      || !equal(dom.description.map(compact),[compact(seo.seo_description)])
      || !equal(dom.og_title.map(compact),[compact(seo.og_title)])
      || !equal(dom.og_description.map(compact),[compact(seo.og_description)]))
      throw new Error(`PUBLIC_CONTENT_53_METADATA_${row.page_id}`);
    if (!Object.hasOwn(row.snapshot,'content_md') && (dom.article_body.length !== 1 || before.dom.article_body.length !== 1
      || !equal(dom.h1.map(compact),row.snapshot.title ? [compact(row.snapshot.title)] : before.dom.h1.map(compact)) || !equal(dom.article_body.map(compact),before.dom.article_body.map(compact))))
      throw new Error(`PUBLIC_CONTENT_53_BODY_DRIFT_${row.page_id}`);
  }
  if (row.kind === 'page' && Object.hasOwn(row.snapshot,'seo_title')) {
    const title = row.snapshot.seo_title || row.snapshot.title;
    const description = row.snapshot.meta_description || row.snapshot.summary;
    if (!equal(dom.title.map(compact),[compact(`${title} | FermatMind`)])
      || !equal(dom.description.map(compact),description ? [compact(description)] : []))
      throw new Error(`PUBLIC_CONTENT_53_METADATA_${row.page_id}`);
  }
  const translatedCategory = row.kind === 'surface' && ['PAGE-33','PAGE-34'].includes(row.page_id) && row.source_snapshot;
  if (translatedCategory) {
    const seo=row.snapshot.payload_json.seo;
    const h1=row.identity.slug === 'tests_category_career' ? 'Free career tests' : 'Free personality tests';
    if (!equal(dom.title.map(compact),[compact(`${seo.title} | FermatMind`)])
      || !equal(dom.description.map(compact),[compact(seo.description)]) || compact(dom.h1[0]) !== compact(h1))
      throw new Error(`PUBLIC_CONTENT_53_METADATA_${row.page_id}`);
  }
  const translatedTestsHub = row.kind === 'surface' && row.page_id === 'PAGE-32' && row.identity.slug === 'tests' && row.identity.locale === 'en' && row.source_snapshot;
  const alignmentTestsHub = row.kind === 'surface' && ['PAGE-61','PAGE-62'].includes(row.page_id) && row.identity.slug === 'tests';
  if (alignmentTestsHub) {
    const seo=row.snapshot.payload_json.seo;
    if (!equal(dom.title.map(compact),[compact(`${seo.title} | FermatMind`)])
      || !equal(dom.description.map(compact),[compact(seo.description)])
      || compact(dom.h1[0]) !== compact(row.snapshot.payload_json.hero.title)) throw new Error(`PUBLIC_CONTENT_53_METADATA_${row.page_id}`);
  }
  if (translatedTestsHub) {
    // TestsHubExperience renders family test cards. tests/page.tsx consumes
    // quickStart exclusively in its named ItemList JSON-LD, not visible prose.
    let list;
    try { if (dom.tests_quickstart_jsonld.length !== 1) throw new Error(); list=JSON.parse(dom.tests_quickstart_jsonld[0]); }
    catch { throw new Error('PUBLIC_CONTENT_53_QUICKSTART_JSONLD_PAGE-32'); }
    const items=row.snapshot.payload_json.quickStart.items;
    if (list?.['@type'] !== 'ItemList' || list.inLanguage !== 'en' || list.numberOfItems !== items.length || list.itemListElement?.length !== items.length)
      throw new Error('PUBLIC_CONTENT_53_QUICKSTART_JSONLD_PAGE-32');
    for (const [index,item] of items.entries()) {
      const entry=list.itemListElement[index], expected=internalTarget(item.href,environment), actual=internalTarget(entry?.url,environment);
      if (entry?.['@type'] !== 'ListItem' || entry.position !== index+1 || entry.name !== item.title || entry.description !== item.description
        || !expected || !actual || actual.pathname !== expected.pathname || actual.search !== expected.search || actual.hash !== expected.hash)
        throw new Error(`PUBLIC_CONTENT_53_QUICKSTART_JSONLD_PAGE-32_ITEM_${index+1}`);
    }
  }
  const home = row.kind === 'surface' && row.identity.slug === 'home';
  if (home) {
    // The homepage renders fixed explanatory cards, while CMS quickStart copy
    // feeds its ItemList. Verify both actual consumers rather than requiring
    // JSON-LD descriptions to appear as visible prose.
    let lists;
    try { lists=dom.home_jsonld.map(text=>JSON.parse(text)).filter(list=>list?.['@type'] === 'ItemList' && list['@id'] === 'https://fermatmind.com/en#quickstart-itemlist'); }
    catch { throw new Error('PUBLIC_CONTENT_53_HOME_JSONLD'); }
    const item=row.snapshot.payload_json.quickStart.items.find(item=>item.href.includes('iq-test-intelligence-quotient-assessment')),list=lists[0];
    if (lists.length !== 1 || list.inLanguage !== 'en' || !Array.isArray(list.itemListElement) || list.numberOfItems !== list.itemListElement.length)
      throw new Error('PUBLIC_CONTENT_53_HOME_JSONLD');
    // The existing visibility filter can omit other tests; bind the changed IQ
    // entry rather than requiring hidden products to reappear in this ItemList.
    const expected=internalTarget(item?.href,environment);
    if (expected && !expected.pathname.startsWith('/en/')) expected.pathname='/en'+expected.pathname;
    const matches=list.itemListElement.filter(entry=>internalTarget(entry?.url,environment)?.pathname === expected?.pathname);
    const entry=matches[0],actual=internalTarget(entry?.url,environment);
    if (!item || !expected || !actual || matches.length !== 1 || entry['@type'] !== 'ListItem' || !Number.isInteger(entry.position) || entry.position < 1
      || entry.name !== item.title || entry.description !== item.description || actual.search !== expected.search || actual.hash !== expected.hash)
      throw new Error('PUBLIC_CONTENT_53_HOME_JSONLD');
    const iq=compact(dom.home_iq_cards.join(' '));
    if (dom.home_iq_cards.length !== 1 || ![
      'Explore visual reasoning with 30 original matrix questions. See your correct-answer count and accuracy.',
      'The current FermatMind IQ test is an original 30-question visual matrix reasoning exercise. Review your performance on this form; it does not replace a professional intelligence assessment.',
      '30 questions','20 min',
    ].every(text=>iq.includes(compact(text)))) throw new Error('PUBLIC_CONTENT_53_HOME_IQ_CARD');
  }
  const copy=row.kind === 'guide' ? row.snapshot.body_md : row.snapshot.content_md;
  // Match the article reader's stripInternalCmsSlotMarkers. These are CMS
  // placement instructions, while the API still must retain exact package bytes.
  const readerCopy = row.kind === 'article' ? copy?.replace(/(?:<!--|&lt;!--)\s*(?:FM_SLOT|body_visual):[\s\S]*?(?:-->|--&gt;)/gi,'') : copy;
  const lines = readerCopy ? readerCopy.split('\n').map(line=>readerLine(line,row.kind)).filter(line=>line.trim() && !/^[\s|:-]+$/.test(line)).flatMap(line=>line.split('|'))
    : row.kind === 'profile' ? row.snapshot.sections.flatMap(s=>[s.title,s.body_md]).filter(Boolean)
      : translatedTestsHub || alignmentTestsHub ? changedStrings(row.before.payload_json.families,row.snapshot.payload_json.families)
        : translatedCategory ? ['hero','featured','allTests','differences','trust','resources','breadcrumb']
            .flatMap(key=>changedStrings(row.before.payload_json[key],row.snapshot.payload_json[key]))
          : home ? []
          : row.snapshot.seo_text ? [] : changedStrings(row.before,row.snapshot).filter(s=>!s.startsWith('/') && !s.startsWith('https://'));
  for (const [index, line] of lines.entries()) {
    const text=compact(plain(line));
    if (text && !body.includes(text) && !readerVariants(line,row,dom,environment).some(variant=>body.includes(compact(variant))))
      throw new Error(`PUBLIC_CONTENT_53_VISIBLE_BODY_${row.page_id}_ITEM_${index + 1}`);
  }
  const initializedPair = environment === 'staging' && ((row.page_id === 'PAGE-60' && before.missing === true && row.staging_bootstrap)
    || (row.page_id === 'PAGE-59' && before.counterpartWasMissing === true));
  if (dom.canonical.length !== 1 || (!before.missing && (!equal(dom.canonical,before.dom.canonical) || !equal(dom.robots,before.dom.robots)))
    || (!initializedPair && !equal(dom.alternates,before.dom.alternates))) throw new Error(`PUBLIC_CONTENT_53_DISCOVERABILITY_DRIFT_${row.page_id}`);
  if (initializedPair) {
    const paths=['/en/articles/ocean-traits-what-the-five-letters-mean','/zh/articles/ocean-traits-what-the-five-letters-mean'];
    if (dom.robots.length !== 1 || !dom.robots.every(value=>/^index,\s*follow$/i.test(value)) || !paths.every(path=>dom.alternates.some(href=>internalTarget(href,environment)?.pathname === path))
      || dom.alternates.some(href=>!paths.includes(internalTarget(href,environment)?.pathname))) throw new Error(`PUBLIC_CONTENT_53_DISCOVERABILITY_DRIFT_${row.page_id}`);
  }
  const path=pathFor(row);const url=new URL(dom.canonical[0]);
  if (url.protocol !== 'https:' || !['fermatmind.com','www.fermatmind.com',...(environment === 'staging' ? ['staging.fermatmind.com']:[])].includes(url.hostname)
    || url.pathname !== path || url.search || url.hash) throw new Error(`PUBLIC_CONTENT_53_CANONICAL_${row.page_id}`);
  for (const link of copy?.matchAll(/\[[^\]]+\]\((\/(?:en|zh)\/[^)]+)\)/g) ?? []) {
    const target=new URL(link[1],'https://fermatmind.com');
    if (target.pathname.includes('\\') || !dom.links.some(href=>{try{return internalTarget(href,environment)?.pathname === target.pathname;}catch{return false;}})) throw new Error(`PUBLIC_CONTENT_53_LINK_${row.page_id}`);
  }
  if (row.page_id === 'PAGE-37') {
    const cta=dom.links.map(href=>new URL(href,'https://fermatmind.com')).find(u=>u.searchParams.get('cta_id') === 'start_test');
    if (!cta || cta.pathname !== '/en/tests/big-five-personality-test-ocean-model' || cta.searchParams.get('test_slug') !== 'big-five-personality-test-ocean-model'
      || cta.searchParams.get('target_test_slug') !== 'big-five-personality-test-ocean-model' || cta.searchParams.get('source_slug') !== 'big-five') throw new Error('PUBLIC_CONTENT_53_BIG5_TRACKING');
  }
}
export async function verifyOnlineCandidates(environment,backendRoot,fetcher=fetch,renderer=renderPage,prestate) {
  const [api,web]=hosts(environment);if(!prestate) throw new Error('PUBLIC_CONTENT_53_PRESTATE_REQUIRED');
  const rows = readCandidateRows(backendRoot).candidates.map(row=>environment === 'staging' && row.staging_snapshot ? {...row,snapshot:row.staging_snapshot} : row);
  for (const row of rows) {
    const data=parseApi(await read(api+apiPath(row),fetcher));const before=prestate[row.page_id];const native=data[row.kind];
    const createdStaging = environment === 'staging' && row.page_id === 'PAGE-60' && row.staging_bootstrap && before?.missing === true;
    if(!before || !equal(projection(row,data),row.snapshot)
      || (createdStaging ? native.is_indexable !== row.staging_bootstrap.is_indexable || native.translation_group_id !== row.staging_source_identity.translation_group_id
        : native.id !== before.id || Object.entries(before.flags).some(([k,v])=>!equal(native[k],v)))) throw new Error(`PUBLIC_CONTENT_53_NATIVE_READBACK_${row.page_id}`);
    // One publication only. Existing 300-second public fetch caches can settle
    // while exact candidate DOM assertions remain strict and bounded.
    const deadline=Date.now()+360000;let last;
    while (true) {
      try { const verifyDOM=html=>assertDom(row,html,before,environment);verifyDOM(await renderer(web+pathFor(row),{surface:'public_page',verifyDOM}));break; }
      catch(error) {last=error?.message?.startsWith('PUBLIC_CONTENT_53_') ? error : new Error(`PUBLIC_CONTENT_53_BROWSER_FAILED_${row.page_id}`);if(Date.now()>=deadline) throw last;await new Promise(resolve=>setTimeout(resolve,10000));}
    }
  }
  return {environment,api_readback_count:rows.length,dom_readback_count:rows.length};
}
