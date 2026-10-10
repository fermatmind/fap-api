import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, mkdtempSync, existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { assertSsrCandidate, expectedVisibleBody, closeOwnedBrowser, renderedDocument, verifyOnlineCandidates } from './eq-new-source-online-qa.mjs';
import { recoveryFailure, buildExecution } from './eq-new-source-publish.mjs';
const rows=JSON.parse(readFileSync('backend/content_assets/eq_public/candidate/20261009-new-articles/assets.json','utf8')).candidates.filter(row=>row.identity.locale==='zh-CN');
const document=row=>({title:row.snapshot.seo_title+' | FermatMind',description:row.snapshot.seo_description,headings:[row.snapshot.title],articleVisible:true,body:expectedVisibleBody(row.snapshot.content_md)});
const api=row=>({ok:true,article:{...row.identity,...row.snapshot},seo_surface_v1:{title:row.snapshot.seo_title,description:row.snapshot.seo_description}});
const fetcher=alter=>async(url)=>{const row=rows.find(row=>url.includes(row.identity.slug));const json=url.includes('/api/');let body=json?JSON.stringify(api(row)):'<html></html>';body=alter?.(body,json,row)??body;return{status:200,headers:new Headers({'content-type':json?'application/json':'text/html'}),text:async()=>body};};
const renderer=async url=>document(rows.find(row=>url.includes(row.identity.slug)));
test('online QA reads the full frozen content through both real environment URL surfaces',async()=>{
 const calls=[];const reader=fetcher();const result=await verifyOnlineCandidates('staging','backend',async(url,options)=>{calls.push(url);assert.equal(options.redirect,'error');return reader(url);},renderer);
 assert.deepEqual(result,{api_readback_count:3,seo_readback_count:3,ssr_readback_count:3,environment:'staging'});
 assert.equal(calls.length,9);assert.ok(calls.every(url=>url.startsWith('https://staging-api.fermatmind.com/')||url.startsWith('https://staging.fermatmind.com/')));
});
test('unreachable real API and stale body fail acceptance',async()=>{
 await assert.rejects(verifyOnlineCandidates('production','backend',async()=>{throw new Error('network');}));
 await assert.rejects(verifyOnlineCandidates('production','backend',fetcher((body,json)=>json?JSON.stringify({...JSON.parse(body),article:{...JSON.parse(body).article,content_md:'old'}}):body),renderer),/EQ_ONLINE_BODY_MISMATCH/);
});
test('rendered acceptance rejects partial, hidden, additional body and wrong metadata',()=>{
 const row=rows[0],accepted=document(row);
 for(const delta of [{body:'Partial'},{articleVisible:false},{body:accepted.body+'Extra old copy'}]) assert.throws(()=>assertSsrCandidate({...accepted,...delta},row),/EQ_SSR_BODY_MISMATCH/);
 assert.throws(()=>assertSsrCandidate({...accepted,title:'Wrong'},row),/EQ_SSR_SEO_TITLE_MISMATCH/);
 assert.throws(()=>assertSsrCandidate({...accepted,description:'Wrong'},row),/EQ_SSR_METADATA_MISMATCH/);
 assert.throws(()=>assertSsrCandidate('<html>HTML alone is not visible evidence</html>',row),/EQ_SSR_TITLE_MISMATCH/);
});
test('actual browser evaluates external CSS visibility and rejects mixed old body',async()=>{
 const row={snapshot:{title:'Reviewed page',seo_title:'Reviewed page',seo_description:'Description',content_md:'## Purpose\n\nOnly the reviewed body.\n\n[Read more](https://example.com)'}};
 const html=(style='',extra='')=>`<html><head><title>Reviewed page | FermatMind</title><meta name="description" content="Description"><style>${style}</style></head><body><h1>Reviewed page</h1><article class="arbitrary-copy-class" data-testid="article-detail-content"><h2>Purpose</h2><p>Only the reviewed body.</p><a href="https://example.com">Read more</a>${extra}</article></body></html>`;
 const read=content=>renderedDocument('data:text/html;charset=utf-8,'+encodeURIComponent(content));
 assertSsrCandidate(await read(html()),row);
 const awaitedHidden=await read(html('.arbitrary-copy-class {display:none}'));
 assert.throws(()=>assertSsrCandidate(awaitedHidden,row),/EQ_SSR_BODY_MISMATCH/);
 const mixed=await read(html('','<p>Additional old body.</p>'));
 assert.throws(()=>assertSsrCandidate(mixed,row),/EQ_SSR_BODY_MISMATCH/);
 for(const css of ['height:0;overflow:hidden','position:absolute;clip:rect(0,0,0,0)','clip-path:inset(100%)']) {
  const clipped=await read(html().replace('<article','<div style="'+css+'"><article').replace('</article>','</article></div>'));
  assert.throws(()=>assertSsrCandidate(clipped,row),/EQ_SSR_BODY_MISMATCH/);
 }
});
test('a bare restored=false does not assert recovery completion',()=>{
 const env={EQ_PUBLISH_ENVIRONMENT:'staging',DEPLOY_PATH:'/srv/app',DEPLOY_USER:'deploy',DEPLOY_HOST:'host.invalid',DEPLOY_PORT:'22',DEPLOY_SHA:'a'.repeat(40),GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'test-key'.repeat(8)};
 const execution=buildExecution(env,'backend');
 const recover=status=>()=>({status:0,stdout:JSON.stringify({ok:true,mode:'recover',restored:false,source_commit:env.DEPLOY_SHA,...(status?{recovery_status:status}:{})})});
 assert.equal(recoveryFailure(execution,recover()).receipt.recovery_completed,false);
 assert.equal(recoveryFailure(execution,recover('not_required')).receipt.recovery_completed,true);
});

test('owned browser refusing close and TERM is killed before its profile is removed',async()=>{
 const profile=mkdtempSync(join(tmpdir(),'eq-public-reader-close-test-'));
 const child=spawn(process.execPath,['-e',"process.on('SIGTERM',()=>{});process.stdout.write('ready');setInterval(()=>{},1000)"],{stdio:['ignore','pipe','ignore']});
 await once(child.stdout,'data');
 await closeOwnedBrowser(child,async()=>{throw new Error('Close refused');},profile);
 assert.equal(child.signalCode,'SIGKILL');
 assert.equal(existsSync(profile),false);
});
