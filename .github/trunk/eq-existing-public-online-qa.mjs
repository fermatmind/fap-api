import {isDeepStrictEqual} from 'node:util';
import {readCandidateRows,inspectPackage} from './eq-existing-public-package.mjs';
import {renderedDocument,assertSsrCandidate,expectedVisibleBody} from './eq-new-source-online-qa.mjs';

export async function verifyOnlineCandidates(environment,backendRoot,fetcher=fetch,renderer=renderedDocument) {
  if(!['staging','production'].includes(environment)) throw new Error('EQ_EXISTING_ONLINE_ENVIRONMENT');
  inspectPackage(backendRoot);
  const api=environment==='staging'?'https://staging-api.fermatmind.com':'https://api.fermatmind.com';
  const web=environment==='staging'?'https://staging.fermatmind.com':'https://fermatmind.com';
  const read=async(url,type)=>{
    const response=await fetcher(url,{redirect:'error',signal:AbortSignal.timeout(15000),headers:{'Cache-Control':'no-cache'}});
    if(response.status!==200 || !response.headers.get('content-type')?.includes(type)) throw new Error('EQ_EXISTING_ONLINE_RESPONSE');
    const body=await response.text();
    if(body.length>2000000) throw new Error('EQ_EXISTING_ONLINE_PAYLOAD_LIMIT');
    return body;
  };
  let seoCount=0;
  for(const row of readCandidateRows(backendRoot)) {
    const locale=row.identity.locale,key=locale==='en'?'en':'zh',slug=row.identity.slug,isEntry=row.page_id==='EQ-01',isArticle=row.page_id==='EQ-02';
    const prefix=isEntry?'tests':isArticle?'articles':'career/guides';
    const apiPrefix=isArticle?'articles':'career-guides';
    const apiUrl=isEntry?`${api}/api/v0.3/scales/lookup?slug=${slug}&locale=${locale}`:`${api}/api/v0.5/${apiPrefix}/${slug}?locale=${locale}&org_id=0`;
    const payload=JSON.parse(await read(apiUrl,'application/json'));
    if(payload.ok!==true) throw new Error('EQ_EXISTING_ONLINE_IDENTITY');
    if(isEntry) {
      if(payload.primary_slug!==slug || payload.scale_code!=='EQ_60' || payload.is_public!==true) throw new Error('EQ_EXISTING_ONLINE_IDENTITY');
      for(const operation of row.registry_operations) {
        const actual=operation.path.slice(1).split('/').reduce((value,segment)=>value?.[segment],payload.content_i18n_json);
        if(!isDeepStrictEqual(actual,operation.value)) throw new Error('EQ_EXISTING_ONLINE_REGISTRY_BODY');
      }
    } else {
      const body=payload[isArticle?'article':'guide'];
      if(body?.slug!==slug || body?.locale!==locale) throw new Error('EQ_EXISTING_ONLINE_IDENTITY');
      for(const [field,expected] of Object.entries(row.snapshot)) {
        const actual=field.startsWith('seo_')?payload.seo_surface_v1?.[field==='seo_title'?'title':'description']:body[!isArticle&&field==='content_md'?'body_md':field];
        if(actual!==expected) throw new Error('EQ_EXISTING_ONLINE_BODY');
      }
      seoCount++;
    }
    const url=`${web}/${key}/${prefix}/${slug}`;
    await read(url,'text/html');
    for(const width of [390,1366]) {
      const document=await renderer(url,{selector:isEntry?'main':isArticle?'article[data-testid="article-detail-content"]':'main article',width,expandDetails:isEntry});
      if(!isEntry) { assertSsrCandidate(document,row); continue; }
      // EQ-01 uses the native structured registry, not an Article markdown body.
      if(document.headings?.length!==1 || document.headings[0]!==row.snapshot.title || document.title!==`${row.snapshot.seo_title} | FermatMind`
        || document.description!==row.snapshot.seo_description || !document.articleVisible) throw new Error('EQ_EXISTING_ENTRY_METADATA');
      const visible=document.body.replace(/\s/gu,'');
      for(const operation of row.registry_operations.filter(operation=>/\/(?:intro|body|a)$/.test(operation.path))) {
        if(typeof operation.value!=='string' || !visible.includes(expectedVisibleBody(operation.value))) throw new Error('EQ_EXISTING_ENTRY_RENDERED_BODY');
      }
      const faq=payload.content_i18n_json?.[key]?.faq;
      if(!Array.isArray(faq) || faq.length!==11 || faq.some(item=>!visible.includes(expectedVisibleBody(item.q)))) throw new Error('EQ_EXISTING_ENTRY_FAQ');
    }
  }
  return {environment,api_readback_count:6,seo_readback_count:seoCount,ssr_readback_count:6,rendered_viewport_count:12};
}
