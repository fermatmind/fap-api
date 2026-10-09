import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { assertSsrCandidate, verifyOnlineCandidates } from './eq-new-source-online-qa.mjs';
import { recoveryFailure, buildExecution } from './eq-new-source-publish.mjs';
const rows=JSON.parse(readFileSync('backend/content_assets/eq_public/candidate/20261009-new-articles/assets.json','utf8')).candidates.filter(row=>row.identity.locale==='zh-CN');
const escape=text=>text.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;').replaceAll('>','&gt;');
const html=row=>`<title>${escape(row.snapshot.seo_title)} | FermatMind</title><meta name="description" content="${escape(row.snapshot.seo_description)}"><article data-testid="article-detail-content"><h1>${row.snapshot.title}</h1><div>${escape(row.snapshot.content_md.replace(/\[([^\]]+)\]\([^)]+\)/g,'$1').replace(/[*`_]/g,''))}</div></article>`;
const api=row=>({ok:true,article:{...row.identity,...row.snapshot},seo_surface_v1:{title:row.snapshot.seo_title,description:row.snapshot.seo_description}});
const fetcher=alter=>async(url)=>{const row=rows.find(row=>url.includes(row.identity.slug));const json=url.includes('/api/');let body=json?JSON.stringify(api(row)):html(row);body=alter?.(body,json,row)??body;return{status:200,headers:new Headers({'content-type':json?'application/json':'text/html'}),text:async()=>body};};
const renderer=async url=>html(rows.find(row=>url.includes(row.identity.slug)));
test('online QA reads the full frozen content through both real environment URL surfaces',async()=>{
 const calls=[];const reader=fetcher();const result=await verifyOnlineCandidates('staging','backend',async(url,options)=>{calls.push(url);assert.equal(options.redirect,'error');return reader(url);},renderer);
 assert.deepEqual(result,{api_readback_count:3,seo_readback_count:3,ssr_readback_count:3,environment:'staging'});
 assert.equal(calls.length,9);assert.ok(calls.every(url=>url.startsWith('https://staging-api.fermatmind.com/')||url.startsWith('https://staging.fermatmind.com/')));
});
test('unreachable real API and stale body fail acceptance',async()=>{
 await assert.rejects(verifyOnlineCandidates('production','backend',async()=>{throw new Error('network');}));
 await assert.rejects(verifyOnlineCandidates('production','backend',fetcher((body,json)=>json?JSON.stringify({...JSON.parse(body),article:{...JSON.parse(body).article,content_md:'old'}}):body),renderer),/EQ_ONLINE_BODY_MISMATCH/);
});
test('SSR cannot pass from a hydration-only body, partial body, or mismatched metadata',()=>{
 const row=rows[0];
 assert.throws(()=>assertSsrCandidate(html(row).replace(/<div>[\s\S]*?<\/div>/,`<script>${row.snapshot.content_md}</script>`),row),/EQ_SSR_BODY_MISMATCH/);
 assert.throws(()=>assertSsrCandidate(html(row).replace(/<div>[\s\S]*?<\/div>/,'<div>Partial body</div>'),row),/EQ_SSR_BODY_MISMATCH/);
 assert.throws(()=>assertSsrCandidate(html(row).replace(escape(row.snapshot.seo_description),'stale'),row),/EQ_SSR_METADATA_MISMATCH/);
});
test('a bare restored=false does not assert recovery completion',()=>{
 const env={EQ_PUBLISH_ENVIRONMENT:'staging',DEPLOY_PATH:'/srv/app',DEPLOY_USER:'deploy',DEPLOY_HOST:'host.invalid',DEPLOY_PORT:'22',DEPLOY_SHA:'a'.repeat(40),GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'test-key'.repeat(8)};
 const execution=buildExecution(env,'backend');
 const recover=status=>()=>({status:0,stdout:JSON.stringify({ok:true,mode:'recover',restored:false,source_commit:env.DEPLOY_SHA,...(status?{recovery_status:status}:{})})});
 assert.equal(recoveryFailure(execution,recover()).receipt.recovery_completed,false);
 assert.equal(recoveryFailure(execution,recover('not_required')).receipt.recovery_completed,true);
});

test('SSR rejects wrong metadata title and hidden substitutes in the actual article region',()=>{
 const row=rows[0];
 assert.throws(()=>assertSsrCandidate(html(row).replace(/<title>[\s\S]*?<\/title>/,'<title>Wrong</title>'),row),/EQ_SSR_SEO_TITLE_MISMATCH/);
 for(const attributes of ['hidden','style="display:none"','class="sr-only"','aria-hidden="true"']) assert.throws(()=>assertSsrCandidate(html(row).replace('<div>',`<div ${attributes}>`),row),/EQ_SSR_BODY_MISMATCH/);
});
