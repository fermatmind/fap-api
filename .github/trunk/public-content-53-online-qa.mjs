import { readMbtiResultCopySample } from './mbti-result-copy-online-qa.mjs';
import { execFileSync } from 'node:child_process';
import { canonical } from './iq-public-scale-package.mjs';
import { readCandidateRows } from './public-content-53-package.mjs';
import { renderPage } from './iq-public-article-online-qa.mjs';

const equal = (a,b) => JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));
const compact = x => String(x ?? '').normalize('NFKC').replace(/[\s\p{P}\p{S}]/gu,'');
// Mirror fap-web's resolveCareerGuideMetadataTitle: a CMS-owned brand suffix
// is normalized once instead of receiving the locale layout suffix again.
const guideDocumentTitle = sourceTitle => {
  const title=sourceTitle.trim();
  return /[|｜]\s*(?:FermatMind|费马测试)\s*$/i.test(title)
    ? title.replace(/(?:\s*[|｜]\s*(?:FermatMind|费马测试))+\s*$/i,' | FermatMind')
    : `${sourceTitle} | FermatMind`;
};
const plain = x => /^\s*```[\w-]*\s*$/.test(x) ? '' : x.replace(/!\[[^\]]*\]\([^)]*\)/g,'').replace(/\[([^\]]+)\]\([^)]*\)/g,'$1')
  .replace(/^\s*\[\^([0-9A-Za-z-]+)\]:\s*/,'$1 ').replace(/\[\^([0-9A-Za-z-]+)\]/g,'$1')
  .replace(/^\s*(?:>\s*)+|^\s*(?:#{1,6}\s*|[-*+]\s+|\d+[.)]\s+)/g,'').replace(/[*`_]/g,'');
export const pathFor = row => {
  const {slug,locale} = row.identity; const segment = locale === 'zh-TW' ? 'zh-TW' : locale === 'en' ? 'en' : 'zh';
  if (row.kind === 'guide') return `/${segment}/career/guides/${slug}`;
  if (row.kind === 'article') return `/${segment}/articles/${slug}`;
  if (row.kind === 'profile') return `/${segment}/topics/${slug}`;
  if (row.new_traditional_blog) return '/zh-TW/articles';
  if (row.kind === 'surface') return slug === 'home' ? `/${segment}` : `/${segment}/tests${slug === 'tests' ? '' : '/category/'+slug.replace('tests_category_','')}`;
  return `/${segment}/${slug.startsWith('help-') ? 'help/'+slug.slice(5) : slug}`;
};
const hosts = env => {
  if (!['staging','production'].includes(env)) throw new Error('PUBLIC_CONTENT_53_ENV_INVALID');
  return env === 'staging' ? ['https://staging-api.fermatmind.com','https://staging.fermatmind.com'] : ['https://api.fermatmind.com','https://fermatmind.com'];
};
export async function read(url, fetcher, allowStagingBootstrap404 = false, headers = {}) {
  let response, bytes;
  try { response = await fetcher(url, {redirect:'error',signal:AbortSignal.timeout(15000),headers:{'Cache-Control':'no-cache',...headers}}); }
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
export function domOf(html, {articleSsr=false}={}) {
  const code = String.raw`
$doc = new DOMDocument; libxml_use_internal_errors(true);
if (!$doc->loadHTML(stream_get_contents(STDIN), LIBXML_NONET)) throw new RuntimeException('invalid DOM');
$xp = new DOMXPath($doc);
// A completed Next.js stream still contains its loading main and a hidden
// React transport segment. Raw acceptance selects the real Article subtree;
// the separate browser pass retains the unique visible-main requirement.
$mainQuery = $argv[1] === 'article_ssr'
 ? '//main[not(ancestor::main) and .//article[@data-testid="article-detail-content"]]'
 : '//main[not(ancestor::main)]';
$walk = function($node) use (&$walk) {
 if ($node instanceof DOMElement && (in_array($node->tagName,['script','style','template','noscript']) || $node->getAttribute('data-iq-qa-hidden') === 'true' || $node->hasAttribute('hidden') || $node->getAttribute('aria-hidden') === 'true')) return '';
 if ($node instanceof DOMText) return $node->nodeValue;
 $text=''; foreach($node->childNodes as $child) $text .= ' '.$walk($child); return $text;
};
$labels=[]; foreach($xp->query($mainQuery.'//a') as $anchor) {
 $hidden=false;
 // Visibility inside the selected reader matters; raw Article transport
 // outside its main is verified by the separate browser acceptance.
 for($parent=$anchor;$parent instanceof DOMElement;$parent=$parent->parentNode) {
  if ($parent->hasAttribute('hidden') || $parent->getAttribute('aria-hidden') === 'true' || $parent->getAttribute('data-iq-qa-hidden') === 'true') { $hidden=true; break; }
  if ($parent->tagName === 'main') break;
 }
 if ($hidden) continue;
 $label=$walk($anchor); if (trim($label)!=='') $labels[$anchor->getAttribute('href')][]=$label;
}
$values = function($query,$attr=null) use($xp,$walk) { $out=[];foreach($xp->query($query) as $node) $out[]=$attr===null?$walk($node):$node->getAttribute($attr);return $out;};
$documentTitles=[]; foreach($xp->query('/html/head/title | /html/body/title') as $node) $documentTitles[]=$node->textContent;
$alternateEntries=[]; $canonicalLinks=[]; $alternateLinks=[];
foreach($xp->query('//link') as $node) {
 $rel=preg_split('/\s+/',strtolower(trim($node->getAttribute('rel'))));
 if (in_array('canonical',$rel,true)) $canonicalLinks[]=$node->getAttribute('href');
 if (in_array('alternate',$rel,true)) {
  $alternateLinks[]=$node->getAttribute('href');
  $alternateEntries[]=['href'=>$node->getAttribute('href'),'type'=>$node->getAttribute('type'),'hreflang'=>$node->getAttribute('hreflang')];
 }
}
$quickstart=[]; foreach($xp->query('//script[@type="application/ld+json" and @id="tests-hub-quickstart-en"]') as $node) $quickstart[]=$node->textContent;
$homeJsonLd=[]; foreach($xp->query('//script[@type="application/ld+json"]') as $node) $homeJsonLd[]=$node->textContent;
echo json_encode(['language'=>$doc->documentElement->getAttribute('lang'),'link_labels'=>$labels,'body'=>$values($mainQuery),'h1'=>$values($mainQuery.'//h1'),'links'=>$values($mainQuery.'//a','href'),
 'home_jsonld'=>$homeJsonLd,'home_iq_cards'=>$values('//main//article[.//a[contains(@href,"iq-test-intelligence-quotient-assessment")]]'),
 'tests_quickstart_jsonld'=>$quickstart,'article_body'=>$values('//main//article[@data-testid="article-detail-content"]'),
 'article_images'=>$values('//main//article[@data-testid="article-detail-content"]//img[normalize-space(@alt)!=""]','src'),
 'blog_featured_links'=>$values($mainQuery.'//*[@data-testid="articles-featured-grid"]//article[@data-article-layout="featured"]/a','href'),
 'h1_names'=>$values($mainQuery.'//h1','aria-label'),'title'=>$documentTitles,'description'=>$values('//meta[@name="description"]','content'),
 'og_title'=>$values('//meta[@property="og:title"]','content'),'og_description'=>$values('//meta[@property="og:description"]','content'),
 'canonical'=>$canonicalLinks,'robots'=>$values('//meta[@name="robots"]','content'),
 'alternates'=>$alternateLinks,'alternate_entries'=>$alternateEntries],JSON_THROW_ON_ERROR);`;
  return JSON.parse(execFileSync('php',['-r',code,articleSsr?'article_ssr':'dom'],{input:html,encoding:'utf8',maxBuffer:3000000,timeout:15000,stdio:['pipe','pipe','pipe']}));
}
export function projection(row,data) {
  const value = data[row.kind];
  if (data.ok !== true || !value || value.locale !== row.identity.locale || (['article','profile','guide'].includes(row.kind) && value.org_id !== 0)
    || (value.slug ?? value.surface_key) !== row.identity.slug || (row.kind !== 'guide' && value.status !== 'published') || value.is_public !== true) throw new Error('PUBLIC_CONTENT_53_IDENTITY_DRIFT');
  if (row.kind === 'guide' && value.guide_code !== row.source_identity.guide_code) throw new Error('PUBLIC_CONTENT_53_IDENTITY_DRIFT');
  const result = Object.fromEntries(Object.keys(row.snapshot).filter(key=>Object.hasOwn(value,key)).map(key=>[key,value[key]]));
  if (row.kind === 'surface' && row.snapshot.block_copy) {
    result.block_copy=Object.fromEntries(Object.keys(row.snapshot.block_copy).map(key=>{
      const blocks=(value.page_blocks ?? []).filter(block=>block.block_key === key && block.is_enabled === true);
      if(blocks.length !== 1) throw new Error('PUBLIC_CONTENT_53_BLOCK_IDENTITY_DRIFT');
      return [key,blocks[0].payload_json];
    }));
  }
  if (row.kind === 'guide' && row.snapshot.seo_text) result.seo_text=Object.fromEntries(Object.keys(row.snapshot.seo_text).map(key=>[key,data.seo_meta?.[key]]));
  if (row.kind === 'article' && row.snapshot.seo_text) result.seo_text=Object.fromEntries(Object.keys(row.snapshot.seo_text).filter(key=>Object.hasOwn(value.seo_meta ?? {},key)).map(key=>[key,value.seo_meta[key]]));
  if (row.kind === 'article' && row.snapshot.editorial_projection) {
    result.editorial_projection = {
      faq: data.answer_surface_v1?.faq_blocks ?? [],
      ...(Object.hasOwn(row.snapshot.editorial_projection, 'ctas') ? {ctas: data.landing_surface_v1?.cta_bundle ?? []} : {}),
    };
  }
  if (row.kind === 'profile') Object.assign(result,{sections:data.sections,entry_groups:data.entry_groups,seo_text:{title:data.seo_meta?.seo_title,description:data.seo_meta?.seo_description}});
  return result;
}
const apiPath = row => `/api/v0.5/${{article:'articles',page:'content-pages',surface:'landing-surfaces',profile:'topics',guide:'career-guides'}[row.kind]}/${row.identity.slug}?locale=${row.identity.locale}&org_id=0`;
function assertOriginalArticleApi(row,data,environment,parent) {
  const source=row.staging_article_original;
  const article=data.article;
  const seo=article?.seo_meta;
  const fields=['title','excerpt','content_md'];
  const controls=['is_indexable','sitemap_eligible','llms_eligible'];
  const expectedParent=environment === 'staging' && source.identity.source_article_id !== null ? parent : source.identity.source_article_id;
  if (data.ok !== true || !article || article.org_id !== 0 || article.slug !== row.identity.slug
    || article.locale !== row.identity.locale || article.status !== 'published' || article.is_public !== true
    || article.translation_group_id !== source.identity.translation_group_id || article.source_locale !== source.identity.source_locale
    || !Number.isInteger(article.published_revision_id) || article.source_article_id !== expectedParent
    || (environment === 'production' && (article.id !== source.identity.id || article.published_revision_id !== source.identity.published_revision_id))
    || !controls.every(key=>article[key] === (environment === 'staging' ? false : source.article[key]))
    || fields.some(key=>article[key] !== source.published[key]) || article.author_name !== source.article.author_name
    || article.cover_image_alt !== source.article.cover_image_alt
    || ['og_title','og_description','og_image_url'].some(key=>source.seo[key] !== null && seo?.[key] !== source.seo[key])
    || (article.category?.slug ?? null) !== (source.taxonomy.category?.slug ?? null)
    || !equal((article.tags ?? []).map(tag=>tag.slug).sort(),source.taxonomy.tags.map(tag=>tag.slug).sort())
    || !equal(data.answer_surface_v1?.faq_blocks ?? [],source.public_projection.faq)
    || !equal(data.landing_surface_v1?.cta_bundle ?? [],source.public_projection.ctas)) {
    throw new Error('PUBLIC_CONTENT_53_STAGING_ARTICLE_ORIGINAL_API');
  }
  return {id:article.id,published_revision_id:article.published_revision_id};
}
async function renderPrestate(row,url,renderer) {
  // This runs before publication transport. A failed isolated browser may be
  // read once more; no API, native write, DOM assertion or recovery is retried.
  for (let attempt=0;attempt<2;attempt++) {
    try { return await renderer(url,{surface:'article'}); }
    catch (error) {
      if (error?.message !== 'IQ_ARTICLE_ONLINE_RENDER_FAILED') throw error;
      if (attempt === 1) throw new Error(`PUBLIC_CONTENT_53_PRESTATE_BROWSER_FAILED_${row.page_id}`);
    }
  }
}
export async function readOnlinePrestate(environment,backendRoot,fetcher=fetch,renderer=renderPage,packageSha256) {
  const [api,web] = hosts(environment); const result={};
  const rows = readCandidateRows(backendRoot,packageSha256,environment).candidates.map(row=>environment === 'staging' && row.staging_snapshot ? {...row,snapshot:row.staging_snapshot} : row);
  for (const row of rows) {
    if (row.staging_article_original) {
      const bytes=await read(api+apiPath(row),fetcher,environment === 'staging');
      if (environment === 'staging') {
        if (bytes !== null) throw new Error('PUBLIC_CONTENT_53_STAGING_ARTICLE_ORIGINAL_PRESTATE');
        result[row.page_id]={missing:true};
      } else {
        const native=assertOriginalArticleApi(row,parseApi(bytes),environment);
        const html=await read(web+pathFor(row),fetcher,false,{'User-Agent':'facebookexternalhit/1.1'});
        const {canonical,robots,alternates}=domOf(html,{articleSsr:true});
        result[row.page_id]={...native,dom:{canonical,robots,alternates}};
      }
      continue;
    }
    if (row.kind === 'mbti-result') {
      if (row.page_id.endsWith('-NATIVE-LEAVES') && row.identity.slug !== 'intj-t') {
        result[row.page_id] = {native_adapter_phase_only:true};
        continue;
      }
      result[row.page_id] = await readMbtiResultCopySample(environment,row,fetcher,renderer);
      continue;
    }
    const bytes=await read(api+apiPath(row),fetcher,row.new_article === true || row.new_traditional_article === true || row.new_traditional_page === true || row.new_traditional_landing === true || (environment === 'staging' && row.page_id === 'PAGE-60' && !!row.staging_bootstrap));
    if (bytes === null) { result[row.page_id]={missing:true};continue; }
    const data=parseApi(bytes);const value=projection(row,data);
    if (!equal(value,environment === 'staging' ? row.staging_before : row.before) && !equal(value,row.snapshot)) throw new Error(`PUBLIC_CONTENT_53_PRESTATE_DRIFT_${row.page_id}`);
    const native=data[row.kind];
    // Metadata-only Article updates compare the unchanged reader body before and
    // after publication. Raw Next.js streams can contain only its initial chunk;
    // capture the same hydrated, visibility-aware DOM used by post-publication QA.
    const html=row.kind === 'article' && row.snapshot.seo_text
      ? await renderPrestate(row,web+pathFor(row),renderer) : await read(web+pathFor(row),fetcher);
    let dom;
    try { dom=domOf(html); }
    catch { throw new Error(`PUBLIC_CONTENT_53_PRESTATE_DOM_FAILED_${row.page_id}`); }
    result[row.page_id]={id:native.id,flags:Object.fromEntries(['is_indexable','sitemap_eligible','llms_eligible','translation_group_id','source_locale'].filter(k=>Object.hasOwn(native,k)).map(k=>[k,native[k]])),dom};
  }
  if (environment === 'staging' && result['PAGE-60']?.missing) {
    if (!result['PAGE-59']?.dom) throw new Error('PUBLIC_CONTENT_53_STAGING_REFERENCE_REQUIRED');
    result['PAGE-60'].stagingReferenceDom=result['PAGE-59'].dom;
  }
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
  const control = line.trim();
  if (control.startsWith('<!--') && control.endsWith('-->')
    && /^[A-Za-z0-9:_ /-]+$/.test(control.slice(4,-3))) return '';
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
function readerPaths(text) {
  return [...new Set(text.match(/(?<![A-Za-z0-9_./:@?=&%#-])\/(?:(?:zh|en)\/)?(?:articles|tests|science|career|personality|topics|method-boundaries|reliability-validity|item-design-notes|common-misconceptions|data-privacy|help|support|business)(?:\/[A-Za-z0-9._~:/?#@!$&'*+,;=%-]+)?/g) ?? [])]
    .map(path=>path.replace(/[.。．，,、；;：:！？!?）)\]】》>]+$/g,''));
}
function readerVariants(line, row, dom, environment) {
  let variants = [plain(line)];
  // Bare internal paths are rendered as human anchor labels. Require the
  // corresponding visible anchor, its same public route, and every non-link word.
  const paths = readerPaths(variants[0]);
  for (const path of paths) {
    const expected = internalTarget(path,environment);
    if (!expected) throw new Error(`PUBLIC_CONTENT_53_LINK_${row.page_id}`);
    // This source alias is redirect-only; the reader emits the same canonical scale.
    const canonicalPath = row.new_traditional_page && expected.pathname === '/tests/big-five-personality-test'
      ? '/tests/big-five-personality-test-ocean-model' : expected.pathname;
    const localized = /^\/(?:en|zh)(?:\/|$)/.test(canonicalPath) ? canonicalPath : `/${row.identity.locale === 'en' ? 'en' : 'zh'}${canonicalPath}`;
    const destinations = row.new_traditional_page && canonicalPath.startsWith('/tests/') ? [localized] : [canonicalPath, localized];
    if (row.new_traditional_page && !/^\/(?:en|zh|zh-TW)(?:\/|$)/.test(canonicalPath)) destinations.push('/zh-TW' + canonicalPath);
    const labels = Object.entries(dom.link_labels).flatMap(([href, values])=>{
      const actual = internalTarget(href,environment);
      return actual && destinations.includes(actual.pathname) && actual.hash === expected.hash && (!row.new_traditional_page || actual.search === expected.search) ? values : [];
    }).filter(label=>compact(label));
    if (!labels.length) throw new Error(`PUBLIC_CONTENT_53_LINK_${row.page_id}`);
    variants = variants.flatMap(text=>[...new Set(labels)].map(label=>text.replaceAll(path,label)));
    if (variants.length > 128) throw new Error(`PUBLIC_CONTENT_53_LINK_VARIANT_LIMIT_${row.page_id}`);
  }
  return variants;
}
export function assertDom(row,html,before,environment, {articleSsr=false}={}) {
  const reviewedBefore=environment === 'staging' ? (row.staging_before ?? row.before) : row.before;
  const dom=domOf(html,{articleSsr}); const body=compact(dom.body.join(' '));
  if (dom.body.length !== 1 || dom.h1.length !== 1 || !body) throw new Error(`PUBLIC_CONTENT_53_DOM_STRUCTURE_${row.page_id}`);
  if (row.new_traditional_blog) {
    const path=pathFor(row);
    const categories=row.snapshot.payload_json.blog_v1.categories;
    const expectedLinks=[...row.blog_article_slugs.map(slug=>path+'/'+slug), ...categories.map(category=>path+'/category/'+category.slug)];
    if (dom.language !== 'zh-TW' || compact(dom.h1[0]) !== compact(row.snapshot.title)
      || !equal(dom.title.map(compact),[compact(row.snapshot.title+' | 費馬測試')])
      || !equal(dom.description.map(compact),[compact(row.snapshot.description)])
      || !equal(dom.canonical,['https://fermatmind.com'+path])
      || !dom.robots.some(value=>/\bnoindex\b/i.test(value))
      || dom.alternate_entries.some(entry=>entry.hreflang)
      || !body.includes(compact(row.snapshot.description))
      || categories.some(category=>!body.includes(compact(category.name)))
      || expectedLinks.some(href=>!dom.links.includes(href))
      || (row.featured_targets && !equal(dom.blog_featured_links,row.featured_targets.map(target=>path+'/'+target.slug)))) {
      throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_BLOG_DOM_${row.page_id}`);
    }
    return;
  }
  if (row.new_traditional_landing) {
    const copy = row.snapshot.payload_json;
    const slug = row.identity.slug;
    const path = pathFor(row);
    const h1 = {home:'看清自己，走好每一步', tests_category_personality:'免費人格測驗', tests_category_career:'免費職業測驗'}[slug] ?? copy.hero.title;
    const readerCopy = slug === 'home' ? ['費馬測試把自我認知','免費測驗','120 萬','5000 萬','真實伺服器資料統計']
      : slug === 'tests' ? ['互動故事','主要測評']
      : [copy.hero.body,copy.featured.title,copy.allTests.title,copy.differences.title,copy.trust.title,copy.resources.title];
    const originalPath = path.slice('/zh-TW'.length);
    const originalLinks = [originalPath ? '/zh'+originalPath : '/', '/en'+originalPath];
    const entryLinks = slug === 'tests'
      ? ['/zh-TW/tests/category/personality','/zh-TW/tests/category/career','/zh-TW/tests/explore/chiikawa']
      : slug === 'home' ? ['/zh-TW/foundation','/zh-TW/method-boundaries','/zh-TW/about'] : ['/zh-TW/tests'];
    const title = slug === 'home' ? copy.seo.title : copy.seo.title+' | 費馬測試';
    const noindex = dom.robots.some(value=>/\bnoindex\b/i.test(value));
    const indexable = row.traditional_landing_indexable === true;
    if (dom.language !== 'zh-TW' || compact(dom.h1_names[0] || dom.h1[0]) !== compact(h1)
      || !equal(dom.title.map(compact),[compact(title)])
      || !equal(dom.description.map(compact),[compact(copy.seo.description)])
      || !equal(dom.canonical,['https://fermatmind.com'+path])
      || (!indexable && !noindex) || (indexable && environment === 'production' && noindex)
      || dom.alternate_entries.some(item=>item.hreflang)
      || readerCopy.some(text=>!body.includes(compact(text)))
      || [...originalLinks,...entryLinks].some(href=>!dom.links.includes(href))) {
      throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_LANDING_DOM_${row.page_id}`);
    }
    return;
  }
  if (row.kind !== 'surface' && row.snapshot.title && compact(dom.h1[0]) !== compact(row.snapshot.title)) throw new Error(`PUBLIC_CONTENT_53_H1_${row.page_id}`);
  if (['article','guide'].includes(row.kind) && row.snapshot.seo_text) {
    const seo=row.snapshot.seo_text;
    // ArticleSeoService projects Big Five SEO titles without a trailing brand.
    // The Traditional page then adds its layout brand only to the document title.
    const publicTitle = title => row.new_traditional_article && row.identity.slug.startsWith('big-five-')
      ? (title.trim().replace(/(?:\s*\|\s*FermatMind)+\s*$/iu, '').trim() || title.trim()) : title;
    const documentTitle = row.kind === 'guide' ? guideDocumentTitle(seo.seo_title)
      : `${publicTitle(seo.seo_title)} | ${row.new_traditional_article ? '費馬測試' : 'FermatMind'}`;
    if (!equal(dom.title.map(compact),[compact(documentTitle)])
      || !equal(dom.description.map(compact),[compact(seo.seo_description)])
      || !equal(dom.og_title.map(compact),[compact(publicTitle(seo.og_title))])
      || !equal(dom.og_description.map(compact),[compact(seo.og_description)]))
      throw new Error(`PUBLIC_CONTENT_53_METADATA_${row.page_id}`);
    if (row.kind === 'article' && !Object.hasOwn(row.snapshot,'content_md') && (dom.article_body.length !== 1 || before.dom.article_body.length !== 1
      || !equal(dom.h1.map(compact),row.snapshot.title ? [compact(row.snapshot.title)] : before.dom.h1.map(compact)) || !equal(dom.article_body.map(compact),before.dom.article_body.map(compact))))
      throw new Error(`PUBLIC_CONTENT_53_BODY_DRIFT_${row.page_id}`);
  }
  if (row.kind === 'page' && Object.hasOwn(row.snapshot,'seo_title')) {
    const title = row.snapshot.seo_title || row.snapshot.title;
    const description = row.snapshot.meta_description || row.snapshot.summary;
    const brand = row.new_traditional_page ? '費馬測試' : 'FermatMind';
    if (!equal(dom.title.map(compact),[compact(`${title} | ${brand}`)])
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
      : translatedTestsHub || alignmentTestsHub ? changedStrings(reviewedBefore.payload_json.families,row.snapshot.payload_json.families)
        : translatedCategory ? ['hero','featured','allTests','differences','trust','resources','breadcrumb']
            .flatMap(key=>changedStrings(reviewedBefore.payload_json[key],row.snapshot.payload_json[key]))
          : home ? []
          : row.snapshot.seo_text ? [] : changedStrings(reviewedBefore,row.snapshot).filter(s=>!s.startsWith('/') && !s.startsWith('https://'));
  for (const [index, line] of lines.entries()) {
    if (row.new_traditional_page) {
      // A visible label alone cannot prove a reviewed Markdown destination.
      for (const [, label, href] of line.matchAll(/\[([^\[\]\n]+)\]\(([^()\s]+)\)/g)) {
        if (!(dom.link_labels[href] ?? []).some(value=>compact(value) === compact(plain(label))))
          throw new Error(`PUBLIC_CONTENT_53_LINK_${row.page_id}`);
      }
    }
    const text=compact(plain(line));
    const linked = row.new_traditional_page && readerPaths(plain(line)).length > 0;
    if (text && (linked || !body.includes(text)) && !readerVariants(line,row,dom,environment).some(variant=>body.includes(compact(variant))))
      throw new Error(`PUBLIC_CONTENT_53_VISIBLE_BODY_${row.page_id}_ITEM_${index + 1}`);
  }
  const initializedStaging = environment === 'staging' && row.page_id === 'PAGE-60' && before.missing === true && row.staging_bootstrap;
  const newArticle = row.new_article === true;
  const newTraditionalPage = row.new_traditional_page === true;
  if (newTraditionalPage) {
    const robots=dom.robots.join(',').toLowerCase().split(',').map(value=>value.trim());
    let schemas;
    try { schemas=dom.home_jsonld.map(bytes=>JSON.parse(bytes)); }
    catch { throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_PAGE_SCHEMA_${row.page_id}`); }
    const url='https://fermatmind.com'+pathFor(row);
    const webPage=schemas.some(node=>node['@type'] === 'WebPage' && node.url === url && node.inLanguage === 'zh-TW'
      && node.name === row.snapshot.title && node.description === (row.snapshot.summary ?? ''));
    const breadcrumb=schemas.some(node=>node['@type'] === 'BreadcrumbList' && node.itemListElement?.at(-1)?.item === url);
    if (dom.language !== 'zh-TW' || !equal(dom.canonical,['https://fermatmind.com'+pathFor(row)]) || !robots.includes('noindex') || robots.includes('index')
      || dom.alternate_entries.some(entry=>entry.hreflang)
      || !webPage || !breadcrumb || (row.identity.slug === 'about' && !['創始人與博士顧問團隊','劉福威','賈睿璇','李琛'].every(text=>body.includes(compact(text)))))
      throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_PAGE_SEO_OR_TEAM_${row.page_id}`);
  }
  if (row.new_traditional_article) {
    const robots=dom.robots.join(',').toLowerCase().split(',').map(value=>value.trim());
    if (dom.language !== 'zh-TW' || !equal(dom.canonical,['https://fermatmind.com'+pathFor(row)])
      || !robots.includes('noindex') || robots.includes('index') || dom.alternate_entries.some(entry=>entry.hreflang))
      throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_ARTICLE_SEO_${row.page_id}`);
    if (row.snapshot.body_visual && !dom.article_images.includes(row.snapshot.body_visual.image_url))
      throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_ARTICLE_BODY_VISUAL_${row.page_id}`);
    if (row.source_identity?.source_locale === 'en') {
      const sourcePath='/en/articles/'+row.source_expectations.source.slug;
      const testSlug=row.article_metadata?.related_test_slug;
      if (!(dom.link_labels[sourcePath] ?? []).some(label=>compact(label) === 'English')
        || (testSlug && !(dom.link_labels['/en/tests/'+testSlug] ?? []).some(label=>compact(label) === '開始測驗')))
        throw new Error('PUBLIC_CONTENT_53_TRADITIONAL_ENGLISH_READER_LINK');
    }
    if (row.source_expectations?.source_snapshot_kind === 'published_projection' && !row.editorial_package_v1?.cta_slots?.length) {
      // A related-test identifier can be present without a published CTA.
      const related = row.article_metadata?.related_test_slug;
      const assessments = row.reviewed_default_assessment_ctas ?? (related ? ['/zh/tests/'+related] : []);
      if (assessments.some(href=>!(dom.link_labels[href] ?? []).some(label=>compact(label) === '開始測驗')))
        throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_ARTICLE_ASSESSMENT_LINK_${row.page_id}`);
    }
    const editorial = row.snapshot.editorial_projection;
    if (editorial && (editorial.faq.some(({question})=>!body.includes(compact(question)) && !body.includes(compact(plain(question))))
      || editorial.ctas?.some(({href,label})=>!(dom.link_labels[href] ?? []).some(value=>compact(value) === compact(label)))))
      throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_ARTICLE_EDITORIAL_DOM_${row.page_id}`);
  }
  if (newArticle) {
    const languages=dom.alternate_entries.filter(entry=>entry.hreflang).map(entry=>[entry.hreflang,entry.href]).sort();
    const expected=[['en',`https://fermatmind.com/en/articles/${row.identity.slug}`],['zh-CN',`https://fermatmind.com/zh/articles/${row.identity.slug}`],['x-default',`https://fermatmind.com/en/articles/${row.identity.slug}`]].sort();
    const robots=dom.robots.join(',').toLowerCase().split(',').map(x=>x.trim());
    const nodes=[];
    const walk=value=>{if(!value || typeof value !== 'object')return;if(!Array.isArray(value) && Object.hasOwn(value,'@type'))nodes.push(value);for(const child of Object.values(value))walk(child);};
    for(const bytes of dom.home_jsonld) {try {walk(JSON.parse(bytes));} catch {throw new Error(`PUBLIC_CONTENT_53_NEW_ARTICLE_SEO_${row.page_id}`);}}
    const typed=(node,type)=>[node['@type']].flat().includes(type);
    const identity=value=>typeof value === 'string'?value:value?.['@id'];
    const url='https://fermatmind.com'+pathFor(row);
    const article=nodes.some(node=>typed(node,'Article') && node.url === url && identity(node.mainEntityOfPage) === url && node['@id'] === url+'#article' && compact(node.headline) === compact(row.snapshot.seo_text.seo_title));
    const breadcrumb=nodes.some(node=>typed(node,'BreadcrumbList') && Array.isArray(node.itemListElement) && identity(node.itemListElement.at(-1)?.item) === url);
    if (!equal(languages,expected) || (environment === 'production' ? !robots.includes('index') || robots.includes('noindex') : !robots.includes('noindex'))
      || !article || !breadcrumb) throw new Error(`PUBLIC_CONTENT_53_NEW_ARTICLE_SEO_${row.page_id}`);
  }
  const reviewedHreflang = row.enable_hreflang === true;
  if (reviewedHreflang) {
    const aiPair=['PAGE-AI-ZH','PAGE-17'].includes(row.page_id) && row.identity.slug === 'how-16-personality-types-talk-to-an-ai-coach';
    const cn='https://fermatmind.com/zh/articles/'+(aiPair ? row.identity.slug : 'mbti-vs-holland-career-choice');
    const en='https://fermatmind.com/en/articles/'+(aiPair ? row.identity.slug : 'mbti-vs-holland-code-career-choice');
    const expected=[['en',en],['zh-CN',cn],['x-default',en]].sort();
    const entries=dom.alternate_entries;
    const languages=entries.filter(entry=>entry.hreflang).map(entry=>[entry.hreflang,entry.href]).sort();
    const feeds=entries.filter(entry=>!entry.hreflang);
    const beforeFeeds=before.dom.alternate_entries.filter(entry=>!entry.hreflang);
    if ((!aiPair && !['PAGE-67','PAGE-68'].includes(row.page_id)) || !equal(languages,expected) || !equal(feeds,beforeFeeds))
      throw new Error(`PUBLIC_CONTENT_53_DISCOVERABILITY_DRIFT_${row.page_id}`);
  }
  if (dom.canonical.length !== 1 || (!before.missing && (!equal(dom.canonical,before.dom.canonical) || !equal(dom.robots,before.dom.robots)))
    || (!row.new_traditional_article && !newArticle && !newTraditionalPage && !initializedStaging && !reviewedHreflang && !equal(dom.alternates,before.dom.alternates))) throw new Error(`PUBLIC_CONTENT_53_DISCOVERABILITY_DRIFT_${row.page_id}`);
  if (initializedStaging) {
    // The staging Article template deliberately omits hreflang and retains
    // noindex. Bind the missing locale to the observed counterpart policy;
    // only its existing localized Atom feed changes language.
    const reference=before.stagingReferenceDom;
    const requiredRobots=value=>{
      const tokens=value.toLowerCase().split(',').map(token=>token.trim());
      return tokens.includes('noindex') && tokens.includes('follow') && !tokens.includes('index');
    };
    const sourceFeed='/zh/articles/feed.xml',targetFeed='/en/articles/feed.xml';
    const atom=entry=>entry && entry.type.toLowerCase() === 'application/atom+xml' && entry.hreflang.trim() === '';
    const expectedFeed=reference?.alternates.length === 1 ? internalTarget(reference.alternates[0],environment) : null;
    if (expectedFeed) expectedFeed.pathname=targetFeed;
    if (!reference || reference.robots.length !== 1 || !reference.robots.every(requiredRobots)
      || !equal(dom.robots,reference.robots) || reference.alternates.length !== 1 || dom.alternates.length !== 1
      || internalTarget(reference.alternates[0],environment)?.pathname !== sourceFeed
      || internalTarget(dom.alternates[0],environment)?.pathname !== targetFeed
      || dom.alternates[0] !== expectedFeed?.href
      || reference.alternate_entries?.length !== 1 || dom.alternate_entries.length !== 1
      || !atom(reference.alternate_entries[0]) || !atom(dom.alternate_entries[0]))
      throw new Error(`PUBLIC_CONTENT_53_DISCOVERABILITY_DRIFT_${row.page_id}`);
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
export function assertNewArticleDiscovery(rows,documents,environment='production') {
  const {sitemap,llms,archiveEn,archiveZh}=documents;
  const links={en:domOf(archiveEn).links,'zh-CN':domOf(archiveZh).links};
  for(const row of rows) {
    const path=pathFor(row),url='https://fermatmind.com'+path;
    if (!sitemap.includes(`<loc>${url}</loc>`) || !llms.includes(url)
      || !links[row.identity.locale].some(href=>internalTarget(href,environment)?.pathname === path)) throw new Error('PUBLIC_CONTENT_53_NEW_ARTICLE_DISCOVERY');
  }
}
export async function verifyOnlineCandidates(environment,backendRoot,fetcher=fetch,renderer=renderPage,prestate,packageSha256) {
  const [api,web]=hosts(environment);if(!prestate) throw new Error('PUBLIC_CONTENT_53_PRESTATE_REQUIRED');
  const rows = readCandidateRows(backendRoot,packageSha256,environment).candidates.map(row=>environment === 'staging' && row.staging_snapshot ? {...row,snapshot:row.staging_snapshot} : row);
  const originalIds=new Map();
  for (const row of rows) {
    if (row.staging_article_original) {
      const source=row.staging_article_original;
      const current=assertOriginalArticleApi(row,parseApi(await read(api+apiPath(row),fetcher)),environment,originalIds.get(source.identity.source_article_id));
      const before=prestate[row.page_id];
      if (environment === 'staging' ? before?.missing !== true : before?.id !== current.id || before.published_revision_id !== current.published_revision_id) {
        throw new Error('PUBLIC_CONTENT_53_STAGING_ARTICLE_ORIGINAL_PRESTATE');
      }
      const html=await read(web+pathFor(row),fetcher,false,{'User-Agent':'facebookexternalhit/1.1'});
      const dom=domOf(html,{articleSsr:true});
      const stage=environment === 'staging';
      const reference=before.dom;
      if (!equal(dom.canonical.map(value=>new URL(value).pathname),[pathFor(row)])
        || !equal(dom.h1.map(compact),[compact(source.published.title)])
        || dom.article_body.length !== 1 || !compact(dom.article_body[0])
        || (stage ? !dom.robots.some(value=>/\bnoindex\b/i.test(value)) || dom.alternate_entries.some(entry=>entry.hreflang)
          : !equal(dom.robots,reference.robots) || !equal(dom.alternates,reference.alternates) || !equal(dom.canonical,reference.canonical))) {
        throw new Error('PUBLIC_CONTENT_53_STAGING_ARTICLE_ORIGINAL_DOM');
      }
      originalIds.set(source.identity.id,current.id);
      continue;
    }
    if (row.kind === 'mbti-result') {
      if (row.page_id.endsWith('-NATIVE-LEAVES') && row.identity.slug !== 'intj-t') {
        if (prestate[row.page_id]?.native_adapter_phase_only !== true) throw new Error('MBTI_RESULT_COPY_QA_PRESTATE_REQUIRED');
        continue;
      }
      const before = prestate[row.page_id];
      const current = await readMbtiResultCopySample(environment,row,fetcher,renderer);
      if (!before || !equal(current.copy,row.snapshot) || current.controls_sha256 !== before.controls_sha256
        || current.variant_section_count !== before.variant_section_count
        || current.h1_count !== before.h1_count || current.headings_sha256 !== before.headings_sha256)
        throw new Error('MBTI_RESULT_COPY_QA_NATIVE_READBACK_FAILED');
      continue;
    }
    const data=parseApi(await read(api+apiPath(row),fetcher));const before=prestate[row.page_id];const native=data[row.kind];
    const createdStaging = environment === 'staging' && row.page_id === 'PAGE-60' && row.staging_bootstrap && before?.missing === true;
    const createdArticle = row.new_article === true && before?.missing === true;
    const createdTraditional = (row.new_traditional_article === true || row.new_traditional_page === true || row.new_traditional_landing === true) && before?.missing === true;
    if (row.featured_targets) {
      const archive=parseApi(await read(api+'/api/v0.5/articles?locale=zh-TW&org_id=0&include_blog=1',fetcher)).blog_v1;
      const fields=['id','slug','published_revision_id'];
      const pick=item=>fields.map(field=>item[field]);
      if (native.is_indexable !== false || archive?.configuration_state !== 'published'
        || archive.is_indexable !== false || !equal(archive.featured_items?.map(pick),row.featured_targets.map(pick)))
        throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_BLOG_FEATURED_API_${row.page_id}`);
    }
    if (row.new_traditional_landing && native.is_indexable !== row.traditional_landing_indexable)
      throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_LANDING_QUALIFICATION_${row.page_id}`);
    if (row.new_traditional_page && (native.is_indexable !== false || native.path !== pathFor(row)))
      throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_PAGE_QUALIFICATION_${row.page_id}`);
    if (row.new_traditional_article === true && (!['is_indexable','sitemap_eligible','llms_eligible'].every(key=>native[key] === false)
      || native.translation_group_id !== row.source_identity.translation_group_id
      || native.source_article_id !== (environment === 'staging' ? row.staging_source_article_id : row.source_identity.source_article_id)
      || !Number.isInteger(native.published_revision_id))) throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_ARTICLE_QUALIFICATION_${row.page_id}`);
    if (row.new_traditional_article && row.source_identity?.source_locale === 'en') {
      const source=row.source_expectations.source;
      const original=parseApi(await read(api+'/api/v0.5/articles/'+source.slug+'?locale=en&org_id=0',fetcher));
      const expected={en:source.slug,'zh-TW':row.identity.slug};
      if (native.source_locale !== 'en' || original.ok !== true || original.article?.id !== source.id
        || original.article.locale !== 'en' || original.article.published_revision_id !== source.published_revision_id
        || !equal(data.reader_language_slugs,expected) || !equal(original.reader_language_slugs,expected))
        throw new Error('PUBLIC_CONTENT_53_TRADITIONAL_ENGLISH_READER_PAIR');
    }
    if (row.traditional_article_batch && ((native.category?.id ?? null) !== row.taxonomy_display.category.id
      || (native.category?.slug ?? null) !== row.taxonomy_display.category.slug
      || !equal((native.tags ?? []).map(tag=>[tag.id,tag.slug]).sort(),row.taxonomy_display.tags.map(tag=>[tag.id,tag.slug]).sort())))
      throw new Error(`PUBLIC_CONTENT_53_TRADITIONAL_ARTICLE_TAXONOMY_${row.page_id}`);
    if (row.new_article === true && (!['is_indexable','sitemap_eligible','llms_eligible'].every(key=>native[key] === true)
      || native.translation_group_id !== row.source_identity.translation_group_id || !Number.isInteger(native.published_revision_id))) throw new Error(`PUBLIC_CONTENT_53_NEW_ARTICLE_QUALIFICATION_${row.page_id}`);
    if(!before || !equal(projection(row,data),row.snapshot)
      || (createdStaging ? native.is_indexable !== row.staging_bootstrap.is_indexable || native.translation_group_id !== row.staging_source_identity.translation_group_id
        : !createdArticle && !createdTraditional && (native.id !== before.id || Object.entries(before.flags).some(([k,v])=>!equal(native[k],v))))) throw new Error(`PUBLIC_CONTENT_53_NATIVE_READBACK_${row.page_id}`);
    // One publication only. Existing 300-second public fetch caches can settle
    // while exact candidate DOM assertions remain strict and bounded.
    const deadline=Date.now()+360000;let last;
    while (true) {
      try {
        const verifyDOM=html=>assertDom(row,html,before,environment);
        // Request Next's complete HTML-only crawler body instead of unfinished
        // $RS placeholders. Ordinary Chrome still verifies final visibility.
        if(row.new_article) assertDom(row,await read(web+pathFor(row),fetcher,false,{'User-Agent':'facebookexternalhit/1.1'}),before,environment,{articleSsr:true});
        verifyDOM(await renderer(web+pathFor(row),{surface:'public_page',verifyDOM}));break;
      }
      catch(error) {last=error?.message?.startsWith('PUBLIC_CONTENT_53_') ? error : new Error(`PUBLIC_CONTENT_53_BROWSER_FAILED_${row.page_id}`);if(Date.now()>=deadline) throw last;await new Promise(resolve=>setTimeout(resolve,10000));}
    }
  }
  const newRows=rows.filter(row=>row.new_article);
  if (newRows.length && environment === 'production') {
    const deadline=Date.now()+360000;
    while(true) {
      try {
        const [sitemap,llms,archiveEn,archiveZh]=await Promise.all(['/sitemap.xml','/llms.txt','/en/articles','/zh/articles'].map(path=>read(web+path,fetcher)));
        assertNewArticleDiscovery(newRows,{sitemap,llms,archiveEn,archiveZh},environment);
        break;
      } catch(error) {if(Date.now()>=deadline) throw new Error('PUBLIC_CONTENT_53_NEW_ARTICLE_DISCOVERY');await new Promise(resolve=>setTimeout(resolve,10000));}
    }
  }
  const adapterOnly=rows.filter(row=>row.kind === 'mbti-result' && row.page_id.endsWith('-NATIVE-LEAVES') && row.identity.slug !== 'intj-t').length;
  return {environment,api_readback_count:rows.length-adapterOnly,dom_readback_count:rows.length-adapterOnly,
    ...(adapterOnly?{native_adapter_phase_only_row_count:adapterOnly,package_row_count:rows.length}:{}),
    raw_html_readback_count:newRows.length+originalIds.size,discovery_readback_count:environment === 'production'?newRows.length:0};
}
