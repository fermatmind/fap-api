import test from 'node:test';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { inspectPackage, readCandidateRows, isPublic53OnlyPromotionRegistration, isPublic53OnlyContextFactoryChange } from './public-content-53-package.mjs';
import { buildExecution, publish, recoveryFailure } from './public-content-53-publish.mjs';
import { assertDom, pathFor, read, projection } from './public-content-53-online-qa.mjs';
import { phpConsumers } from './impact-consumers.mjs';
const root=fileURLToPath(new URL('../../backend',import.meta.url));
const translationDigest=()=>createHash('sha256').update('fermatmind.public_content_translation.v1\n'+['translation-batch.json','translation-review.json'].map(name=>`${name}\n${createHash('sha256').update(readFileSync(resolve(root,'content_assets/public_content_53',name))).digest('hex')}\n`).join('')).digest('hex');
function translatedTestsHubDom() {
 const row=readCandidateRows(root,translationDigest()).candidates.find(row=>row.page_id==='PAGE-32');
 const payload=row.snapshot.payload_json;
 const list={'@type':'ItemList',inLanguage:'en',numberOfItems:payload.quickStart.items.length,itemListElement:payload.quickStart.items.map((item,index)=>({'@type':'ListItem',position:index+1,name:item.title,description:item.description,url:'https://fermatmind.com'+item.href}))};
 const cards=[payload.families.items[1].tests[0].description,payload.families.items[0].tests[1].outputLabel];
 const script='<script type="application/ld+json" id="tests-hub-quickstart-en">'+JSON.stringify(list)+'</script>';
 const html='<html><head><link rel="canonical" href="https://fermatmind.com/en/tests"></head><body><main>'+script+'<h1>Free assessments</h1>'+cards.map(text=>'<p>'+text+'</p>').join('')+'</main></body></html>';
 return {row,list,cards,script,html,before:{dom:{canonical:['https://fermatmind.com/en/tests'],robots:[],alternates:[]}}};
}
test('translated tests hub checks quickStart in its actual named JSON-LD consumer and cards in visible prose',()=>{
 const {row,cards,html,before}=translatedTestsHubDom();
 assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
 for(const card of cards) {
  assert.throws(()=>assertDom(row,html.replace('<p>'+card+'</p>',''),before,'production'),/VISIBLE_BODY/);
  assert.throws(()=>assertDom(row,html.replace('<p>'+card+'</p>','<script>'+card+'</script>'),before,'production'),/VISIBLE_BODY/);
 }
});
test('tests hub JSON-LD must retain the exact translated text, identity, route and full item inventory',()=>{
 const {row,list,script,html,before}=translatedTestsHubDom();
 for(const mutate of [x=>{x.itemListElement[1].description='Old unrelated summary';},x=>{x.itemListElement[2].url='https://external.invalid/tests';},x=>{x.itemListElement[3].url='https://fermatmind.com/en/tests#wrong';},x=>{x.itemListElement[1].position=99;},x=>{x.itemListElement.pop();},x=>{x.inLanguage='zh-CN';}]) {
  const altered=structuredClone(list);mutate(altered);
  assert.throws(()=>assertDom(row,html.replace(JSON.stringify(list),JSON.stringify(altered)),before,'production'),/QUICKSTART_JSONLD/);
 }
 for(const altered of [html.replace(script,''),html.replace(script,script+script),html.replace('tests-hub-quickstart-en','unrelated-data'),html.replace('application/ld+json','text/javascript'),html.replace(JSON.stringify(list),'{')])
  assert.throws(()=>assertDom(row,altered,before,'production'),/QUICKSTART_JSONLD/);
});
test('previous translation package remains readable by its own immutable digest',()=>{
 const rows=readCandidateRows(root,translationDigest()).candidates;
 assert.equal(rows.length,9);
 assert.equal(new Set(rows.map(row=>row.page_id)).size,9);
 const allowed=['help-about','help-team','help-used-and-mentioned','help-payment-refund','help-unlock-failure','help-result-recovery','tests','tests_category_career','tests_category_personality'];
 assert.deepEqual(rows.map(row=>row.identity.slug).sort(),allowed.sort());
 assert.ok(rows.every(row=>['page','surface'].includes(row.kind) && row.identity.org_id===0 && row.identity.locale==='en'));
 assert.equal(rows.find(row=>row.identity.slug==='help-payment-refund').snapshot.faq_items.length,4);
 assert.ok(rows.find(row=>row.identity.slug==='help-unlock-failure').snapshot.content_md.includes('24 hours'));
 assert.equal(rows.filter(row=>row.staging_source_snapshot).length,3);
 assert.equal(rows.find(row=>row.page_id==='PAGE-32').kind,'surface');
});
test('previous article amendment still binds only the reviewed existing article body',()=>{
 const binding=inspectPackage(root);const sha=bytes=>createHash('sha256').update(bytes).digest('hex');const digest=sha('fermatmind.public_content_article_amendment.v1\n'+['article-amendment.json','article-amendment-review.json'].map(name=>`${name}\n${sha(readFileSync(resolve(root,'content_assets/public_content_53',name)))}\n`).join(''));const rows=readCandidateRows(root,digest).candidates;
 assert.equal(binding.expected_row_count,1);assert.equal(rows.length,1);
 const row=rows[0];assert.equal(row.page_id,'PAGE-54');assert.equal(row.kind,'article');
 assert.deepEqual(row.identity,{org_id:0,slug:'mbti-full-report-career-relationship-communication',locale:'en'});
 for(const before of [row.before,row.staging_before]) {
  assert.equal(row.snapshot.title,before.title);assert.equal(row.snapshot.excerpt,before.excerpt);
 }
 assert.ok(row.snapshot.content_md.includes('Identify the tool behind the letters'));
 assert.ok(row.snapshot.content_md.includes('A/T is not a fifth official MBTI preference.'));
});
test('C02 article QA follows stripped CMS markers and still requires actual reader prose',()=>{
 const marker='<!-- body_visual:big_five_conscientiousness_task_plan_flow -->';
 const paragraph='Record a task opportunity when it occurs; do not create tasks just to fill seven days.';
 const source={page_id:'PAGE-04',kind:'article',identity:{org_id:0,locale:'en',slug:'big-five-conscientiousness-low-procrastination-task-plan'},snapshot:{title:'Low Conscientiousness in the Big Five',content_md:marker+'\n\n'+paragraph}};
 // A reader DOM fixture using the actual C02 marker and prose; contract CI
 // intentionally runs without Composer or a frontend application build.
 const row={...source,snapshot:{...source.snapshot,content_md:marker+'\n\n'+paragraph}};
 const url='https://fermatmind.com'+pathFor(row);
 const html='<html><head><meta charset="utf-8"><link rel="canonical" href="'+url+'"></head><body><main><h1>'+row.snapshot.title+'</h1><p>'+paragraph+'</p></main></body></html>';
 const before={dom:{canonical:[url],robots:[],alternates:[]}};
 assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
 assert.throws(()=>assertDom(row,html.replace(paragraph,''),before,'production'),/VISIBLE_BODY/);
 const small={...row,snapshot:{...row.snapshot,content_md:'Before <!-- FM_SLOT:mid_article --> after.\n\n&lt;!-- body_visual:example --&gt;\n\nRequired prose.'}};
 const visible='<html><head><link rel="canonical" href="'+url+'"></head><body><main><h1>'+row.snapshot.title+'</h1><p>Before after.</p><p>Required prose.</p></main></body></html>';
 assert.doesNotThrow(()=>assertDom(small,visible,before,'production'));
 assert.throws(()=>assertDom(small,visible.replace('Required prose.',''),before,'production'),/VISIBLE_BODY/);
 const unknown={...small,snapshot:{...small.snapshot,content_md:'<!-- unrecognized:marker -->\n\nRequired prose.'}};
 assert.throws(()=>assertDom(unknown,visible,before,'production'),/VISIBLE_BODY/);
});
test('previous immutable 50-row package remains fully validated by its own exact digest',()=>{
 const sha=bytes=>createHash('sha256').update(bytes).digest('hex');
 const names=['assets.json','independent-review.json','enneagram_metrics.en.json','enneagram_public_copy.en.json','riasec_technical_note.en.json'];
 const digest=sha('fermatmind.public_content_53.v1\n'+names.map(name=>`${name}\n${sha(readFileSync(resolve(root,'content_assets/public_content_53',name)))}\n`).join(''));
 const rows=readCandidateRows(root,digest).candidates;
 assert.equal(rows.length,50);assert.equal(new Set(rows.map(x=>x.page_id)).size,50);
 assert.equal(rows.filter(x=>x.kind==='article').length,33);assert.equal(rows.filter(x=>x.kind==='page').length,11);
 assert.equal(rows.filter(x=>x.kind==='profile').length,2);
});
test('public-only registration proof rejects unrelated policy or canonical changes',()=>{
 const config=readFileSync(resolve(root,'config/content_promotion.php'),'utf8');const base=config.replace("    'public_content_53_environment' => env('PUBLIC_CONTENT_53_ENVIRONMENT', 'production'),\n",'').replace("        'content_assets/public_content_53',\n",'').replace(", 'PUBLIC-CONTENT-53' => 'audit_compatible'",'');
 assert.equal(isPublic53OnlyPromotionRegistration(base,config),true);
 const legacyConfig=config.replace("    'public_content_53_environment' => env('PUBLIC_CONTENT_53_ENVIRONMENT', 'production'),\n",'');
 assert.equal(isPublic53OnlyPromotionRegistration(legacyConfig,config),true);
 assert.equal(isPublic53OnlyPromotionRegistration(legacyConfig,config.replace("'W2'","'W22'")),false);
 assert.equal(isPublic53OnlyPromotionRegistration(base,config.replace("'W2'", "'W22'")),false);
 const factory=readFileSync(resolve(root,'app/Services/ContentPromotion/PromotionContextFactory.php'),'utf8');
 const addition="        if ($lane === 'W3' && $subscope === 'PUBLIC-CONTENT-53') {\n            $signatureMaterial .= '|'.$executorReleaseSha256.'|'.config('content_promotion.public_content_53_environment', 'production');\n        }\n";
 assert.equal(isPublic53OnlyContextFactoryChange(factory.replace(addition,''),factory),true);
 const readEnvironment="config('content_promotion.public_content_53_environment', 'production')";
 const legacyFactory=factory.replace(readEnvironment,"(getenv('PUBLIC_CONTENT_53_ENVIRONMENT') ?: 'production')");
 assert.equal(isPublic53OnlyContextFactoryChange(legacyFactory,factory),true);
 assert.equal(isPublic53OnlyContextFactoryChange(factory.replace(readEnvironment,'PublicContent53Package::environment()'),factory),true);
 assert.equal(isPublic53OnlyContextFactoryChange(legacyFactory,factory.replace('ksort($nested','sort($nested')),false);
 assert.equal(isPublic53OnlyContextFactoryChange(factory.replace(addition,''),factory.replace('ksort($nested','sort($nested')),false);
});
test('public signature configuration does not make the package a private publisher dependency',()=>{
 const graph=phpConsumers(resolve(root,'..'));
 const publishers=graph.consumer([/\/Console\/Commands\/Packs2?Publish\.php$/, /\/Services\/Content\/ContentPackV2(?:Resolver|Materializer|RuntimeTruthService)\.php$/]);
 assert.equal(publishers.has('backend/app/Services/ContentPromotion/PublicContent53Package.php'),false);
});
test('publication transport fails closed and recovers malformed phase counts',async()=>{
 const env={PUBLIC_CONTENT_53_PUBLISH_ENVIRONMENT:'staging',DEPLOY_PATH:'/srv/app',DEPLOY_USER:'deploy',DEPLOY_HOST:'example.invalid',DEPLOY_PORT:'22',DEPLOY_SHA:'a'.repeat(40),GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'k'.repeat(40)};
 const execution=buildExecution(env,root);assert.ok(execution.args.includes('StrictHostKeyChecking=yes'));assert.ok(execution.args.includes('ServerAliveInterval=15'));assert.ok(execution.args.includes('ServerAliveCountMax=8'));assert.ok(execution.args.includes('TCPKeepAlive=yes'));
 let recovery=0;
 await assert.rejects(()=>publish(execution,async request=>{if(request.mode==='recover'){recovery++;return {status:0,stdout:JSON.stringify({ok:true,mode:'recover',restored:false,recovery_status:'not_required',source_commit:request.source_commit,workflow_run_id:request.workflow_run_id,workflow_run_attempt:1,package_sha256:request.package_sha256,executor_release_sha256:request.executor_release_sha256,sanitized:true})};}return {status:0,stdout:'{}'};}),error=>error.receipt.recovery_completed === true);
 assert.equal(recovery,1);
});
test('DOM acceptance cannot pass from script-only content or HTTP availability',()=>{
 const row={page_id:'PAGE-02',kind:'page',identity:{locale:'en',slug:'about'},snapshot:{title:'About',content_md:'Expected visible paragraph.'}};
 const html='<html><head><link rel="canonical" href="https://fermatmind.com/en/about"></head><body><main><h1>About</h1><p>Expected visible paragraph.</p></main></body></html>';
 const before={dom:{canonical:['https://fermatmind.com/en/about'],robots:[],alternates:[]}};
 assertDom(row,html,before,'production');
  assertDom(row,html.replace('<main>', '<main><main>').replace('</main>', '</main></main>'),before,'production');
  assert.throws(()=>assertDom(row,html.replace('</main>', '<h1>Duplicate title</h1></main>'),before,'production'),/DOM_STRUCTURE/);
 assert.throws(()=>assertDom(row,html.replace('<p>Expected visible paragraph.</p>','<script>Expected visible paragraph.</script>'),before,'production'),/VISIBLE_BODY/);
 assert.equal(pathFor({kind:'surface',identity:{locale:'zh-CN',slug:'tests_category_career'}}),'/zh/tests/category/career');
});
test('publication retains bounded page diagnostics and separate recovery errors without raw output',async()=>{
 const execution={request:{source_commit:'a'.repeat(40),workflow_run_id:'123'},binding:{package_sha256:'b'.repeat(64),executor_release_sha256:'c'.repeat(64)}};
 const failed=()=>({status:1,stdout:JSON.stringify({ok:false,error_code:'public_content_53_revalidation_http_429'})});
 const error=await recoveryFailure(execution,failed,new Error('PUBLIC_CONTENT_53_VISIBLE_BODY_PAGE-02_ITEM_14'));
 assert.equal(error.receipt.error_code,'PUBLIC_CONTENT_53_VISIBLE_BODY_PAGE-02_ITEM_14');
 assert.equal(error.receipt.recovery_error_code,'public_content_53_revalidation_http_429');
 assert.equal(error.receipt.recovery_completed,false);
 const unsafe=await recoveryFailure(execution,failed,new Error('https://private.invalid/token=secret'));
 assert.equal(unsafe.receipt.error_code,'PUBLIC_CONTENT_53_PUBLICATION_FAILED');
 assert.ok(!JSON.stringify(unsafe.receipt).includes('secret'));
});
test('fenced Markdown requires visible code content without treating language delimiters as reader copy',()=>{
 const row={page_id:'PAGE-04',kind:'article',identity:{locale:'en',slug:'task-plan'},snapshot:{title:'Task plan',content_md:'Use this chain:\n\n```text\nProject → deliverable → next action → 15-minute start\n```'}};
 const code='<pre><code>Project → deliverable → next action → 15-minute start</code></pre>';
 const html='<html><head><meta charset="utf-8"><link rel="canonical" href="https://fermatmind.com/en/articles/task-plan"></head><body><main><h1>Task plan</h1><p>Use this chain:</p>'+code+'</main></body></html>';
 const before={dom:{canonical:['https://fermatmind.com/en/articles/task-plan'],robots:[],alternates:[]}};
 assertDom(row,html,before,'production');
 assert.throws(()=>assertDom(row,html.replace(code,''),before,'production'),/VISIBLE_BODY/);
 assert.throws(()=>assertDom(row,html.replace(code,'<script>Project → deliverable → next action → 15-minute start</script>'),before,'production'),/VISIBLE_BODY/);
});

test('API transport errors retain only recognized causes without endpoint or response bytes',async()=>{
 await assert.rejects(()=>read('https://example.invalid',async()=>{throw new TypeError('private endpoint',{cause:{code:'UND_ERR_SOCKET'}})}),/PUBLIC_CONTENT_53_API_TRANSPORT_UND_ERR_SOCKET/);
 await assert.rejects(()=>read('https://example.invalid',async()=>{throw new Error('private body')}),/PUBLIC_CONTENT_53_API_TRANSPORT_FAILED/);
 await assert.rejects(()=>read('https://example.invalid',async()=>({status:200,text:async()=>{throw new Error('private response body')}})),/PUBLIC_CONTENT_53_API_BODY_TRANSPORT_FAILED/);
});
test('inline citation labels and their rendered bibliography remain required visible copy',()=>{
 const row={page_id:'PAGE-08',kind:'article',identity:{locale:'en',slug:'trait-evidence'},snapshot:{title:'Trait evidence',content_md:'Trait dimensions are continuous.[^1]\n\n[^1]: John, O. P. (2008). *Trait taxonomy*. Guilford Press.'}};
 const reference='<ol><li><span>【1】</span><span>John, O. P. (2008). <em>Trait taxonomy</em>. Guilford Press.</span></li></ol>';
 const html='<html><head><meta charset="utf-8"><link rel="canonical" href="https://fermatmind.com/en/articles/trait-evidence"></head><body><main><h1>Trait evidence</h1><p>Trait dimensions are continuous.<sup>【1】</sup></p>'+reference+'</main></body></html>';
 const before={dom:{canonical:['https://fermatmind.com/en/articles/trait-evidence'],robots:[],alternates:[]}};
 assertDom(row,html,before,'production');
 assert.throws(()=>assertDom(row,html.replace(reference,''),before,'production'),/VISIBLE_BODY/);
});

test('async publication accepts the complete signed protocol without invoking recovery',async()=>{
 const execution={request:{source_commit:'a'.repeat(40),workflow_run_id:'123',mode:'publish'},binding:{package_sha256:'b'.repeat(64),expected_row_count:1}};
 const calls=[];
 const receipt=await publish(execution,async request=>{
  calls.push(request.mode);await new Promise(resolve=>setImmediate(resolve));
  return {status:0,stdout:JSON.stringify({schema:'public.content53.publish.v1',ok:true,source_commit:request.source_commit,workflow_run_id:request.workflow_run_id,workflow_run_attempt:1,package_sha256:execution.binding.package_sha256,published_count:1,sanitized:true,phases:['preflight','draft-import','publish','live-qa'].map(phase=>({phase,readback_count:1,receipt_sha256:'c'.repeat(64)}))})};
 });
 assert.deepEqual(calls,['publish']);assert.equal(receipt.ok,true);assert.equal(receipt.published_count,1);
 const invalid=()=>({status:0,stdout:'invalid private response bytes'});
 await assert.rejects(()=>publish(execution,invalid),error=>error.receipt.error_code==='PUBLIC_CONTENT_53_PUBLICATION_JSON_INVALID'&&error.receipt.transport_response?.shape==='text'&&!JSON.stringify(error.receipt).includes('private'));
});


test('content-page field markers follow the real template without dropping reader headings or paragraphs',()=>{
 const row={page_id:'PAGE-52',kind:'page',identity:{locale:'zh-CN',slug:'science'},snapshot:{title:'测评科学',content_md:'# content_md\n## **content-html**\n### `visible faq items`\ncontent_md: 真实正文必须保留。\n## 方法边界\n仍需验证。'}};
 const html='<html><head><meta charset="utf-8"><link rel="canonical" href="https://fermatmind.com/zh/science"></head><body><main><h1>测评科学</h1><p>真实正文必须保留。</p><h2>方法边界</h2><p>仍需验证。</p></main></body></html>';
 const before={dom:{canonical:['https://fermatmind.com/zh/science'],robots:[],alternates:[]}};
 assertDom(row,html,before,'production');
 assert.throws(()=>assertDom(row,html.replace('真实正文必须保留。',''),before,'production'),/VISIBLE_BODY/);
 assert.throws(()=>assertDom(row,html.replace('<h2>方法边界</h2>',''),before,'production'),/VISIBLE_BODY/);
});
test('bare internal paths require visible labels on the matching public route and retain surrounding copy',()=>{
 const row={page_id:'PAGE-30',kind:'page',identity:{locale:'en',slug:'science'},snapshot:{title:'Science',content_md:'Related pages include /method-boundaries and /item-design-notes.'}};
 const html='<html><head><meta charset="utf-8"><link rel="canonical" href="https://fermatmind.com/en/science"></head><body><main><h1>Science</h1><p>Related pages include <a href="/method-boundaries">Method boundaries</a> and <a href="/en/item-design-notes">Item design notes</a>.</p></main></body></html>';
 const before={dom:{canonical:['https://fermatmind.com/en/science'],robots:[],alternates:[]}};
 assertDom(row,html,before,'production');
 for(const replacement of ['https://external.invalid/method-boundaries','/wrong-route','/zh/method-boundaries'])assert.throws(()=>assertDom(row,html.replace('href="/method-boundaries"','href="'+replacement+'"'),before,'production'),/LINK/);
 assert.throws(()=>assertDom(row,html.replace('Related pages include ',''),before,'production'),/VISIBLE_BODY/);
 assert.throws(()=>assertDom(row,html.replace('<a href="/method-boundaries">','<a hidden href="/method-boundaries">'),before,'production'),/LINK/);
});


test('failed SSH exit is classified before JSON parsing and still invokes exact recovery',async()=>{
 const execution={request:{source_commit:'a'.repeat(40),workflow_run_id:'123',mode:'publish'},binding:{package_sha256:'b'.repeat(64),executor_release_sha256:'c'.repeat(64)}};
 const calls=[];
 await assert.rejects(()=>publish(execution,async request=>{
  calls.push(request.mode);
  if(request.mode==='publish')return {status:255,stdout:'private transport bytes'};
  return {status:0,stdout:JSON.stringify({ok:true,mode:'recover',source_commit:request.source_commit,workflow_run_id:request.workflow_run_id,workflow_run_attempt:1,package_sha256:execution.binding.package_sha256,executor_release_sha256:execution.binding.executor_release_sha256,restored:true,recovery_status:'restored',sanitized:true})};
 }),error=>error.receipt.error_code==='PUBLIC_CONTENT_53_SSH_EXIT_255'&&error.receipt.recovery_completed===true&&!JSON.stringify(error.receipt).includes('private'));
 assert.deepEqual(calls,['publish','recover']);
});


test('nonzero remote business failures retain their typed cause rather than becoming SSH errors',async()=>{
 const execution={request:{source_commit:'a'.repeat(40),workflow_run_id:'123',mode:'publish'},binding:{package_sha256:'b'.repeat(64),expected_row_count:1}};
 await assert.rejects(()=>publish(execution,async()=>({status:1,stdout:JSON.stringify({ok:false,error_code:'public_content_53_revalidation_http_429',sanitized:true})})),error=>error.receipt.error_code==='public_content_53_revalidation_http_429');
});

test('full-page metadata must expose the same approved translated candidate',()=>{
 const row={page_id:'PAGE-METHOD-BOUNDARIES',kind:'page',identity:{locale:'en',slug:'method-boundaries'},snapshot:{title:'Method boundaries',seo_title:'Assessment Methods',meta_description:'Complete interpretation and usage boundaries.',summary:null,content_md:'Expected paragraph.'}};
 const html='<html><head><title>Assessment Methods | FermatMind</title><meta name="description" content="Complete interpretation and usage boundaries."><link rel="canonical" href="https://fermatmind.com/en/method-boundaries"></head><body><main><h1>Method boundaries</h1><p>Expected paragraph.</p></main></body></html>';
 const before={dom:{canonical:['https://fermatmind.com/en/method-boundaries'],robots:[],alternates:[]}};
 assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
 assert.throws(()=>assertDom(row,html.replace('Complete interpretation and usage boundaries.','Old English summary.'),before,'production'),/METADATA/);
});

test('ASCII FAQ control comments stay in the exact API candidate without becoming reader copy',()=>{
 const row={page_id:'PAGE-HELP-PAYMENT',kind:'page',identity:{locale:'en',slug:'help-payment-refund'},snapshot:{title:'Payment',content_md:'<!-- help-service-visible-faq:start -->\n\n### Refund conditions\n\nRequest within 7 days.\n\n<!-- help-service-visible-faq:end -->'}};
 const before={dom:{canonical:['https://fermatmind.com/en/help/payment-refund'],robots:[],alternates:[]}};
 const html='<html><head><link rel="canonical" href="https://fermatmind.com/en/help/payment-refund"></head><body><main><h1>Payment</h1><h3>Refund conditions</h3><p>Request within 7 days.</p></main></body></html>';
 assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
 assert.throws(()=>assertDom(row,html.replace('Request within 7 days.',''),before,'production'),/VISIBLE_BODY/);
});

test('category acceptance binds metadata and visible sections without mistaking unused final CTA data for DOM prose',()=>{
 for(const row of readCandidateRows(root,translationDigest()).candidates.filter(row=>['PAGE-33','PAGE-34'].includes(row.page_id))) {
  const payload=row.snapshot.payload_json,path=pathFor(row);
  const strings=value=>typeof value==='string'?[value]:value&&typeof value==='object'?Object.values(value).flatMap(strings):[];
  const copy=['hero','featured','allTests','differences','trust','resources','breadcrumb'].flatMap(key=>strings(payload[key]));
  const h1=row.page_id==='PAGE-33'?'Free career tests':'Free personality tests';
  const head='<html><head><title>'+payload.seo.title+' | FermatMind</title><meta name="description" content="'+payload.seo.description+'"><link rel="canonical" href="https://fermatmind.com'+path+'"></head><body><main><h1>'+h1+'</h1>';
  const html=head+copy.map(s=>'<p>'+s+'</p>').join('')+'</main></body></html>';
  const before={dom:{canonical:['https://fermatmind.com'+path],robots:[],alternates:[]}};
  assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
  assert.throws(()=>assertDom(row,html.replace(payload.seo.description,'Old SEO summary'),before,'production'),/METADATA/);
  for(const [index,item] of payload.differences.items.entries()) if(item.description !== row.before.payload_json.differences.items[index].description) assert.throws(()=>assertDom(row,html.replaceAll('<p>'+item.description+'</p>',''),before,'production'),/VISIBLE_BODY/);
 }
});

test('active single-locale guide amendment protects identity and checks real body_md consumer',()=>{
 const row=readCandidateRows(root).candidates[0];assert.equal(row.kind,'guide');
 assert.equal(inspectPackage(root).expected_row_count,1);assert.equal(row.identity.slug,'from-mbti-to-job-fit');
 assert.ok(['en','zh-CN'].includes(row.identity.locale));
 for(const before of [row.before,row.staging_before]) for(const field of ['title','excerpt']) assert.equal(before[field],row.snapshot[field]);
 const native={...row.identity,...row.snapshot,guide_code:row.identity.slug,is_public:true};
 assert.deepEqual(projection(row,{ok:true,guide:native}),row.snapshot);
 for(const patch of [{org_id:1},{guide_code:'other'},{locale:'fr'},{slug:'other'},{is_public:false}])
  assert.throws(()=>projection(row,{ok:true,guide:{...native,...patch}}),/IDENTITY_DRIFT/);
 const small={...row,snapshot:{title:'Career choices',body_md:'Required new answer.\n\n[Interview practice](/en/career/guides/interview-strategy-by-role)'}};
 const url='https://fermatmind.com'+pathFor(small);const before={dom:{canonical:[url],robots:[],alternates:[]}};
 const html=`<html><head><link rel="canonical" href="${url}"></head><body><main><h1>Career choices</h1><p>Required new answer.</p><a href="/en/career/guides/interview-strategy-by-role">Interview practice</a></main></body></html>`;
 assert.doesNotThrow(()=>assertDom(small,html,before,'production'));
 assert.throws(()=>assertDom(small,html.replace('<p>Required new answer.</p>','<script>Required new answer.</script>'),before,'production'),/VISIBLE_BODY/);
 assert.throws(()=>assertDom(small,html.replace('href="/en/career/guides/interview-strategy-by-role"','href="/en/career/guides/other"'),before,'production'),/LINK/);
});
