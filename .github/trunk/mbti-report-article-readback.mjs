import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

export async function readback(expectedSha, fetcher=fetch, root=resolve('backend/content_assets/mbti_report_articles/20261019')) {
  const pkg=JSON.parse(readFileSync(resolve(root,'package.json'),'utf8'));
  const read=async path=>{
    const response=await fetcher('https://api.fermatmind.com'+path,{redirect:'error',signal:AbortSignal.timeout(15000)});
    if(response.status!==200) throw new Error('MBTI_READBACK_HTTP');
    return response.json();
  };
  if((await read('/revision')).revision!==expectedSha) throw new Error('MBTI_READBACK_REVISION');
  for(const row of pkg.articles) {
    const path=`/api/v0.5/articles/${row.identity.slug}?locale=${row.identity.locale}&org_id=0`;
    const data=await read(path);
    const a=data.article;
    if(!a || a.id!==row.identity.id || a.locale!==row.identity.locale || a.slug!==row.identity.slug || a.org_id!==0
      || a.title!==row.title || a.excerpt!==row.excerpt || a.content_md!==readFileSync(resolve(root,row.file),'utf8'))
      throw new Error('MBTI_READBACK_ARTICLE');
    const faq=data.answer_surface_v1?.faq_blocks;
    if(!Array.isArray(faq) || JSON.stringify(faq.map(({question,answer})=>({question,answer})))!==JSON.stringify(row.faq_items.map(({question,answer})=>({question,answer}))))
      throw new Error('MBTI_READBACK_FAQ');
    const seo=await read(path.replace('?', '/seo?'));
    if(seo.meta?.title!==row.seo_title || seo.meta?.description!==row.seo_description)
      throw new Error('MBTI_READBACK_SEO');
  }
  return {ok:true,body_match:true,faq_counts:[6,8]};
}

if(process.argv[1] && import.meta.url===new URL(`file://${process.argv[1]}`).href) {
  try {console.log(JSON.stringify(await readback(process.argv[2])));}
  catch(error) {
    const reason=/^MBTI_READBACK_[A-Z]+$/.test(error.message)?error.message:'MBTI_READBACK_FAILED';
    console.log(JSON.stringify({ok:false,reason}));
    process.exitCode=1;
  }
}
