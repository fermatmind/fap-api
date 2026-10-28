import test from 'node:test';
import { classifyPaths } from './classify-paths.mjs';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { selectPublicContentPackage, packageDigest, executorPaths, inspectPackage, readCandidateRows, isPublic53OnlyPromotionRegistration, isPublic53OnlyContextFactoryChange } from './public-content-53-package.mjs';
import { buildExecution, publish, recoveryFailure } from './public-content-53-publish.mjs';
import { assertNewArticleDiscovery, assertDom, domOf, pathFor, read, projection, readOnlinePrestate, verifyOnlineCandidates } from './public-content-53-online-qa.mjs';
import { phpConsumers } from './impact-consumers.mjs';
test('Chinese directory card package selects one native surface and retains serial publication',()=>{
 const paths=['backend/content_assets/public_content_53/directory-card-copy.json','backend/content_assets/public_content_53/directory-card-copy-review.json'];
 assert.equal(selectPublicContentPackage(paths),'directory-card-copy');
 assert.throws(()=>selectPublicContentPackage([...paths,'backend/content_assets/public_content_53/article-amendment.json']),/MULTIPLE_PACKAGES/);
 const binding=inspectPackage(root,'directory-card-copy');
 assert.equal(binding.expected_row_count,1);
 const rows=readCandidateRows(root,binding.package_sha256).candidates;
 assert.equal(rows.length,1);
 assert.deepEqual(rows[0].identity,{org_id:0,slug:'tests',locale:'zh-CN'});
 assert.equal(pathFor(rows[0]),'/zh/tests');
});

test('Chinese directory card DOM acceptance requires the changed labels and preserves metadata',()=>{
 const raw=readCandidateRows(root,packageDigest(root,'directory-card-copy')).candidates[0];
 const escape=x=>x.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
 for(const environment of ['production','staging']) {
  const row=environment==='staging'?{...raw,snapshot:raw.staging_snapshot}:raw;
  const p=row.snapshot.payload_json,labels=['93 / 144 题','约 10 / 15 分钟','90 / 120 题','约 11 / 15 分钟'];
  const html=`<html><head><meta charset="utf-8"><title>${escape(p.seo.title)} | FermatMind</title><meta name="description" content="${escape(p.seo.description)}"><link rel="canonical" href="https://fermatmind.com/zh/tests"></head><body><main><h1>${escape(p.hero.title)}</h1>${labels.map(x=>`<p>${x}</p>`).join('')}</main></body></html>`;
  const before={dom:domOf(html)};
  assert.doesNotThrow(()=>assertDom(row,html,before,environment));
  for(const label of ['约 10 / 15 分钟','约 11 / 15 分钟'])
   assert.throws(()=>assertDom(row,html.replace(label,'旧预计用时'),before,environment),/VISIBLE_BODY/);
  assert.throws(()=>assertDom(row,html.replace('<title>','<title>Unselected '),before,environment),/METADATA/);
 }
});
process.env.PUBLIC_CONTENT_53_PACKAGE_NAME='riasec-code-samples';
const root=fileURLToPath(new URL('../../backend',import.meta.url));
const riasecDigest=()=>createHash('sha256').update('fermatmind.public_content_article_amendment.v1\n'+['riasec-amendment.json','riasec-amendment-review.json'].map(name=>`${name}\n${createHash('sha256').update(readFileSync(resolve(root,'content_assets/public_content_53',name))).digest('hex')}\n`).join('')).digest('hex');
const guideDigest=()=>createHash('sha256').update('fermatmind.public_content_guide_amendment.v1\n'+['guide-amendment.json','guide-amendment-review.json'].map(name=>`${name}\n${createHash('sha256').update(readFileSync(resolve(root,'content_assets/public_content_53',name))).digest('hex')}\n`).join('')).digest('hex');
const translationDigest=()=>createHash('sha256').update('fermatmind.public_content_translation.v1\n'+['translation-batch.json','translation-review.json'].map(name=>`${name}\n${createHash('sha256').update(readFileSync(resolve(root,'content_assets/public_content_53',name))).digest('hex')}\n`).join('')).digest('hex');
const copyDigest=()=>createHash('sha256').update('fermatmind.public_content_copy_amendment.v1\n'+['copy-amendment.json','copy-amendment-review.json'].map(name=>`${name}\n${createHash('sha256').update(readFileSync(resolve(root,'content_assets/public_content_53',name))).digest('hex')}\n`).join('')).digest('hex');
const alignmentDigest=()=>createHash('sha256').update('fermatmind.public_content_seo_alignment.v1\n'+['seo-alignment.json','seo-alignment-review.json'].map(name=>`${name}\n${createHash('sha256').update(readFileSync(resolve(root,'content_assets/public_content_53',name))).digest('hex')}\n`).join('')).digest('hex');
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
test('AI method gate-only amendment binds the current published language bodies',()=>{
 const sha=bytes=>createHash('sha256').update(bytes).digest('hex');const digest=sha('fermatmind.public_content_article_amendment.v1\n'+['article-amendment.json','article-amendment-review.json'].map(name=>`${name}\n${sha(readFileSync(resolve(root,'content_assets/public_content_53',name)))}\n`).join(''));const rows=readCandidateRows(root,digest).candidates;
 assert.deepEqual(rows.map(row=>row.page_id),['PAGE-AI-ZH','PAGE-17']);
 assert.deepEqual(rows.map(row=>row.identity),['zh-CN','en'].map(locale=>({org_id:0,slug:'how-16-personality-types-talk-to-an-ai-coach',locale})));
 for(const row of rows) {
  assert.equal(row.kind,'article');
  assert.equal(row.enable_hreflang,true);
  assert.deepEqual(row.before,row.snapshot);
  assert.deepEqual(row.staging_before,row.snapshot);
  assert.deepEqual(Object.keys(row.snapshot).sort(),['content_md','excerpt','title']);
  for(const before of [row.before,row.staging_before]) {
   assert.equal(row.snapshot.title,before.title);assert.equal(row.snapshot.excerpt,before.excerpt);
  }
  assert.ok(row.snapshot.content_md.includes(row.identity.locale==='en'?'Synthetic exercise: turn an interest clue':'合成案例：把兴趣线索'));
 }
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
 const env={PUBLIC_CONTENT_53_PACKAGE_NAME:'riasec-code-samples',PUBLIC_CONTENT_53_PUBLISH_ENVIRONMENT:'staging',DEPLOY_PATH:'/srv/app',DEPLOY_USER:'deploy',DEPLOY_HOST:'example.invalid',DEPLOY_PORT:'22',DEPLOY_SHA:'a'.repeat(40),GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'k'.repeat(40)};
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
 for(const id of ['RIASEC-RIC-zh-CN','RIASEC-IAR-en','RIASEC-SAE-en','EN-GUIDE-annual-career-review-system','EN-GUIDE-build-five-year-career-roadmap','EN-GUIDE-build-portfolio-for-career-switch','EN-GUIDE-cross-industry-move-strategy']) {
  for(const diagnostic of [`PUBLIC_CONTENT_53_NEW_ARTICLE_SEO_${id}`,`PUBLIC_CONTENT_53_VISIBLE_BODY_${id}_ITEM_14`]) {
   const sample=await recoveryFailure(execution,failed,new Error(diagnostic));
   assert.equal(sample.receipt.error_code,diagnostic);
  }
 }
 for (const code of ['MBTI_RESULT_COPY_QA_BROWSER_FAILED','MBTI_RESULT_COPY_QA_NATIVE_READBACK_FAILED','MBTI_RESULT_COPY_QA_DERIVED_BODY_MISMATCH']) {
  const sample=await recoveryFailure(execution,failed,new Error(code));
  assert.equal(sample.receipt.error_code,code);
 }
 for (const code of ['MBTI_RESULT_COPY_QA_fm_private_token','MBTI_RESULT_COPY_QA_ATTEMPT_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']) {
  const sample=await recoveryFailure(execution,failed,new Error(code));
  assert.equal(sample.receipt.error_code,'PUBLIC_CONTENT_53_PUBLICATION_FAILED');
 }
 const unknown=await recoveryFailure(execution,failed,new Error('PUBLIC_CONTENT_53_VISIBLE_BODY_RIASEC-XYZ-en'));
 assert.equal(unknown.receipt.error_code,'PUBLIC_CONTENT_53_PUBLICATION_FAILED');
 const unknownGuide=await recoveryFailure(execution,failed,new Error('PUBLIC_CONTENT_53_VISIBLE_BODY_EN-GUIDE-other-guide'));
 assert.equal(unknownGuide.receipt.error_code,'PUBLIC_CONTENT_53_PUBLICATION_FAILED');
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
test('HTML-only Article reads retain strict transport while requesting a complete crawler body',async()=>{
 let request;
 assert.equal(await read('https://example.invalid/article',async(_url,options)=>{request=options;return {status:200,text:async()=>'<main>Complete article</main>'};},false,{'User-Agent':'facebookexternalhit/1.1'}),'<main>Complete article</main>');
 assert.equal(request.headers['User-Agent'],'facebookexternalhit/1.1');
 assert.equal(request.headers['Cache-Control'],'no-cache');
 assert.equal(request.redirect,'error');
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

test('previous single-locale guide amendment protects identity and checks real body_md consumer',()=>{
 const row=readCandidateRows(root,guideDigest()).candidates[0];assert.equal(row.kind,'guide');
 assert.equal(row.identity.slug,'from-mbti-to-job-fit');
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

test('previous copy amendment binds six native resources and checks metadata plus unchanged reader body',()=>{
 const rows=readCandidateRows(root,copyDigest()).candidates;
 assert.deepEqual(rows.map(row=>row.page_id),['PAGE-23','PAGE-32','PAGE-34','PAGE-33','PAGE-HOME','PAGE-17']);
 const row=rows[0],seo=row.snapshot.seo_text,url='https://fermatmind.com'+pathFor(row);
 const native={...row.identity,status:'published',is_public:true,seo_meta:seo};
 assert.deepEqual(projection(row,{ok:true,article:native}),row.snapshot);
 assert.notDeepEqual(projection(row,{ok:true,article:{...native,seo_meta:{...seo,seo_description:'Stale'}}}),row.snapshot);
 const html=`<html><head><meta charset="utf-8"><title>${seo.seo_title} | FermatMind</title><meta name="description" content="${seo.seo_description}"><meta property="og:title" content="${seo.og_title}"><meta property="og:description" content="${seo.og_description}"><link rel="canonical" href="${url}"></head><body><main><h1>Seven types of love</h1><article data-testid="article-detail-content"><p>Retain this explanation.</p></article></main></body></html>`;
 const before={dom:domOf(html)};
 assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
 assert.throws(()=>assertDom(row,html.replaceAll(seo.seo_description,'Old four dimensions'),before,'production'),/METADATA/);
 assert.throws(()=>assertDom(row,html.replace('Retain this explanation.','Changed explanation.'),before,'production'),/BODY_DRIFT/);
 assert.throws(()=>assertDom(row,html.replace('<h1>Seven types of love</h1>','<h1>Wrong heading</h1>'),before,'production'),/BODY_DRIFT/);
});


function streamedArticlePrestateFixture() {
 const rows=readCandidateRows(root,copyDigest()).candidates,row=rows.find(row=>row.page_id==='PAGE-23');
 const body='<p>Keep the complete explanation.</p><p>Keep the streamed continuation too.</p>';
 const html=seo=>`<html><head><meta charset="utf-8"><title>${seo.seo_title} | FermatMind</title><meta name="description" content="${seo.seo_description}"><meta property="og:title" content="${seo.og_title}"><meta property="og:description" content="${seo.og_description}"><link rel="canonical" href="https://fermatmind.com${pathFor(row)}"></head><body><main><h1>Seven types of love</h1><article data-testid="article-detail-content">${body}</article></main></body></html>`;
 const before=html(row.staging_before.seo_text),after=html(row.snapshot.seo_text);
 const raw=before.replace('<p>Keep the streamed continuation too.</p>','');
 const requests=[];
 const fetcher=async url=>{
  requests.push(url);
  if(!url.includes('/api/v0.5/')) return {status:200,text:async()=>raw};
  const index=rows.findIndex(item=>url.includes('/'+item.identity.slug+'?')),item=rows[index];
  assert.ok(item);
  const native={...item.identity,...item.staging_before,id:index+1,status:'published',is_public:true};
  if(item.kind==='article') native.seo_meta=item.staging_before.seo_text;
  if(item.staging_before.block_copy) native.page_blocks=Object.entries(item.staging_before.block_copy).map(([block_key,payload_json])=>({block_key,payload_json,is_enabled:true}));
  return {status:200,text:async()=>JSON.stringify({ok:true,[item.kind]:native})};
 };
 return {row,before,after,raw,fetcher,requests};
}

test('metadata-only Article prestate uses the same rendered reader as post-publication QA',async()=>{
 const f=streamedArticlePrestateFixture(),renders=[];
 const prestate=await readOnlinePrestate('staging',root,f.fetcher,async(url,options)=>{
  renders.push({url,surface:options.surface});return f.before;
 },copyDigest());
 assert.doesNotThrow(()=>assertDom(f.row,f.after,prestate[f.row.page_id],'staging'));
 assert.deepEqual(renders,readCandidateRows(root,copyDigest()).candidates.filter(row=>row.kind==='article').map(row=>({url:'https://staging.fermatmind.com'+pathFor(row),surface:'article'})));
 assert.equal(f.requests.filter(url=>!url.includes('/api/v0.5/') && url.includes('/articles/')).length,0);
 assert.throws(()=>assertDom(f.row,f.after,{dom:domOf(f.raw)},'staging'),/BODY_DRIFT/);
 assert.throws(()=>assertDom(f.row,f.after.replace('streamed continuation','changed continuation'),prestate[f.row.page_id],'staging'),/BODY_DRIFT/);
});

test('prestate renderer failure propagates without a raw HTML fallback',async()=>{
 const f=streamedArticlePrestateFixture();
 await assert.rejects(readOnlinePrestate('staging',root,f.fetcher,async()=>{throw new Error('render unavailable');},copyDigest()),/render unavailable/);
 assert.equal(f.requests.length,1);
});

test('one failed read-only browser can reopen the same prestate without a raw HTML fallback',async()=>{
 const f=streamedArticlePrestateFixture(),renders=[];
 const result=await readOnlinePrestate('staging',root,f.fetcher,async(url,options)=>{
  renders.push({url,surface:options.surface});
  if(renders.length===1) throw new Error('IQ_ARTICLE_ONLINE_RENDER_FAILED');
  return f.before;
 },copyDigest());
 assert.deepEqual(renders[0],renders[1]);
 assert.equal(renders.length,readCandidateRows(root,copyDigest()).candidates.filter(row=>row.kind==='article').length+1);
 assert.equal(f.requests.filter(url=>!url.includes('/api/v0.5/')&&url.includes('/articles/')).length,0);
 assert.doesNotThrow(()=>assertDom(f.row,f.after,result[f.row.page_id],'staging'));
 assert.throws(()=>assertDom(f.row,f.after.replace('streamed continuation','missing continuation'),result[f.row.page_id],'staging'),/BODY_DRIFT/);
});

test('persistent prestate browser failure stops after two reads with a safe page identity',async()=>{
 const f=streamedArticlePrestateFixture();let attempts=0;
 const first=readCandidateRows(root,copyDigest()).candidates[0];
 await assert.rejects(readOnlinePrestate('staging',root,f.fetcher,async()=>{
  attempts++;throw new Error('IQ_ARTICLE_ONLINE_RENDER_FAILED');
 },copyDigest()),{message:'PUBLIC_CONTENT_53_PRESTATE_BROWSER_FAILED_'+first.page_id});
 assert.equal(attempts,2);
 assert.equal(f.requests.length,1);
});

test('unparseable prestate DOM fails immediately with its page identity',async()=>{
 const f=streamedArticlePrestateFixture();let attempts=0;
 const first=readCandidateRows(root,copyDigest()).candidates[0];
 await assert.rejects(readOnlinePrestate('staging',root,f.fetcher,async()=>{
  attempts++;return '';
 },copyDigest()),{message:'PUBLIC_CONTENT_53_PRESTATE_DOM_FAILED_'+first.page_id});
 assert.equal(attempts,1);
 assert.equal(f.requests.length,1);
});

test('home copy is consumed by ItemList while the visible IQ card retains the actual reader contract',()=>{
 const row=readCandidateRows(root,copyDigest()).candidates.find(row=>row.page_id==='PAGE-HOME');
 const items=row.snapshot.payload_json.quickStart.items;
 const list={'@type':'ItemList','@id':'https://fermatmind.com/en#quickstart-itemlist',inLanguage:'en',numberOfItems:items.length,
  itemListElement:items.map((item,index)=>({'@type':'ListItem',position:index+1,name:item.title,description:item.description,url:'https://fermatmind.com'+(item.href.startsWith('/en/')?item.href:'/en'+item.href)}))};
 const card='Explore visual reasoning with 30 original matrix questions. See your correct-answer count and accuracy.';
 const detail='The current FermatMind IQ test is an original 30-question visual matrix reasoning exercise. Review your performance on this form; it does not replace a professional intelligence assessment.';
 const html=`<html><head><meta charset="utf-8"><link rel="canonical" href="https://fermatmind.com/en"><script type="application/ld+json">${JSON.stringify(list)}</script></head><body><main><h1>Understand yourself</h1><article><a href="/en/tests/iq-test-intelligence-quotient-assessment/take">${card}<span>30 questions</span><span>20 min</span></a><p>${detail}</p></article></main></body></html>`;
 const before={dom:domOf(html)};
 assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
 assert.throws(()=>assertDom(row,html.replace(row.snapshot.payload_json.quickStart.items[4].description,'Raw score only'),before,'production'),/HOME_JSONLD/);
 assert.throws(()=>assertDom(row,html.replace(card,'Raw score only'),before,'production'),/HOME_IQ_CARD/);
 assert.throws(()=>assertDom(row,html.replace('30 questions','60 questions'),before,'production'),/HOME_IQ_CARD/);
 const native={surface_key:'home',locale:'en',status:'published',is_public:true,...row.snapshot,
  page_blocks:Object.entries(row.snapshot.block_copy).map(([block_key,payload_json])=>({block_key,payload_json,is_enabled:true}))};
 assert.deepEqual(projection(row,{ok:true,surface:native}),row.snapshot);
 assert.throws(()=>projection(row,{ok:true,surface:{...native,page_blocks:[]}}),/BLOCK_IDENTITY_DRIFT/);
});

test('reviewed AI-coach heading changes while its explanatory body stays protected',()=>{
 const row=readCandidateRows(root,copyDigest()).candidates.find(row=>row.page_id==='PAGE-17'),seo=row.snapshot.seo_text;
 const html=`<html><head><meta charset="utf-8"><title>${seo.seo_title} | FermatMind</title><meta name="description" content="${seo.seo_description}"><meta property="og:title" content="${seo.og_title}"><meta property="og:description" content="${seo.og_description}"><link rel="canonical" href="https://fermatmind.com${pathFor(row)}"></head><body><main><h1>${row.snapshot.title}</h1><article data-testid="article-detail-content"><p>Eight illustrative patterns and their limits.</p></article></main></body></html>`;
 const before={dom:domOf(html.replace(row.snapshot.title,row.before.title))};
 assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
 assert.throws(()=>assertDom(row,html.replace(row.snapshot.title,row.before.title),before,'production'),/H1/);
 assert.throws(()=>assertDom(row,html.replace('Eight illustrative patterns','Sixteen validated types'),before,'production'),/BODY_DRIFT/);
});

test('bilingual alignment binds exactly ten native identities and preserves other assessment cards',()=>{
 const digest=createHash('sha256').update('fermatmind.public_content_seo_alignment.v1\n'+['seo-alignment.json','seo-alignment-review.json'].map(name=>`${name}\n${createHash('sha256').update(readFileSync(resolve(root,'content_assets/public_content_53',name))).digest('hex')}\n`).join('')).digest('hex');
 const rows=readCandidateRows(root,digest).candidates;
 assert.deepEqual(rows.map(row=>row.page_id),Array.from({length:10},(_,i)=>`PAGE-${i+55}`));
 assert.equal(rows.filter(row=>row.kind==='article').length,6);
 assert.equal(rows.filter(row=>row.kind==='surface').length,2);
 assert.equal(rows.filter(row=>row.kind==='page').length,2);
 for(const row of rows.filter(row=>row.kind==='surface')) {
  const cards=payload=>payload.families.items.flatMap(f=>f.tests ?? []).filter(c=>/mbti|iq-test/.test(c.key));
  assert.deepEqual(cards(row.before.payload_json),cards(row.snapshot.payload_json));
  assert.deepEqual(row.before.payload_json.seo.iq_ramp_authority,row.snapshot.payload_json.seo.iq_ramp_authority);
  assert.deepEqual(Object.keys(row.snapshot.block_copy),['hero','families']);
  assert.deepEqual(row.snapshot.block_copy.hero,row.snapshot.payload_json.hero);
  const {items,...familyCopy}=row.snapshot.block_copy.families;
  assert.deepEqual(items,row.before.block_copy.families.items);
  for(const [key,value] of Object.entries(familyCopy)) assert.deepEqual(value,row.snapshot.payload_json.families[key]);
 }
});

test('combined article body and search changes require the complete new visible body',()=>{
 const source=readCandidateRows(root,alignmentDigest()).candidates.find(row=>row.page_id==='PAGE-60');
 const row={...source,snapshot:{...source.snapshot,content_md:'New supported answer.\n\nA necessary qualification.'}};
 const seo=row.snapshot.seo_text,url='https://fermatmind.com'+pathFor(row);
 const head=`<meta charset="utf-8"><title>${seo.seo_title} | FermatMind</title><meta name="description" content="${seo.seo_description}"><meta property="og:title" content="${seo.og_title}"><meta property="og:description" content="${seo.og_description}"><link rel="canonical" href="${url}"><meta name="robots" content="index, follow">`;
 const html=`<html><head>${head}</head><body><main><h1>${row.snapshot.title}</h1><article data-testid="article-detail-content"><p>New supported answer.</p><p>A necessary qualification.</p></article></main></body></html>`;
 const before={dom:domOf(html.replace('New supported answer.','Previous answer.'))};
 assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
 assert.throws(()=>assertDom(row,html.replace('A necessary qualification.',''),before,'production'),/VISIBLE_BODY/);
 assert.throws(()=>assertDom(row,html.replaceAll(seo.seo_title,'Stale search title'),before,'production'),/METADATA/);
 assert.throws(()=>assertDom(row,html.replace(`<h1>${row.snapshot.title}</h1>`,'<h1>Old heading</h1>'),before,'production'),/H1/);
 const stagingHtml=html.replace('content="index, follow"','content="noindex, follow, noarchive, nocache"').replace('</head>','<link rel="alternate" type="application/atom+xml" href="https://fermatmind.com/en/articles/feed.xml"></head>');
 const stagingBefore={missing:true,stagingReferenceDom:{robots:['noindex, follow, noarchive, nocache'],alternates:['https://fermatmind.com/zh/articles/feed.xml'],alternate_entries:[{href:'https://fermatmind.com/zh/articles/feed.xml',type:'application/atom+xml',hreflang:''}]}};
 assert.doesNotThrow(()=>assertDom(row,stagingHtml,stagingBefore,'staging'));
 for(const altered of [stagingHtml.replace('type="application/atom+xml"','type="text/html"'),stagingHtml.replace('type="application/atom+xml"','type="application/atom+xml" hreflang="zh-CN"'),stagingHtml.replace('/en/articles/feed.xml','/en/articles/feed.xml?unexpected=1'),stagingHtml.replace('/en/articles/feed.xml','/en/articles/feed.xml#unexpected')])
  assert.throws(()=>assertDom(row,altered,stagingBefore,'staging'),/DISCOVERABILITY/);
 assert.throws(()=>assertDom(row,stagingHtml.replace('content="noindex, follow, noarchive, nocache"','content="index, follow"'),stagingBefore,'staging'),/DISCOVERABILITY/);
 assert.throws(()=>assertDom(row,stagingHtml.replace('</head>','<link rel="alternate" href="https://external.invalid/en/articles/foreign"></head>'),stagingBefore,'staging'),/DISCOVERABILITY/);
 assert.throws(()=>assertDom(row,stagingHtml.replace('https://fermatmind.com/en/articles/feed.xml','https://fermatmind.com/en/articles/foreign'),stagingBefore,'staging'),/DISCOVERABILITY/);
 assert.throws(()=>assertDom(row,stagingHtml,{missing:true},'staging'),/DISCOVERABILITY/);
 assert.throws(()=>assertDom(row,stagingHtml,stagingBefore,'production'));

});

test('missing baseline permission is limited to a native not-found response',async()=>{
 const missing=async()=>({status:404,text:async()=>JSON.stringify({ok:false,error_code:'NOT_FOUND'})});
 assert.equal(await read('https://staging-api.fermatmind.com/example',missing,true),null);
 await assert.rejects(read('https://staging-api.fermatmind.com/example',missing),/RESPONSE_INVALID/);
 for(const payload of ['<html>proxy error</html>',JSON.stringify({ok:false,error_code:'FORBIDDEN'}),JSON.stringify({ok:true})])
  await assert.rejects(read('https://staging-api.fermatmind.com/example',async()=>({status:404,text:async()=>payload}),true),/INVALID/);
});

test('staging missing locale binds its actual published counterpart metadata; other missing rows still fail',async()=>{
 const rows=readCandidateRows(root,alignmentDigest()).candidates;
 const fetcher=async url=>{
  if(!url.includes('/api/v0.5/')) return {status:200,text:async()=>'<html><head></head><main>Existing surface</main></html>'};
  const u=new URL(url),row=rows.find(r=>u.pathname.endsWith('/'+r.identity.slug) && u.searchParams.get('locale')===r.identity.locale);
  assert.ok(row);
  if(row.page_id==='PAGE-60')return {status:404,text:async()=>JSON.stringify({ok:false,error_code:'NOT_FOUND'})};
  const native={...row.identity,...row.staging_before,id:Number(row.page_id.slice(5)),status:'published',is_public:true};
  if(row.kind==='article')native.seo_meta=row.staging_before.seo_text;
  if(row.kind==='surface')native.page_blocks=Object.entries(row.staging_before.block_copy).map(([block_key,payload_json])=>({block_key,payload_json,is_enabled:true}));
  return {status:200,text:async()=>JSON.stringify({ok:true,[row.kind]:native})};
 };
 const renderer=async()=>'<html><head><meta name="robots" content="noindex, follow, noarchive, nocache"><link rel="alternate" type="application/atom+xml" href="https://fermatmind.com/zh/articles/feed.xml"></head><main><h1>Existing article</h1></main></html>';
 const state=await readOnlinePrestate('staging',root,fetcher,renderer,alignmentDigest());
 assert.equal(state['PAGE-60'].missing,true);
 assert.deepEqual(state['PAGE-60'].stagingReferenceDom,state['PAGE-59'].dom);
 assert.deepEqual(state['PAGE-60'].stagingReferenceDom.robots,['noindex, follow, noarchive, nocache']);
 await assert.rejects(readOnlinePrestate('production',root,async url=>url.includes('/articles/ocean-traits-what-the-five-letters-mean?locale=en')?{status:404,text:async()=>JSON.stringify({ok:false,error_code:'NOT_FOUND'})}:fetcher(url),renderer,alignmentDigest()),/PRESTATE_DRIFT|RESPONSE_INVALID/);
 await assert.rejects(readOnlinePrestate('staging',root,async()=>({status:404,text:async()=>JSON.stringify({ok:false,error_code:'NOT_FOUND'})}),renderer,alignmentDigest()),/RESPONSE_INVALID/);
});


test('alignment DOM deltas use the selected environment baseline without requiring retained staging action URLs',()=>{
 for(const source of readCandidateRows(root,alignmentDigest()).candidates.filter(row=>['PAGE-61','PAGE-62'].includes(row.page_id))) {
  for(const environment of ['staging','production']) {
   const row={...source,snapshot:environment==='staging'?source.staging_snapshot:source.snapshot};
   const payload=row.snapshot.payload_json,seo=payload.seo;
   const card=payload.families.items.flatMap(f=>f.tests).find(c=>c.key==='eq-test-emotional-intelligence-assessment');
   const url='https://fermatmind.com'+pathFor(row);
   const prose=[card.title,card.description,card.outputLabel];
   const html=`<html><head><meta charset="utf-8"><title>${seo.title} | FermatMind</title><meta name="description" content="${seo.description}"><link rel="canonical" href="${url}"></head><body><main><h1>${payload.hero.title}</h1>${prose.map(x=>'<p>'+x+'</p>').join('')}</main></body></html>`;
   const before={dom:domOf(html)};
   assert.doesNotThrow(()=>assertDom(row,html,before,environment));
   for(const text of prose) assert.throws(()=>assertDom(row,html.replace('<p>'+text+'</p>',''),before,environment),/VISIBLE_BODY/);
   const changed=structuredClone(row);
   for(const family of changed.snapshot.payload_json.families.items) for(const item of family.tests) {
    if(item.key==='eq-test-emotional-intelligence-assessment') item.description=card.description+' /'+(row.identity.locale==='en'?'en':'zh')+'/method-boundaries';
   }
   assert.throws(()=>assertDom(changed,html,before,environment),/LINK/);
   const linked=html.replace('<p>'+card.description+'</p>','<p>'+card.description+' <a href="/'+(row.identity.locale==='en'?'en':'zh')+'/method-boundaries">Read method boundaries</a></p>');
   assert.doesNotThrow(()=>assertDom(changed,linked,{dom:domOf(linked)},environment));
  }
 }
});


test('previous RIASEC publication selects four reviewed native bodies with exact bilingual slugs',()=>{
 const rows=readCandidateRows(root,riasecDigest()).candidates;
 assert.deepEqual(rows.map(row=>[row.page_id,row.identity.slug,row.identity.locale]),[
  ['PAGE-65','riasec-holland-career-interest-test-explained','zh-CN'],
  ['PAGE-66','what-is-riasec-holland-code-career-interest-test','en'],
  ['PAGE-67','mbti-vs-holland-career-choice','zh-CN'],
  ['PAGE-68','mbti-vs-holland-code-career-choice','en'],
 ]);
 for(const row of rows) {
  for(const [baseline,candidate] of [[row.before,row.snapshot],[row.staging_before,row.staging_snapshot]]) {
   assert.equal(candidate.title,baseline.title);
   assert.equal(candidate.excerpt,baseline.excerpt);
  }
  assert.equal(row.snapshot.content_md,row.staging_snapshot.content_md);
  assert.notEqual(row.snapshot.content_md,row.before.content_md);
  assert.ok(row.snapshot.content_md.includes(`/${row.identity.locale==='en'?'en':'zh'}/career/tests`));
 }
});


test('reviewed MBTI comparison adds only the exact reciprocal hreflang pair and preserves feeds',()=>{
 const source=readCandidateRows(root,riasecDigest()).candidates.find(row=>row.page_id==='PAGE-67');
 const row={...source,snapshot:{title:'Comparison',content_md:'Interest and preference.'}};
 const cn='https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice';
 const en='https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice';
 const html=`<html><head><meta charset="utf-8"><link rel="canonical" href="${cn}"><meta name="robots" content="index, follow"><link rel="alternate" type="application/atom+xml" href="https://fermatmind.com/zh/articles/feed.xml"></head><body><main><h1>Comparison</h1><p>Interest and preference.</p></main></body></html>`;
 const before={dom:domOf(html)};
 const alternates=`<link rel="alternate" hreflang="en" href="${en}"><link rel="alternate" hreflang="zh-CN" href="${cn}"><link rel="alternate" hreflang="x-default" href="${en}">`;
 const complete=html.replace('</head>',alternates+'</head>');
 assert.doesNotThrow(()=>assertDom(row,complete,before,'production'));
 for(const altered of [html,complete.replace('hreflang="zh-CN"','hreflang="fr"'),complete.replace('/en/articles/mbti-vs-holland-code-career-choice','/en/articles/other'),complete.replace('application/atom+xml','text/html'),complete.replace('index, follow','noindex, follow')])
  assert.throws(()=>assertDom(row,altered,before,'production'),/DISCOVERABILITY/);
 const stagingHtml=html.replace('index, follow','noindex, follow, noarchive, nocache');
 const stagingBefore={dom:domOf(stagingHtml)};
 const stagingComplete=stagingHtml.replace('</head>',alternates+'</head>');
 assert.doesNotThrow(()=>assertDom(row,stagingComplete,stagingBefore,'staging'));
 for(const altered of [stagingHtml,stagingComplete.replace('hreflang="zh-CN"','hreflang="fr"'),stagingComplete.replace('/en/articles/mbti-vs-holland-code-career-choice','/en/articles/other'),stagingComplete.replace('application/atom+xml','text/html'),stagingComplete.replace('noindex, follow, noarchive, nocache','index, follow'),stagingComplete.replace(cn,cn+'?different=1')])
  assert.throws(()=>assertDom(row,altered,stagingBefore,'staging'),/DISCOVERABILITY/);
});

test('the selected sample package contains exactly three independent bilingual Article identities',()=>{
 const rows=readCandidateRows(root).candidates;
 assert.equal(rows.length,6);
 assert.equal(new Set(rows.map(row=>row.identity.slug)).size,3);
 for(const code of ['ric','iar','sae']) {
  const pair=rows.filter(row=>row.identity.slug===`${code}-holland-code-career-exploration`);
  assert.deepEqual(pair.map(row=>row.identity.locale).sort(),['en','zh-CN']);
  assert.equal(pair[0].source_identity.translation_group_id,pair[1].source_identity.translation_group_id);
  assert.ok(pair.every(row=>row.new_article===true && Object.keys(row.before).length===0 && Object.keys(row.staging_before).length===0));
 }
 const source=readFileSync(resolve(root,'scripts/deploy/run_public_content_53_publish.php'),'utf8');
 const serverPaths=[...source.matchAll(/        '(app\/[^']+|config\/content_promotion.php|scripts\/deploy\/run_public_content_53_publish.php)',/g)].map(m=>m[1]).sort();
 assert.deepEqual(executorPaths,serverPaths);
});

test('new sample SEO requires exact reciprocal routes, environment robots, schema and visible prose',()=>{
 const source=readCandidateRows(root).candidates[0];
 const row={...source,snapshot:{title:'Code exploration',excerpt:'A sample.',content_md:'## Try a task\n\nKeep the result.',seo_text:{seo_title:'Code exploration',seo_description:'Explore tasks.',og_title:'Code exploration',og_description:'Explore tasks.'}}};
 const slug=row.identity.slug;
 const cn=`https://fermatmind.com/zh/articles/${slug}`,en=`https://fermatmind.com/en/articles/${slug}`;
 const alt=`<link rel="alternate" hreflang="en" href="${en}"><link rel="alternate" hreflang="zh-CN" href="${cn}"><link rel="alternate" hreflang="x-default" href="${en}">`;
 const html=`<html><head><meta charset="utf-8"><title>Code exploration | FermatMind</title><meta name="description" content="Explore tasks."><meta property="og:title" content="Code exploration"><meta property="og:description" content="Explore tasks."><link rel="canonical" href="${cn}"><meta name="robots" content="index,follow">${alt}<script type="application/ld+json">{"@type":"Article","@id":"${cn}#article","url":"${cn}","mainEntityOfPage":"${cn}","headline":"Code exploration"}</script><script type="application/ld+json">{"@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","item":"${cn}"}]}</script></head><body><main><h1>Code exploration</h1><h2>Try a task</h2><p>Keep the result.</p></main></body></html>`;
 assert.doesNotThrow(()=>assertDom(row,html,{missing:true},'production'));
 assert.doesNotThrow(()=>assertDom(row,html.replace('index,follow','noindex,follow'),{missing:true},'staging'));
 for(const changed of [html.replace(alt,''),html.replace('hreflang="zh-CN"','hreflang="fr"'),html.replace('"@type":"Article"','"@type":"Thing","name":"Article BreadcrumbList"'),html.replace('index,follow','noindex,follow')])
  assert.throws(()=>assertDom(row,changed,{missing:true},'production'),/NEW_ARTICLE_SEO/);
 assert.throws(()=>assertDom(row,html.replace('<p>Keep the result.</p>','<script>Keep the result.</script>'),{missing:true},'production'),/VISIBLE_BODY/);
 const completed=html.replace('<h2>Try a task</h2>','<article data-testid="article-detail-content"><h2>Try a task</h2>').replace('</main>','</article></main>');
 const streamed=completed.replace('<body>','<body><main>Loading article</main><div hidden id="S:3">').replace('</body>','</div></body>');
 assert.doesNotThrow(()=>assertDom(row,streamed,{missing:true},'production',{articleSsr:true}));
 assert.throws(()=>assertDom(row,streamed,{missing:true},'production'),/DOM_STRUCTURE/);
 assert.doesNotThrow(()=>assertDom(row,completed,{missing:true},'production'));
 const unfinished=streamed.replace('<p>Keep the result.</p>','<template id="P:b"></template>').replace('</body>','<div hidden id="S:b"><p>Keep the result.</p></div></body>');
 assert.throws(()=>assertDom(row,unfinished,{missing:true},'production',{articleSsr:true}),/VISIBLE_BODY/);
 for(const absent of [completed.replace(/<main>[\s\S]*<\/main>/,'<main>Loading article</main>'),completed.replace(/<main>[\s\S]*<\/main>/,'<main>Loading article</main><script>Keep the result.</script>')])
  assert.throws(()=>assertDom(row,absent,{missing:true},'production',{articleSsr:true}),/DOM_STRUCTURE/);
 assert.throws(()=>assertDom(row,completed.replace('<article data-testid=', '<article data-iq-qa-hidden="true" data-testid='),{missing:true},'production'),/VISIBLE_BODY/);
});

test('six new Article acceptance keeps API, complete raw HTML and strict browser modes separate',async()=>{
 const rows=readCandidateRows(root).candidates;
 const escape=text=>text.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;');
 const pages=new Map(rows.map(row=>{
  const seo=row.snapshot.seo_text,url='https://fermatmind.com'+pathFor(row),slug=row.identity.slug;
  const links=[...row.snapshot.content_md.matchAll(/\[[^\]]+\]\((\/(?:en|zh)\/[^)]+)\)/g)].map(match=>`<a href="${escape(match[1])}">Destination</a>`).join('');
  const text=row.snapshot.content_md.replace(/\[([^\]]+)\]\([^)]+\)/g,'$1');
  const head=`<meta charset="utf-8"><title>${escape(seo.seo_title)} | FermatMind</title><meta name="description" content="${escape(seo.seo_description)}"><meta property="og:title" content="${escape(seo.og_title)}"><meta property="og:description" content="${escape(seo.og_description)}"><link rel="canonical" href="${url}"><meta name="robots" content="noindex,follow">`
   +[['en','en'],['zh-CN','zh'],['x-default','en']].map(([lang,segment])=>`<link rel="alternate" hreflang="${lang}" href="https://fermatmind.com/${segment}/articles/${slug}">`).join('')
   +`<script type="application/ld+json">${JSON.stringify({'@type':'Article','@id':url+'#article',url,mainEntityOfPage:url,headline:seo.seo_title})}</script><script type="application/ld+json">${JSON.stringify({'@type':'BreadcrumbList',itemListElement:[{item:url}]})}</script>`;
  const main=`<main><h1>${escape(row.snapshot.title)}</h1><article data-testid="article-detail-content"><pre>${escape(text)}</pre>${links}</article></main>`;
  return [pathFor(row),{row,html:`<html><head>${head}</head><body>${main}</body></html>`,stream:`<html><head>${head}</head><body><main>Loading</main><div hidden id="S:3">${main}</div></body></html>`}];
 }));
 let apiReads=0,rawReads=0,browserReads=0;
 const fetcher=async(url,options)=>{
  const parsed=new URL(url);
  if(parsed.hostname==='staging-api.fermatmind.com') {
   assert.equal(options.headers['User-Agent'],undefined);apiReads++;
   const row=rows.find(row=>url.includes('/'+row.identity.slug+'?') && parsed.searchParams.get('locale')===row.identity.locale);
   return {status:200,text:async()=>JSON.stringify({ok:true,article:{...row.identity,...row.snapshot,status:'published',is_public:true,seo_meta:row.snapshot.seo_text,is_indexable:true,sitemap_eligible:true,llms_eligible:true,translation_group_id:row.source_identity.translation_group_id,published_revision_id:1}})};
  }
  assert.equal(options.headers['User-Agent'],'facebookexternalhit/1.1');rawReads++;
  return {status:200,text:async()=>pages.get(parsed.pathname).stream};
 };
 const renderer=async(url,options)=>{
  assert.equal(options.surface,'public_page');assert.equal(options.headers,undefined);browserReads++;
  const page=pages.get(new URL(url).pathname);
  assert.throws(()=>options.verifyDOM(page.stream),/DOM_STRUCTURE/);
  options.verifyDOM(page.html);return page.html;
 };
 const result=await verifyOnlineCandidates('staging',root,fetcher,renderer,Object.fromEntries(rows.map(row=>[row.page_id,{missing:true}])),packageDigest(root));
 assert.deepEqual([apiReads,rawReads,browserReads],[6,6,6]);
 assert.deepEqual([result.api_readback_count,result.raw_html_readback_count,result.dom_readback_count],[6,6,6]);
});


test('career planning selects the three reviewed Chinese application guides and preserves their SEO copy',()=>{
 const digest=createHash('sha256').update('fermatmind.public_content_career_planning.v1\n'+['career-planning.json','career-planning-review.json'].map(name=>`${name}\n${createHash('sha256').update(readFileSync(resolve(root,'content_assets/public_content_53',name))).digest('hex')}\n`).join('')).digest('hex');
 const rows=readCandidateRows(root,digest).candidates;
 assert.deepEqual(rows.map(row=>[row.kind,row.identity.slug,row.identity.locale]),[
  ['guide','big5-for-career-decisions','zh-CN'],['guide','career-risk-management','zh-CN'],['guide','first-90-days-in-new-role','zh-CN'],
 ]);
 for(const row of rows) {
  assert.notEqual(row.snapshot.body_md,row.before.body_md);
  assert.deepEqual(row.snapshot.seo_text,row.before.seo_text);
  assert.equal(row.snapshot.title,row.before.title);
  const data={ok:true,guide:{...row.identity,...row.snapshot,guide_code:row.identity.slug,is_public:true},seo_meta:row.snapshot.seo_text};
  assert.deepEqual(projection(row,data),row.snapshot);
  assert.throws(()=>projection(row,{...data,guide:{...data.guide,guide_code:'another-guide'}}),/IDENTITY/);
 }
});

test('sample discovery requires real archive anchors and cannot pass from serialized script paths',()=>{
 const rows=readCandidateRows(root).candidates;
 const sitemap='<urlset>'+rows.map(row=>`<url><loc>https://fermatmind.com${pathFor(row)}</loc></url>`).join('')+'</urlset>';
 const llms=rows.map(row=>'https://fermatmind.com'+pathFor(row)).join('\n');
 const archive=locale=>'<html><body><main>'+rows.filter(row=>row.identity.locale===locale).map(row=>`<a href="${pathFor(row)}">${row.identity.slug}</a>`).join('')+'</main></body></html>';
 const documents={sitemap,llms,archiveEn:archive('en'),archiveZh:archive('zh-CN')};
 assert.doesNotThrow(()=>assertNewArticleDiscovery(rows,documents));
 for(const key of ['archiveEn','archiveZh']) {
  const bad='<html><body><main><script>'+rows.map(row=>JSON.stringify(pathFor(row))).join('')+'</script></main></body></html>';
  assert.throws(()=>assertNewArticleDiscovery(rows,{...documents,[key]:bad}),/NEW_ARTICLE_DISCOVERY/);
 }
 assert.throws(()=>assertNewArticleDiscovery(rows,{...documents,sitemap:sitemap.replace(pathFor(rows[0]),'/en/articles/unrelated')}),/NEW_ARTICLE_DISCOVERY/);
});


test('exact changed paths select a single package through transport, QA and workflow budgets',()=>{
 const env={PUBLIC_CONTENT_53_PUBLISH_ENVIRONMENT:'staging',DEPLOY_PATH:'/srv/app',DEPLOY_USER:'deploy',DEPLOY_HOST:'example.invalid',DEPLOY_PORT:'22',DEPLOY_SHA:'a'.repeat(40),GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'k'.repeat(40)};
 for(const [name,file,count] of [['riasec-code-samples','riasec-code-samples.json',6],['career-planning','career-planning.json',3],['guide-amendment','guide-amendment-review.json',1]]) {
  const selected=selectPublicContentPackage(['backend/content_assets/public_content_53/'+file]);
  assert.equal(selected,name);
  const execution=buildExecution({...env,PUBLIC_CONTENT_53_PACKAGE_NAME:selected},root);
  assert.equal(execution.binding.package_sha256,packageDigest(root,name));
  assert.equal(execution.request.package_sha256,execution.binding.package_sha256);
  assert.equal(execution.binding.expected_row_count,count);
  assert.equal(readCandidateRows(root,execution.request.package_sha256).candidates.length,count);
 }
 assert.equal(selectPublicContentPackage(['backend/app/Services/ContentPromotion/PublicContent53Package.php']),null);
 assert.throws(()=>selectPublicContentPackage(['backend/content_assets/public_content_53/riasec-code-samples.json','backend/content_assets/public_content_53/career-planning.json']),/MULTIPLE_PACKAGES/);
 assert.throws(()=>selectPublicContentPackage(['backend/content_assets/public_content_53/unknown.json']),/SELECTION_UNKNOWN/);
 assert.throws(()=>buildExecution(env,root),/SELECTION_REQUIRED/);
 const workflow=readFileSync(resolve(root,'../.github/workflows/deploy.yml'),'utf8');
 assert.equal(workflow.split('PUBLIC_CONTENT_53_PACKAGE_NAME: ${{ fromJSON(needs.policy.outputs.classification).operations.public_content_53_package }}').length-1,2);
 const publisher=readFileSync(resolve(root,'../.github/trunk/public-content-53-publish.mjs'),'utf8');
 assert.match(publisher,/readOnlinePrestate\([^;]+execution.binding.package_sha256\)/);
 assert.match(publisher,/verifyOnlineCandidates\([^;]+execution.binding.package_sha256\)/);
});

test('guide metadata accepts a streamed document title and still rejects missing, stale, duplicate or inert titles',()=>{
 const source=readCandidateRows(root,packageDigest(root,'career-planning')).candidates[0];
 const row={...source,snapshot:{...source.snapshot,body_md:'Required visible planning advice.',seo_text:{...source.snapshot.seo_text,seo_title:'Required planning guide'}}};
 const seo=row.snapshot.seo_text,title=`<title>${seo.seo_title} | FermatMind</title>`;
 const metadata=`<meta name="description" content="${seo.seo_description}"><meta property="og:title" content="${seo.og_title}"><meta property="og:description" content="${seo.og_description}"><link rel="canonical" href="https://fermatmind.com${pathFor(row)}"><meta name="robots" content="index, follow">`;
 const html=`<html><head><meta charset="utf-8">${metadata}</head><body>${title}<main><h1>${row.snapshot.title}</h1><p>Required visible planning advice.</p></main></body></html>`;
 const before={dom:domOf(html)};
 assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
 assert.doesNotThrow(()=>assertDom(row,html.replace('<title>','<title data-iq-qa-hidden="true">'),before,'production'));
 for(const altered of [html.replace(title,''),html.replace(title,'<title>Stale title</title>'),html.replace(title,title+title),html.replace('</head>',title+'</head>'),html.replace(title,`<template>${title}</template>`),html.replace(title,`<script type="application/json">${JSON.stringify(title)}</script>`)])
  assert.throws(()=>assertDom(row,altered,before,'production'),/METADATA/);
});


test('guide document titles follow the published CMS brand normalizer without accepting stale or duplicate titles',()=>{
 const source=readCandidateRows(root,packageDigest(root,'career-planning')).candidates[0];
 for(const [cmsTitle,documentTitle] of [
  ['Career planning','Career planning | FermatMind'],
  ['Career planning | FermatMind','Career planning | FermatMind'],
  ['职业转型行动手册｜FermatMind','职业转型行动手册 | FermatMind'],
  ['职业转型行动手册｜费马测试','职业转型行动手册 | FermatMind'],
  ['Career planning | FermatMind ｜ FermatMind','Career planning | FermatMind'],
 ]){
  const row={...source,snapshot:{...source.snapshot,body_md:'Required planning advice.',seo_text:{...source.snapshot.seo_text,seo_title:cmsTitle}}};
  const seo=row.snapshot.seo_text,title=`<title>${documentTitle}</title>`;
  const html=`<html><head><meta charset="utf-8">${title}<meta name="description" content="${seo.seo_description}"><meta property="og:title" content="${seo.og_title}"><meta property="og:description" content="${seo.og_description}"><link rel="canonical" href="https://fermatmind.com${pathFor(row)}"><meta name="robots" content="index, follow"></head><body><main><h1>${row.snapshot.title}</h1><p>Required planning advice.</p></main></body></html>`;
  const before={dom:domOf(html)};
  assert.doesNotThrow(()=>assertDom(row,html,before,'production'));
  for(const altered of [html.replace(title,''),html.replace(title,'<title>Stale title</title>'),html.replace(title,title+title),html.replace(title,`<title>${documentTitle} | FermatMind</title>`)])
   assert.throws(()=>assertDom(row,altered,before,'production'),/METADATA/);
 }
});


test('selected English guide batch selects only its reviewed identities and native guide projections',()=>{
 const source=JSON.parse(readFileSync(resolve(root,'content_assets/public_content_53/english-career-guides.json'),'utf8'));
 const digest=packageDigest(root,'english-career-guides');
 const rows=readCandidateRows(root,digest).candidates;
 const paths=['backend/content_assets/public_content_53/english-career-guides.json','backend/content_assets/public_content_53/english-career-guides-review.json'];
 const operations=classifyPaths(paths).operations;
 assert.equal(operations.public_content_53_publish,true);
 assert.equal(operations.public_content_53_package,'english-career-guides');
 assert.throws(()=>selectPublicContentPackage([...paths,'backend/content_assets/public_content_53/riasec-code-samples.json']),/MULTIPLE_PACKAGES/);
 assert.notEqual(digest,packageDigest(root,'riasec-code-samples'));
 assert.deepEqual(rows,source.candidates);
 assert.equal(inspectPackage(root,'english-career-guides').expected_row_count,source.candidates.length);
 assert.ok(rows.length>=2 && rows.length<=4);
 assert.equal(new Set(rows.map(row=>row.identity.slug)).size,rows.length);
 for(const row of rows){
  assert.equal(row.kind,'guide');assert.equal(row.identity.locale,'en');
  assert.ok(!['how-to-find-right-career-direction','career-transition-playbook','iq-eq-balance-at-work'].includes(row.identity.slug));
  assert.equal(row.snapshot.title,row.before.title);
  const data={ok:true,guide:{...row.identity,...row.snapshot,guide_code:row.identity.slug,is_public:true},seo_meta:row.snapshot.seo_text};
  assert.deepEqual(projection(row,data),row.snapshot);
  assert.throws(()=>projection(row,{...data,guide:{...data.guide,guide_code:'wrong-guide'}}),/IDENTITY/);
  assert.notDeepEqual(projection(row,{...data,seo_meta:{...data.seo_meta,seo_description:'Stale generic summary'}}),row.snapshot);
  for(const field of ['seo_title','og_title','twitter_title'])assert.equal(row.snapshot.seo_text[field],row.before.seo_text[field]);
  for(const field of ['seo_description','og_description','twitter_description'])assert.ok(row.snapshot.seo_text[field].length<=160);
  if(row.identity.slug==='from-mbti-to-job-fit')assert.equal(row.snapshot.body_md,row.before.body_md);
 }
});


test('English guide diagnostics cover exactly the 17 selected identities without exposing unsafe text',async()=>{
 const slugs=["annual-career-review-system","big5-for-career-decisions","build-five-year-career-roadmap","build-portfolio-for-career-switch","career-growth-with-manager","career-risk-management","cross-industry-move-strategy","first-90-days-in-new-role","from-mbti-to-job-fit","how-to-choose-college-major","improve-workplace-competitiveness","interview-strategy-by-role","leader-track-vs-expert-track","networking-that-actually-works","personal-brand-for-professionals","prevent-burnout-while-growing","salary-negotiation-framework"];
 const execution={request:{source_commit:'a'.repeat(40),workflow_run_id:'123'},binding:{package_sha256:'b'.repeat(64),executor_release_sha256:'c'.repeat(64)}};
 const failed=()=>({status:1,stdout:JSON.stringify({ok:false,error_code:'public_content_53_revalidation_failed'})});
 for(const slug of slugs){
  const code=`PUBLIC_CONTENT_53_VISIBLE_BODY_EN-GUIDE-${slug}_ITEM_14`;
  const error=await recoveryFailure(execution,failed,new Error(code));
  assert.equal(error.receipt.error_code,code);
 }
 for(const suffix of ['not-in-this-task','how-to-find-right-career-direction','career-transition-playbook','iq-eq-balance-at-work','annual-career-review-system/secret','annual-career-review-system?token=secret']){
  const error=await recoveryFailure(execution,failed,new Error(`PUBLIC_CONTENT_53_VISIBLE_BODY_EN-GUIDE-${suffix}_ITEM_14`));
  assert.equal(error.receipt.error_code,'PUBLIC_CONTENT_53_PUBLICATION_FAILED');
  assert.ok(!JSON.stringify(error.receipt).includes('secret'));
 }
});

test('page control recognition retains non-control comments and surrounding prose',()=>{
 const row={page_id:'PAGE-HELP-PAYMENT',kind:'page',identity:{locale:'en',slug:'help-payment-refund'},snapshot:{title:'Payment',content_md:''}};
 const before={dom:{canonical:['https://fermatmind.com/en/help/payment-refund'],robots:[],alternates:[]}};
 const html='<html><head><link rel="canonical" href="https://fermatmind.com/en/help/payment-refund"></head><body><main><h1>Payment</h1></main></body></html>';
 for(const copy of ['<!-- faq:start --> Required prose.', 'Required prose. <!-- faq:end -->','<!-- required\nprose -->','<!-- 中文正文 -->','<!-- unknown=marker -->','<!----> Required copy']) {
  assert.throws(()=>assertDom({...row,snapshot:{...row.snapshot,content_md:copy}},html,before,'production'),/VISIBLE_BODY/,copy);
 }
 assert.doesNotThrow(()=>assertDom({...row,snapshot:{...row.snapshot,content_md:' \t<!-- FAQ_control:v2 /start-1 --> \t'}},html,before,'production'));
});

test('IAR body-only amendment selects exactly the existing bilingual pair',()=>{
 const binding=inspectPackage(root,'iar-amendment');
 const rows=readCandidateRows(root,binding.package_sha256).candidates;
 assert.equal(binding.expected_row_count,2);
 assert.deepEqual(rows.map(row=>[row.page_id,row.identity.slug,row.identity.locale]),[
  ['IAR-ZH','iar-holland-code-career-exploration','zh-CN'],
  ['IAR-EN','iar-holland-code-career-exploration','en'],
 ]);
 for(const row of rows){
  assert.equal(row.enable_hreflang,false);
  assert.equal(row.snapshot.title,row.before.title);assert.equal(row.snapshot.excerpt,row.before.excerpt);
  assert.equal(row.staging_snapshot.title,row.staging_before.title);assert.equal(row.staging_snapshot.excerpt,row.staging_before.excerpt);
  assert.equal(row.snapshot.content_md,row.staging_snapshot.content_md);
  const locale=row.identity.locale==='en'?'en':'zh';
  for(const path of ['/career/compare','/holland-test/pdf-download','/career/jobs/industrial-designers','/career/jobs/architects-except-landscape-and-naval'])assert.ok(row.snapshot.content_md.includes('/'+locale+path));
  assert.ok(!row.before.content_md.includes('/'+locale+'/career/compare'));
 }
 assert.equal(selectPublicContentPackage(['backend/content_assets/public_content_53/iar-amendment.json']),'iar-amendment');
 assert.throws(()=>selectPublicContentPackage(['backend/content_assets/public_content_53/iar-amendment.json','backend/content_assets/public_content_53/copy-amendment.json']),/MULTIPLE_PACKAGES/);
});


test('AI method hreflang acceptance requires its exact reciprocal pair and keeps canonical, robots and Atom feeds',()=>{
 const slug='how-16-personality-types-talk-to-an-ai-coach';
 const en=`https://fermatmind.com/en/articles/${slug}`,cn=`https://fermatmind.com/zh/articles/${slug}`;
 for(const [id,locale,url] of [['PAGE-AI-ZH','zh-CN',cn],['PAGE-17','en',en]]) {
  const row={page_id:id,kind:'article',enable_hreflang:true,identity:{org_id:0,locale,slug},snapshot:{title:'Guide',content_md:'Keep current prose.'}};
  const html=`<html><head><link rel="canonical" href="${url}"><meta name="robots" content="index, follow"><link rel="alternate" type="application/atom+xml" href="https://fermatmind.com/${locale==='en'?'en':'zh'}/articles/feed.xml"></head><body><main><h1>Guide</h1><p>Keep current prose.</p></main></body></html>`;
  const before={dom:domOf(html)};
  const links=`<link REL="Alternate other" hrefLang="en" href="${en}"><link rel="alternate" hreflang="zh-CN" href="${cn}"><link rel="alternate" hreflang="x-default" href="${en}">`;
  const complete=html.replace('</head>',links+'</head>');
  assert.doesNotThrow(()=>assertDom(row,complete,before,'production'));
  for(const altered of [html,complete.replace('hreflang="zh-CN"','hreflang="fr"'),complete.replace('hreflang="x-default"','hreflang="zh"'),complete.replace('application/atom+xml','text/html'),complete.replace('index, follow','noindex, follow'),complete.replace('Keep current prose.','')])
   assert.throws(()=>assertDom(row,altered,before,'production'));
  const staging=complete.replace('index, follow','noindex, follow, noarchive, nocache');
  assert.doesNotThrow(()=>assertDom(row,staging,{dom:domOf(html.replace('index, follow','noindex, follow, noarchive, nocache'))},'staging'));
 }
});
