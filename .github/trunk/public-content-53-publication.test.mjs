import test from 'node:test';
import { classifyPaths } from './classify-paths.mjs';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { selectPublicContentPackage, packageDigest, executorPaths, inspectPackage, readCandidateRows, isPublic53OnlyPromotionRegistration, isPublic53OnlyContextFactoryChange } from './public-content-53-package.mjs';
import { buildExecution, publish, recoveryFailure, recoverPreviousStagingIndex, PUBLIC_CONTENT_53_TRANSPORT_TIMEOUT_MS, TRADITIONAL_INDEX_PUBLISH_TIMEOUT_MS } from './public-content-53-publish.mjs';
import { assertNewArticleDiscovery, assertDom, domOf, pathFor, read, projection, readOnlinePrestate, verifyOnlineCandidates } from './public-content-53-online-qa.mjs';
import { phpConsumers } from './impact-consumers.mjs';
test('support contact selects ten existing bilingual pages with only approved email changes',()=>{
 const paths=['backend/content_assets/public_content_53/support-contact.json','backend/content_assets/public_content_53/support-contact-review.json'];
 assert.equal(selectPublicContentPackage(paths),'support-contact');
 assert.equal(classifyPaths(paths).operations.public_content_53_package,'support-contact');
 assert.throws(()=>selectPublicContentPackage([...paths,'backend/content_assets/public_content_53/pricing-refund.json']),/MULTIPLE_PACKAGES/);
 const binding=inspectPackage(root,'support-contact');
 assert.equal(binding.expected_row_count,10);
 const rows=readCandidateRows(root,binding.package_sha256).candidates;
 assert.deepEqual(new Set(rows.map(pathFor)),new Set(['terms','help/payment-refund','help/contact','help/unlock-failure','help/result-recovery'].flatMap(path=>['zh','en'].map(locale=>`/${locale}/${path}`))));
 for(const row of rows) for(const prefix of ['', 'staging_']) {
  const before=row[prefix+'before'], after=row[prefix+'snapshot'];
  assert.deepEqual(Object.keys(after),['title','summary','content_md','faq_items','support_contact']);
  assert.equal(after.title,before.title);
  assert.equal(after.summary,before.summary);
  assert.equal(after.support_contact,'fuwei8868@outlook.com');
  if(!['help-unlock-failure','help-result-recovery'].includes(row.identity.slug)) assert.ok(after.content_md.includes('mailto:fuwei8868@outlook.com'));
  assert.ok(!after.content_md.includes('support@fermatmind.com'));
  if(row.identity.slug!=='help-contact') assert.equal(after.content_md,before.content_md.replaceAll('support@fermatmind.com',after.support_contact));
 }
});
test('pricing/refund publication selects only eight existing bilingual pages and keeps native publication controls',()=>{
 const paths=['backend/content_assets/public_content_53/pricing-refund.json','backend/content_assets/public_content_53/pricing-refund-review.json'];
 assert.equal(selectPublicContentPackage(paths),'pricing-refund');
 assert.equal(classifyPaths(paths).operations.public_content_53_package,'pricing-refund');
 assert.throws(()=>selectPublicContentPackage([...paths,'backend/content_assets/public_content_53/translation-batch.json']),/MULTIPLE_PACKAGES/);
 const binding=inspectPackage(root,'pricing-refund');
 assert.equal(binding.expected_row_count,8);
 const rows=readCandidateRows(root,binding.package_sha256).candidates;
 assert.deepEqual(new Set(rows.map(row=>pathFor(row))),new Set(['terms','policies','help/payment-refund','help/faq'].flatMap(path=>['zh','en'].map(locale=>`/${locale}/${path}`))));
 for(const row of rows) {
  assert.equal(row.kind,'page');
  assert.deepEqual(Object.keys(row.snapshot),['title','summary','content_md','faq_items']);
  assert.equal(row.snapshot.title,row.before.title);
  assert.equal(row.staging_snapshot.title,row.staging_before.title);
  const body=row.snapshot.content_md;
  assert.ok(body.includes('9.99')&&body.includes('1.99'));
  assert.ok(body.includes(row.identity.locale==='en'?'7 business days':'7 个工作日')||row.identity.slug==='help-faq'||row.identity.slug==='policies');
  if(row.identity.slug==='help-payment-refund') {
   assert.equal(row.snapshot.faq_items.length,4);
   for(const faq of row.snapshot.faq_items) assert.ok(body.includes(faq.question)&&body.includes(faq.answer));
  }
 }
});
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
test('Chinese reading links select exactly five reviewed native articles',()=>{
 const paths=['backend/content_assets/public_content_53/zh-reading-links.json','backend/content_assets/public_content_53/zh-reading-links-review.json'];
 assert.equal(selectPublicContentPackage(paths),'zh-reading-links');
 assert.throws(()=>selectPublicContentPackage([...paths,'backend/content_assets/public_content_53/directory-card-copy.json']),/MULTIPLE_PACKAGES/);
 const binding=inspectPackage(root,'zh-reading-links');
 assert.equal(binding.expected_row_count,5);
 const rows=readCandidateRows(root,binding.package_sha256).candidates;
 assert.equal(new Set(rows.map(row=>pathFor(row))).size,5);
 assert.ok(rows.every(row=>row.kind==='article' && row.identity.locale==='zh-CN' && row.identity.org_id===0));
});
test('Chinese reading link acceptance requires visible contextual anchors in both environments',()=>{
 const rows=readCandidateRows(root,packageDigest(root,'zh-reading-links')).candidates;
 const destinations=[['mbti-narrative-portrait'],['ric-holland-code-career-exploration','iar-holland-code-career-exploration','sae-holland-code-career-exploration'],['enneagram-workplace-friction-core-motivations'],['ocean-traits-what-the-five-letters-mean','big-five-score-ranges-what-high-middle-low-can-mean'],['eq60-score-and-results-guide','emotional-awareness-regulation-and-relationship-practice']];
 const escape=x=>x.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;');
 for(const environment of ['production','staging']) for(const [i,source] of rows.entries()) for(const slug of destinations[i]) {
  const candidate=environment==='staging'?source.staging_snapshot:source.snapshot;
  const target='/zh/articles/'+slug;
  const line=candidate.content_md.split('\n').find(line=>line.includes(']('+target+')'));
  assert.ok(line,`${source.page_id} must include the selected contextual link`);
  const row={...source,snapshot:{title:candidate.title,content_md:line}};
  const paragraph=escape(line).replace(/^[-*] /,'').replace(/\[([^\]]+)\]\(([^)]+)\)/g,'<a href="$2">$1</a>');
  const url='https://fermatmind.com'+pathFor(row);
  const html=`<html><head><meta charset="utf-8"><link rel="canonical" href="${url}"></head><body><main><h1>${escape(candidate.title)}</h1><p>${paragraph}</p></main></body></html>`;
  const before={dom:domOf(html)};
  assert.doesNotThrow(()=>assertDom(row,html,before,environment));
  assert.throws(()=>assertDom(row,html.replace(`href="${target}"`,'href="/zh/articles/unselected"'),before,environment),/LINK|VISIBLE_BODY/);
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
test('reader link labels exclude hidden ancestors and unrelated loading mains',()=>{
 for(const attribute of ['hidden','aria-hidden="true"','data-iq-qa-hidden="true"']) {
  const dom=domOf(`<html><body><main><span ${attribute}><a href="/hidden">Support</a></span><p>Support</p><a href="/visible">Visible</a></main></body></html>`);
  assert.equal(dom.link_labels['/hidden'],undefined);
  assert.deepEqual(dom.link_labels['/visible'].map(value=>value.trim()),['Visible']);
 }
 const article=domOf('<html><body><main><a href="/loading">Loading</a></main><div hidden><main><article data-testid="article-detail-content"><a href="/visible">Visible</a><span hidden><a href="/hidden">Hidden</a></span></article></main></div></body></html>',{articleSsr:true});
 assert.equal(article.body.length,1);
 assert.equal(article.link_labels['/loading'],undefined);
 assert.equal(article.link_labels['/hidden'],undefined);
 assert.deepEqual(article.link_labels['/visible'].map(value=>value.trim()),['Visible']);
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
 for(const id of ['TRADITIONAL-about','TRADITIONAL-brand','TRADITIONAL-charter','TRADITIONAL-careers','TRADITIONAL-privacy','TRADITIONAL-terms','TRADITIONAL-method-boundaries','TRADITIONAL-science','TRADITIONAL-help-contact','RIASEC-RIC-zh-CN','RIASEC-IAR-en','RIASEC-SAE-en','EN-GUIDE-annual-career-review-system','EN-GUIDE-build-five-year-career-roadmap','EN-GUIDE-build-portfolio-for-career-switch','EN-GUIDE-cross-industry-move-strategy']) {
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
 for (const [timedOut, code] of [[false,'PUBLIC_CONTENT_53_SSH_EXIT_255'],[true,'PUBLIC_CONTENT_53_SSH_TIMEOUT']]) {
  const calls=[];
  await assert.rejects(()=>publish(execution,async request=>{
   calls.push(request.mode);
   if(request.mode==='publish')return {status:255,timedOut,stdout:'private transport bytes'};
   return {status:0,stdout:JSON.stringify({ok:true,mode:'recover',source_commit:request.source_commit,workflow_run_id:request.workflow_run_id,workflow_run_attempt:1,package_sha256:execution.binding.package_sha256,executor_release_sha256:execution.binding.executor_release_sha256,restored:true,recovery_status:'restored',sanitized:true})};
  }),error=>error.receipt.error_code===code&&error.receipt.recovery_completed===true&&!JSON.stringify(error.receipt).includes('private'));
  assert.deepEqual(calls,['publish','recover']);
 }
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


test('deferred Traditional index manifest stays out of automatic publication selection',()=>{
 const index='backend/content_assets/public_content_53/traditional-article-index.json';
 const independent='backend/content_assets/public_content_53/support-contact.json';
 assert.equal(selectPublicContentPackage([index]),null);
 assert.equal(selectPublicContentPackage([index,independent]),'support-contact');
 const classification=classifyPaths([index,'backend/app/Services/Career/CareerFilePageReader.php']);
 assert.equal(classification.operations.public_content_53_publish,false);
 assert.equal(classification.operations.public_content_53_package,null);
 assert.equal(classification.operations.public_content_53_checks,true);
});

test('interrupted Traditional index staging restores only the exact prior transaction', async () => {
 const previous='a'.repeat(40), candidate='b'.repeat(40), run='123';
 const selection='traditional-article-index';
 const digest=packageDigest(root,selection);
 const cohort=readCandidateRows(root,digest,'staging').candidates.map(row=>({loc:row.traditional_article_index.target.canonical}));
 const temp=mkdtempSync(resolve(tmpdir(),'fm-index-recovery-'));
 const env={RUNNER_TEMP:temp,PUBLIC_CONTENT_53_PACKAGE_NAME:selection,PUBLIC_CONTENT_53_PUBLISH_ENVIRONMENT:'staging',
  DEPLOY_PATH:'/srv/app',DEPLOY_USER:'deploy',DEPLOY_HOST:'example.invalid',DEPLOY_PORT:'22',
  DEPLOY_SHA:candidate,GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'k'.repeat(40)};
 const receipt={schema:'public.content53.publish.v1',ok:false,sanitized:true,source_commit:previous,
  workflow_run_id:run,workflow_run_attempt:1,package_sha256:digest,transport_started:true,recovery_completed:false,
  recovery_error_code:'public_content_53_execution_lock_failed',error_code:'PUBLIC_CONTENT_53_SSH_EXIT_255'};
 let active=cohort;
 let receiptBody=receipt;
 const calls=[];
 const deps={
  command(program,args) {
   calls.push([program,...args.slice(0,2)]);
   if (program==='git') return args[0]==='rev-parse'?candidate:'';
   if (args[1]==='list') return JSON.stringify([{headSha:previous,attempt:1,status:'completed',conclusion:'failure',databaseId:Number(run)}]);
   assert.equal(args[4],`public-content-53-staging-${previous}-${run}-1`);
   writeFileSync(resolve(args.at(-1),'staging.json'),JSON.stringify(receiptBody));
   return '';
  },
  fetcher: async url => new Response(JSON.stringify(url.endsWith('/revision')?{revision:previous}:
   {ok:true,source:'backend_sitemap_generator',items:active})),
  async execute(execution,request) {
   calls.push(['recover',request.source_commit,request.workflow_run_id]);
   assert.equal(request.mode,'recover');
   assert.equal(request.package_sha256,digest);
   active=[];
   return {status:0,stdout:JSON.stringify({...request,ok:true,restored:true,recovery_status:'restored',sanitized:true})};
  },
 };
 try {
  await recoverPreviousStagingIndex(env,deps);
  assert.deepEqual(calls.at(-1),['recover',previous,run]);
  const recoveryCalls=()=>calls.filter(call=>call[0]==='recover').length;
  await recoverPreviousStagingIndex(env,deps);
  assert.equal(recoveryCalls(),1);
  active=cohort.slice(1);
  await assert.rejects(recoverPreviousStagingIndex(env,deps),/PREVIOUS_STAGING_STATE_INVALID/);
  active=cohort;
  receiptBody={...receipt,recovery_completed:true};
  await assert.rejects(recoverPreviousStagingIndex(env,deps),/PREVIOUS_STAGING_RECEIPT_INVALID/);
  await assert.rejects(recoverPreviousStagingIndex({...env,PUBLIC_CONTENT_53_PUBLISH_ENVIRONMENT:'production'},deps),/PREVIOUS_STAGING_SCOPE_INVALID/);
  assert.equal(recoveryCalls(),1);
 } finally {
  rmSync(temp,{recursive:true,force:true});
 }
});

test('exact changed paths select a single package through transport, QA and workflow budgets',()=>{
 const env={PUBLIC_CONTENT_53_PUBLISH_ENVIRONMENT:'staging',DEPLOY_PATH:'/srv/app',DEPLOY_USER:'deploy',DEPLOY_HOST:'example.invalid',DEPLOY_PORT:'22',DEPLOY_SHA:'a'.repeat(40),GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'k'.repeat(40)};
 for(const [name,file,count] of [['riasec-code-samples','riasec-code-samples.json',6],['career-planning','career-planning.json',3],['guide-amendment','guide-amendment-review.json',1]]) {
  const selected=selectPublicContentPackage(['backend/content_assets/public_content_53/'+file]);
  assert.equal(selected,name);
  const execution=buildExecution({...env,PUBLIC_CONTENT_53_PACKAGE_NAME:selected},root);
  assert.equal(execution.publishTimeoutMs,PUBLIC_CONTENT_53_TRANSPORT_TIMEOUT_MS);
  assert.equal(execution.binding.package_sha256,packageDigest(root,name));
  assert.equal(execution.request.package_sha256,execution.binding.package_sha256);
  assert.equal(execution.binding.expected_row_count,count);
  assert.equal(readCandidateRows(root,execution.request.package_sha256).candidates.length,count);
 }
 const indexExecution=buildExecution({...env,PUBLIC_CONTENT_53_PACKAGE_NAME:'traditional-article-index'},root);
 assert.equal(indexExecution.publishTimeoutMs,TRADITIONAL_INDEX_PUBLISH_TIMEOUT_MS);
 assert.equal(indexExecution.binding.expected_row_count,115);
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


test('Traditional career guides bind the same twenty candidates to the two original-source states',()=>{
 const name='traditional-career-guides';
 const files=['traditional-career-guides.json','traditional-career-guides-review.json']
   .map(file=>'backend/content_assets/public_content_53/'+file);
 const binding=inspectPackage(root,name);
 const production=readCandidateRows(root,binding.package_sha256,'production').candidates;
 const staging=readCandidateRows(root,binding.package_sha256,'staging').candidates;
 assert.equal(classifyPaths(files).operations.public_content_53_package,name);
 assert.equal(binding.expected_row_count,20);
 assert.deepEqual(production.map(row=>row.snapshot),staging.map(row=>row.snapshot));
 assert.equal(production.filter((row,index)=>row.source_expectation.guide.authoredHashes.body_md
   !==staging[index].source_expectation.guide.authoredHashes.body_md).length,6);
 for(const row of production){
  assert.equal(row.identity.locale,'zh-TW');
  assert.equal(row.native_draft.is_indexable,false);
  const guide={...row.identity,...row.snapshot,guide_code:row.source_identity.guide_code,is_public:true};
  const data={ok:true,guide,seo_meta:row.snapshot.seo_text};
  assert.deepEqual(projection(row,data),row.snapshot);
  assert.throws(()=>projection(row,{...data,guide:{...guide,locale:'zh-CN'}}),/IDENTITY_DRIFT/);
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

test('Traditional CMS package selects sixteen reviewed variants with exact environment sources and verifies complete noindex DOM',()=>{
 const paths=['backend/content_assets/public_content_53/traditional-pages.json','backend/content_assets/public_content_53/traditional-pages-review.json'];
 assert.equal(selectPublicContentPackage(paths),'traditional-pages');
 assert.throws(()=>selectPublicContentPackage([...paths,'backend/content_assets/public_content_53/translation-batch.json']),/MULTIPLE_PACKAGES/);
 const binding=inspectPackage(root,'traditional-pages');
 assert.equal(binding.expected_row_count,16);
 const rows=readCandidateRows(root,binding.package_sha256).candidates;
 assert.deepEqual(rows.map(row=>row.identity.slug),['about','brand','charter','careers','privacy','terms','method-boundaries','science','help-contact','foundation','policies','help-faq','help-about','help-team','help-used-and-mentioned','help-for-business-and-research']);
 const escape=value=>String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;');
 for(const original of rows) {
  for(const environment of ['staging','production']) {
  const row=environment==='staging'&&original.staging_snapshot?{...original,snapshot:original.staging_snapshot}:original;
  assert.equal(row.identity.locale,'zh-TW');
  assert.equal(pathFor(row),`/zh-TW/${row.identity.slug.startsWith('help-')?'help/'+row.identity.slug.slice(5):row.identity.slug}`);
  assert.equal(row.snapshot.is_indexable,false);
  const links=[['/method-boundaries','/zh/method-boundaries','方法邊界'],['/item-design-notes','/zh/item-design-notes','題目設計說明'],['/reliability-validity','/zh/reliability-validity','信度與效度'],['/tests/mbti-personality-test-16-personality-types','/zh/tests/mbti-personality-test-16-personality-types','MBTI免費測試'],['/tests/big-five-personality-test','/zh/tests/big-five-personality-test-ocean-model','大五人格免費測試'],['/tests/holland-career-interest-test-riasec','/zh/tests/holland-career-interest-test-riasec','霍蘭德職業興趣免費測試']];
  const body=row.snapshot.content_md.split('\n').filter(line=>line && !/^# content_md$|^visible_faq_items:$/.test(line)).map(line=>{
   let copy=escape(line.replace(/^#{1,6}\s+/,'').replaceAll('*',''));
   copy=copy.replace(/\[([^\[\]\n]+)\]\(([^()\s]+)\)/g,(_,label,href)=>`<a href="${href}">${label}</a>`);
   copy=copy.split(/(<a\b[^>]*>[\s\S]*?<\/a>)/g).map((chunk,index)=>{
    if(index%2)return chunk;
    for(const [path,href,label] of links)chunk=chunk.replaceAll(path,`<a href="${href}">${label}</a>`);
    return chunk;
   }).join('');
   return `<p>${copy}</p>`;
  }).join('');
  const url='https://fermatmind.com'+pathFor(row);
  const schema=[{'@type':'WebPage',url,inLanguage:'zh-TW',name:row.snapshot.title,description:row.snapshot.summary ?? ''},{'@type':'BreadcrumbList',itemListElement:[{item:url}]}].map(node=>`<script type="application/ld+json">${JSON.stringify(node)}</script>`).join('');
  const team=row.identity.slug==='about'?'<section>創始人與博士顧問團隊 劉福威 賈睿璇 李琛</section>':'';
  const html=`<html lang="zh-TW"><head><meta charset="utf-8"><title>${escape(row.snapshot.seo_title)} | 費馬測試</title><meta name="description" content="${escape(row.snapshot.meta_description)}"><meta name="robots" content="noindex, follow"><link rel="canonical" href="${url}"></head><body>${schema}<main><h1>${escape(row.snapshot.title)}</h1>${body}${team}</main></body></html>`;
  assert.doesNotThrow(()=>assertDom(row,html,{missing:true},environment));
  for(const altered of [html.replace('lang="zh-TW"','lang="zh-CN"'),html.replace('noindex, follow','index, follow'),html.replace(pathFor(row),pathFor(row).replace('/zh-TW/','/zh/')),html.replace(escape(row.snapshot.title),'Incomplete title')])
   assert.throws(()=>assertDom(row,altered,{missing:true},'production'));
  if(team) assert.throws(()=>assertDom(row,html.replace('劉福威',''),{missing:true},'production'));
  if(row.identity.slug==='terms') {
   const source='[價格與報告內容](/zh/pricing)、[支援中心](/zh/support)、[郵件](mailto:support@fermatmind.com)、[說明](/关于?地区=中国#说明)';
   const linkedRow={...row,snapshot:{...row.snapshot,content_md:source}};
   const anchors='<p><a href="/zh/pricing">價格與報告內容</a>、<a href="/zh/support">支援中心</a>、<a href="mailto:support@fermatmind.com">郵件</a>、<a href="/关于?地区=中国#说明">說明</a></p>';
   const linkedHtml=html.replace(/<main>[\s\S]*?<\/main>/,`<main><h1>${escape(row.snapshot.title)}</h1>${anchors}</main>`);
   assert.doesNotThrow(()=>assertDom(linkedRow,linkedHtml,{missing:true},'production'));
   for(const altered of [
    linkedHtml.replace('<a href="/zh/pricing">價格與報告內容</a>','價格與報告內容'),
    linkedHtml.replace('href="/zh/pricing"','href="/en/pricing"'),
    linkedHtml.replace('mailto:support@fermatmind.com','mailto:wrong@fermatmind.com'),
    linkedHtml.replace('地区=中国#说明','地区=中國#说明'),
    linkedHtml.replace('href="/zh/support"','href="/zh/support?unexpected=1"'),
    linkedHtml.replace('<a href="/zh/pricing">價格與報告內容</a>','<span hidden><a href="/zh/pricing">價格與報告內容</a></span>價格與報告內容'),
    linkedHtml.replace('<a href="/zh/pricing">價格與報告內容</a>','<span aria-hidden="true"><a href="/zh/pricing">價格與報告內容</a></span>價格與報告內容'),
   ]) assert.throws(()=>assertDom(linkedRow,altered,{missing:true},'production'),/LINK/);
  }
  if(row.identity.slug==='science') {
   assert.equal(row.snapshot.summary,null);
   assert.throws(()=>assertDom(row,html.replace('\"description\":\"\"','\"description\":null'),{missing:true},'production'),/SEO_OR_TEAM/);
   const withSummary={...row,snapshot:{...row.snapshot,summary:'Nonempty reviewed summary'}};
   assert.throws(()=>assertDom(withSummary,html,{missing:true},'production'),/SEO_OR_TEAM/);
   assert.doesNotThrow(()=>assertDom(row,html.replace('href="/zh/method-boundaries"','href="/zh-TW/method-boundaries"'),{missing:true},'production'));
   for(const altered of [html.replace('<a href="/zh/method-boundaries">方法邊界</a>','方法邊界'),html.replace('href="/zh/method-boundaries"','href="/zh/account"'),html.replace('href="/zh/tests/big-five-personality-test-ocean-model"','href="/tests/big-five-personality-test"'),html.replace('href="/zh/tests/big-five-personality-test-ocean-model"','href="/tests/big-five-personality-test-ocean-model"'),html.replace('href="/zh/method-boundaries"','href="/zh/method-boundaries?unexpected=1"')])
    assert.throws(()=>assertDom(row,altered,{missing:true},'production'),/LINK/);
   let raw=html;
   for(const [path,href,label] of links) raw=raw.replace(`<a href="${href}">${label}</a>`,path);
   assert.throws(()=>assertDom(row,raw,{missing:true},'production'),/LINK/);
  }
  if(row.identity.slug==='help-contact') {
   const opposite={...original,snapshot:environment==='staging'?original.snapshot:original.staging_snapshot};
   assert.throws(()=>assertDom(opposite,html,{missing:true},environment),/METADATA|VISIBLE_BODY|SEO/);
   assert.throws(()=>assertDom(row,html.replace('mailto:fuwei8868@outlook.com','mailto:wrong@example.invalid'),{missing:true},environment),/LINK/);
  }
  }
 }
});

test('contact native diagnostics retain only fixed source conditions and declared slugs',async()=>{
 const execution={request:{source_commit:'a'.repeat(40),workflow_run_id:'123',workflow_run_attempt:1,mode:'publish'},binding:{package_sha256:'b'.repeat(64),executor_release_sha256:'c'.repeat(64)}};
 for(const status of [0,1])for(const [code,known] of [
  ['public_content_53_traditional_page_source_path_help-contact',true],
  ['public_content_53_traditional_page_source_support_contact_help-contact',true],
  ['public_content_53_traditional_page_source_copy_summary_method-boundaries',true],
  ['public_content_53_traditional_page_source_password_help-contact',false],
  ['public_content_53_traditional_page_source_identity_private-token',false],
 ]){
  const execute=request=>request.mode==='recover'
   ? {status:0,stdout:JSON.stringify({ok:true,mode:'recover',source_commit:request.source_commit,workflow_run_id:request.workflow_run_id,workflow_run_attempt:1,package_sha256:execution.binding.package_sha256,executor_release_sha256:execution.binding.executor_release_sha256,restored:false,recovery_status:'not_required',sanitized:true})}
   : {status,stdout:JSON.stringify({ok:false,error_code:code,sanitized:true})};
  await assert.rejects(()=>publish(execution,execute),error=>{
   assert.equal(error.receipt.error_code,known?code:status===1?'PUBLIC_CONTENT_53_SSH_EXIT_1':'PUBLIC_CONTENT_53_PUBLICATION_RESPONSE_INVALID');
   assert.equal(error.receipt.recovery_completed,true);return true;
  });
 }
});


test('Taiwan entrance preserves the indexed pilot and keeps unpublished categories out of discovery',()=>{
 const packageFiles=['backend/content_assets/public_content_53/traditional-entrance.json','backend/content_assets/public_content_53/traditional-entrance-review.json'];
 assert.equal(selectPublicContentPackage(packageFiles),'traditional-entrance');
 assert.equal(classifyPaths(packageFiles).operations.public_content_53_package,'traditional-entrance');
 const row={page_id:'TRADITIONAL-LANDING-home',kind:'surface',identity:{slug:'home',locale:'zh-TW'},new_traditional_landing:true,traditional_landing_indexable:true,before:{},
   snapshot:{payload_json:{seo:{title:'費馬測試',description:'認識自己'}}}};
 const html='<html lang="zh-TW"><head><meta charset="utf-8"><title>費馬測試</title><meta name="description" content="認識自己"><link rel="canonical" href="https://fermatmind.com/zh-TW"><meta name="robots" content="noindex,follow"></head><body><main><h1 aria-label="看清自己，走好每一步"><span aria-hidden="true">看清自己，走好每一步</span></h1><p>費馬測試把自我認知</p><p>免費測驗</p><p>120 萬，5000 萬（真實伺服器資料統計）</p><a href="/">简体中文</a><a href="/en">English</a><a href="/zh-TW/foundation">公益計畫</a><a href="/zh-TW/method-boundaries">方法界線</a><a href="/zh-TW/about">關於費馬測試</a></main></body></html>';
 const production=html.replace('<meta name="robots" content="noindex,follow">','');
 assert.doesNotThrow(()=>assertDom(row,production,{missing:true},'production'));
 assert.doesNotThrow(()=>assertDom(row,html,{missing:true},'staging'));
 for(const changed of [html,production.replace('lang="zh-TW"','lang="zh-CN"'),production.replace('免費測驗','免费测试'),production.replace('/zh-TW/about','/zh/about')])
  assert.throws(()=>assertDom(row,changed,{missing:true},'production'),/TRADITIONAL_LANDING_DOM/);
});

test('Traditional Article selects the existing publisher and binds the OCEAN source revisions',()=>{
 const files=['backend/content_assets/public_content_53/traditional-article.json','backend/content_assets/public_content_53/traditional-article-review.json'];
 assert.equal(selectPublicContentPackage(files),'traditional-article');
 assert.equal(classifyPaths(files).operations.public_content_53_package,'traditional-article');
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 const binding=inspectPackage(root,'traditional-article');
 assert.equal(binding.expected_row_count,1);
 const [row]=readCandidateRows(root,binding.package_sha256).candidates;
 assert.deepEqual(row.identity,{org_id:0,slug:'ocean-traits-what-the-five-letters-mean',locale:'zh-TW'});
 assert.equal(row.source_expectations.source.published_revision_id,900);
 assert.equal(row.source_expectations.existing_english.published_revision_id,903);
 assert.equal(row.new_traditional_article,true);
});

test('Traditional Article batch selects three independently verified native source pairs per environment',()=>{
 const files=['backend/content_assets/public_content_53/traditional-article-batch.json','backend/content_assets/public_content_53/traditional-article-batch-review.json'];
 assert.equal(classifyPaths(files).operations.public_content_53_package,'traditional-article-batch');
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 const binding=inspectPackage(root,'traditional-article-batch');
 assert.equal(binding.expected_row_count,4);
 const productionRows=readCandidateRows(root,binding.package_sha256).candidates;
 const stagingRows=readCandidateRows(root,binding.package_sha256,'staging').candidates;
 const production=productionRows.filter(row=>row.kind==='article');
 const staging=stagingRows.filter(row=>row.kind==='article');
 const directory=productionRows.find(row=>row.new_traditional_blog);
 assert.equal(pathFor(directory),'/zh-TW/articles');
 assert.deepEqual(directory.snapshot.payload_json.blog_v1.featured_article_ids,[]);
 assert.equal(directory.source_snapshot.payload_json.blog_v1.featured_article_ids.length,4);
 assert.equal(stagingRows.find(row=>row.new_traditional_blog).source_snapshot,null);
 assert.deepEqual(production.map(row=>row.source_identity.source_article_id),[275,276,277]);
 assert.deepEqual(staging.map(row=>row.source_identity.source_article_id),[234,235,236]);
 assert.deepEqual(staging.map(row=>row.source_expectations.source.published_revision_id),[777,785,779]);
 assert.deepEqual(staging.map(row=>row.snapshot),production.map(row=>row.snapshot));
 assert.ok(staging.every(row=>row.staging_source_taxonomy.category_id===null && row.taxonomy_display.category.id===11));
});

test('Traditional Article featured package preserves reviewed editorial output in each environment',()=>{
 const files=['backend/content_assets/public_content_53/traditional-article-featured.json','backend/content_assets/public_content_53/traditional-article-featured-review.json'];
 assert.equal(classifyPaths(files).operations.public_content_53_package,'traditional-article-featured');
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 const binding=inspectPackage(root,'traditional-article-featured');
 assert.equal(binding.expected_row_count,4);
 for (const environment of ['production','staging']) {
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates;
  assert.deepEqual(rows.map(row=>row.identity.slug),[
   'riasec-holland-career-interest-test-explained','holland-career-interest-test-can-and-cannot-tell-you',
   'mbti-narrative-portrait','big-five-tool-guide',
  ]);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection.faq.length),[6,4,6,5]);
  assert.ok(rows.every(row=>row.snapshot.editorial_projection.ctas.length===1));
  assert.equal(rows[2].snapshot.author_name,null);
  for (const row of rows) {
   const expected=row.snapshot.editorial_projection;
   const actual=projection(row,{
    ok:true,article:{...row.identity,status:'published',is_public:true},
    answer_surface_v1:{faq_blocks:expected.faq},landing_surface_v1:{cta_bundle:expected.ctas},
   });
   assert.deepEqual(actual.editorial_projection,expected);
   const {href,label}=expected.ctas[0];
   const faq=expected.faq.map(({question})=>'<summary>'+question+'</summary>').join('');
   const html='<html lang="zh-TW"><head><meta charset="utf-8"><meta name="robots" content="noindex,follow"><link rel="canonical" href="https://fermatmind.com'+pathFor(row)+'"></head><body><main><h1>繁體文章</h1>'+faq+'<a href="'+href+'">'+label+'</a></main></body></html>';
   const editorialRow={...row,snapshot:{content_md:'繁體文章',editorial_projection:expected}};
   assert.doesNotThrow(()=>assertDom(editorialRow,html,{missing:true},environment));
   assert.throws(()=>assertDom(editorialRow,html.replace(href,'/zh/tests'),{missing:true},environment),/TRADITIONAL_ARTICLE_EDITORIAL_DOM/);
   assert.throws(()=>assertDom(editorialRow,html.replace(faq,''),{missing:true},environment),/TRADITIONAL_ARTICLE_EDITORIAL_DOM/);
  }
 }
});

test('Traditional Blog featured order binds published targets and checks the visible grid order',async()=>{
 const selection='traditional-blog-featured-order';
 const filename=selection+'.json';
 const assets='content_assets/public_content_53';
 assert.equal(classifyPaths(['backend/'+assets+'/'+filename]).operations.public_content_53_package,selection);
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 const fixture=mkdtempSync(resolve(tmpdir(),'traditional-blog-featured-order-'));
 try {
  symlinkSync(resolve(root,'app'),resolve(fixture,'app'),'dir');
  mkdirSync(resolve(fixture,assets),{recursive:true});
  for (const packageName of ['traditional-article-batch','traditional-article-featured']) {
   for (const name of [packageName+'.json',packageName+'-review.json'])
    copyFileSync(resolve(root,assets,name),resolve(fixture,assets,name));
  }
  const digest=packageDigest(root,'traditional-article-featured');
  const source=readCandidateRows(root,digest).candidates;
  const articles=source.map((row,i)=>({id:500+i,slug:row.identity.slug,published_revision_id:900+i}));
  const packageRow={schema:'fermatmind.traditional_blog_featured_order.v1',environments:{
   staging:articles.map(a=>({...a,id:a.id+100})),production:articles,
  }};
  const save=()=>writeFileSync(resolve(fixture,assets,filename),JSON.stringify(packageRow));
  save();
  const sha=packageDigest(fixture,selection);
  const [row]=readCandidateRows(fixture,sha).candidates;
  const [stage]=readCandidateRows(fixture,sha,'staging').candidates;
  assert.deepEqual(row.snapshot.payload_json.blog_v1.featured_article_ids,[500,501,502,503]);
  assert.deepEqual(stage.snapshot.payload_json.blog_v1.featured_article_ids,[600,601,602,603]);
  assert.deepEqual(row.featured_targets.map(item=>item.source_article_id),[40,31,10,3]);
  assert.deepEqual({...row.snapshot,payload_json:{...row.snapshot.payload_json,blog_v1:{...row.snapshot.payload_json.blog_v1,featured_article_ids:[]}}},row.before);
  const path=pathFor(row);
  const anchor=slug=>'<a href="'+path+'/'+slug+'">文章</a>';
  const cards=targets=>'<div data-testid="articles-featured-grid">'+targets.map(a=>'<article data-article-layout="featured">'+anchor(a.slug)+'</article>').join('')+'</div>';
  const categories=row.snapshot.payload_json.blog_v1.categories;
  const html=targets=>'<html lang="zh-TW"><head><meta charset="utf-8"><title>'+row.snapshot.title+' | 費馬測試</title><meta name="description" content="'+row.snapshot.description+'"><meta name="robots" content="noindex"><link rel="canonical" href="https://fermatmind.com'+path+'"></head><body><main><h1>'+row.snapshot.title+'</h1><p>'+row.snapshot.description+'</p>'+categories.map(category=>'<a href="'+path+'/category/'+category.slug+'">'+category.name+'</a>').join('')+row.blog_article_slugs.map(anchor).join('')+cards(targets)+'</main></body></html>';
  assert.doesNotThrow(()=>assertDom(row,html(articles),{},'production'));
  assert.throws(()=>assertDom(row,html(articles.toReversed()),{},'production'),/TRADITIONAL_BLOG_DOM/);
  assert.throws(()=>assertDom(row,html([]),{},'production'),/TRADITIONAL_BLOG_DOM/);
  const fetcher=(items,candidate=row)=>async url=>{
   const path=new URL(url).pathname;
   const body=path.endsWith('/articles_index')
    ? {ok:true,surface:{surface_key:'articles_index',locale:'zh-TW',status:'published',is_public:true,is_indexable:false,...candidate.snapshot}}
    : {ok:true,blog_v1:{configuration_state:'published',is_indexable:false,featured_items:items}};
   return Response.json(body);
  };
  const prestate={[row.page_id]:{flags:{is_indexable:false}}};
  const renderer=async()=>html(articles);
  const accepted=await verifyOnlineCandidates('production',fixture,fetcher(articles),renderer,prestate,sha);
  assert.equal(accepted.api_readback_count,1);
  assert.equal(accepted.dom_readback_count,1);
  await assert.rejects(verifyOnlineCandidates('production',fixture,fetcher(articles.toReversed()),renderer,prestate,sha),/TRADITIONAL_BLOG_FEATURED_API/);
  const stagingArticles=packageRow.environments.staging;
  const stagingRenderer=async()=>html(stagingArticles);
  const stagingAccepted=await verifyOnlineCandidates('staging',fixture,fetcher(stagingArticles,stage),stagingRenderer,prestate,sha);
  assert.equal(stagingAccepted.api_readback_count,1);
  assert.equal(stagingAccepted.dom_readback_count,1);
  await assert.rejects(verifyOnlineCandidates('staging',fixture,fetcher(articles,stage),stagingRenderer,prestate,sha),/TRADITIONAL_BLOG_FEATURED_API/);
  packageRow.environments.production=[...articles].reverse();
  save();
  assert.throws(()=>readCandidateRows(fixture,packageDigest(fixture,selection)));
 } finally {
  rmSync(fixture,{recursive:true,force:true});
 }
});

test('Traditional Article English source preserves one independent published root per environment',()=>{
 const selection='traditional-article-english';
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,1);
 for (const [environment,revision] of [['staging',681],['production',844]]) {
  const [row]=readCandidateRows(root,binding.package_sha256,environment).candidates;
  assert.deepEqual(row.identity,{org_id:0,locale:'zh-TW',slug:'why-mbti-and-holland-code-results-dont-match'});
  assert.equal(row.source_identity.source_locale,'en');
  assert.equal(row.source_expectations.source.id,42);
  assert.equal(row.source_expectations.source.published_revision_id,revision);
  assert.equal(row.source_expectations.existing_chinese,null);
 }
});

test('Traditional Article English source verifies reciprocal reader links and retains the English assessment route',async()=>{
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 const {package_sha256:sha}=inspectPackage(root,'traditional-article-english');
 const [row]=readCandidateRows(root,sha,'staging').candidates;
 const source=row.source_expectations.source;
 const sourcePath='/en/articles/'+source.slug, testPath='/en/tests/'+row.article_metadata.related_test_slug;
 const fixture={...row,snapshot:{content_md:'繁體正文'}};
 const html='<html lang="zh-TW"><head><meta charset="utf-8"><meta name="robots" content="noindex"><link rel="canonical" href="https://fermatmind.com'+pathFor(row)+'"></head><body><main><h1>繁體正文</h1><a href="'+sourcePath+'">English</a><a href="'+testPath+'">開始測驗</a></main></body></html>';
 assert.doesNotThrow(()=>assertDom(fixture,html,{missing:true},'staging'));
 assert.throws(()=>assertDom(fixture,html.replace(testPath,testPath.replace('/en/','/zh/')),{missing:true},'staging'),/TRADITIONAL_ARTICLE_ASSESSMENT_LINK/);
 assert.throws(()=>assertDom(fixture,html.replace(sourcePath,'/en/articles/other'),{missing:true},'staging'),/TRADITIONAL_ENGLISH_READER_LINK/);
 const chinese={id:77,source_article_id:source.id,slug:'simplified-source'};
 const linked={...fixture,source_expectations:{...fixture.source_expectations,existing_chinese:chinese}};
 const linkedHtml=html.replace(testPath,testPath.replace('/en/','/zh/'))
  .replace('</main>','<a href="/zh/articles/'+chinese.slug+'">简体中文</a></main>');
 assert.doesNotThrow(()=>assertDom(linked,linkedHtml,{missing:true},'staging'));
 const reviewedCta={href:testPath,label:'比較目前的版本'};
 const editorial={...fixture,editorial_package_v1:{cta_slots:[reviewedCta]},
  snapshot:{...fixture.snapshot,editorial_projection:{faq:[],ctas:[reviewedCta]}}};
 const editorialHtml=html.replace('開始測驗',reviewedCta.label);
 assert.doesNotThrow(()=>assertDom(editorial,editorialHtml,{missing:true},'staging'));
 assert.throws(()=>assertDom(editorial,html,{missing:true},'staging'),/TRADITIONAL_ARTICLE_EDITORIAL_DOM/);
 assert.throws(()=>assertDom(linked,linkedHtml.replace('简体中文','其他语言'),{missing:true},'staging'),/TRADITIONAL_ENGLISH_READER_LINK/);
 const links={en:source.slug,'zh-TW':row.identity.slug};
 const fetcher=async url=>{
  const original=new URL(url).searchParams.get('locale')==='en';
  return Response.json(original
   ? {ok:true,article:{id:source.id,locale:'en',published_revision_id:source.published_revision_id},reader_language_slugs:{en:source.slug}}
   : {ok:true,article:{locale:'zh-TW',source_locale:'en',source_article_id:source.id,translation_group_id:source.translation_group_id,published_revision_id:123,is_indexable:false,sitemap_eligible:false,llms_eligible:false},reader_language_slugs:links});
 };
 await assert.rejects(verifyOnlineCandidates('staging',root,fetcher,undefined,{[row.page_id]:{missing:true}},sha),/TRADITIONAL_ENGLISH_READER_PAIR/);
});

test('Traditional Article career and EQ batch binds each environment and preserves the public assessment link',()=>{
 const selection='traditional-article-career-eq';
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,4);
 const production=readCandidateRows(root,binding.package_sha256).candidates;
 const staging=readCandidateRows(root,binding.package_sha256,'staging').candidates;
 assert.deepEqual(production.map(row=>row.source_expectations.source.published_revision_id),[898,783,784,1000]);
 assert.deepEqual(staging.map(row=>row.source_expectations.source.published_revision_id),[745,464,465,466]);
 assert.deepEqual(production.map(row=>row.taxonomy_display.category.id),[11,1,38,38]);
 assert.deepEqual(staging.map(row=>row.taxonomy_display.category.id),[11,null,null,null]);
 assert.notEqual(staging[1].source_identity.translation_group_id,production[1].source_identity.translation_group_id);
 for (const row of [...staging,...production]) {
  assert.equal(row.source_expectations.source_snapshot_kind,'published_projection');
  const href='/zh/tests/'+row.article_metadata.related_test_slug;
  const fixture={...row,snapshot:{content_md:'繁體文章'}};
  const html='<html lang="zh-TW"><head><meta charset="utf-8"><meta name="robots" content="noindex"><link rel="canonical" href="https://fermatmind.com'+pathFor(row)+'"></head><body><main><h1>繁體文章</h1><a href="'+href+'">開始測驗</a></main></body></html>';
  assert.doesNotThrow(()=>assertDom(fixture,html,{missing:true},'production'));
  assert.throws(()=>assertDom(fixture,html.replace(href,href.replace('/zh/','/en/')),{missing:true},'production'),/TRADITIONAL_ARTICLE_ASSESSMENT_LINK/);
 }
});

test('Traditional Big Five metadata checks the public SEO title and rejects a leaked brand suffix',()=>{
 const binding=inspectPackage(root,'traditional-article-big-five');
 const source=readCandidateRows(root,binding.package_sha256,'staging').candidates.find(row=>row.identity.slug==='big-five-myths-types-destiny-and-perfect-scores');
 const base='大五人格五大誤區';
 const description='辨別常見誤區。';
 const row={...source,snapshot:{title:base,content_md:base,seo_text:{
  seo_title:base+' | FermatMind',og_title:base+' | FermatMind',seo_description:description,og_description:description,
 }}};
 const head=`<title>${base} | 費馬測試</title><meta name="description" content="${description}"><meta property="og:title" content="${base}"><meta property="og:description" content="${description}"><meta name="robots" content="noindex"><link rel="canonical" href="https://fermatmind.com${pathFor(row)}">`;
 const href='/zh/tests/'+row.article_metadata.related_test_slug;
 const html=`<html lang="zh-TW"><head><meta charset="utf-8">${head}</head><body><main><h1>${base}</h1><a href="${href}">開始測驗</a></main></body></html>`;
 assert.doesNotThrow(()=>assertDom(row,html,{missing:true},'staging'));
 for(const bad of [html.replace(base+' | 費馬測試',base+' | FermatMind | 費馬測試'),
  html.replace('property="og:title" content="'+base+'"','property="og:title" content="'+base+' | FermatMind"'),
  html.replace('property="og:title" content="'+base+'"','property="og:title" content="舊標題"')]){
  assert.throws(()=>assertDom(row,bad,{missing:true},'staging'),/METADATA/);
 }
});

test('Traditional Big Five articles retain the production English relationships while staging lacks those editions',()=>{
 const selection='traditional-article-big-five';
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 const paths=['backend/content_assets/public_content_53/'+selection+'.json'];
 assert.equal(classifyPaths(paths).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,12);
 const production=readCandidateRows(root,binding.package_sha256).candidates;
 const staging=readCandidateRows(root,binding.package_sha256,'staging').candidates;
 assert.deepEqual(production.map(row=>row.source_expectations.source.published_revision_id),[1121,721,1064,1111,1124,704,1105,1113,1126,1127,1128,1130]);
 assert.deepEqual(staging.map(row=>row.source_expectations.source.published_revision_id),[399,400,404,395,424,403,420,390,414,421,415,386]);
 for (const [index, stage] of staging.entries()) {
  const prod=production[index];
  assert.equal(stage.identity.slug,prod.identity.slug);
  assert.equal(stage.source_expectations.existing_english,null);
  assert.equal(stage.source_expectations.unpublished_english_slug,prod.source_expectations.existing_english.slug);
  assert.equal(prod.source_expectations.existing_english.source_article_id,prod.source_identity.source_article_id);
  assert.equal(prod.article_metadata.related_test_slug,'big-five-personality-test-ocean-model');
 }
 for (const rows of [staging,production]) {
  const faqRows=rows.filter(row=>row.snapshot.editorial_projection);
  assert.deepEqual(faqRows.map(row=>row.snapshot.editorial_projection.faq.length),[3,4]);
  for (const row of faqRows) {
   const {faq}=row.snapshot.editorial_projection;
   assert.deepEqual(row.editorial_package_v1.cta_slots,[]);
   const cta={key:'start_test',label:'開始測驗',href:'/zh/tests/'+row.article_metadata.related_test_slug,kind:'start_test'};
   assert.deepEqual(projection(row,{ok:true,article:{org_id:0,locale:'zh-TW',slug:row.identity.slug,status:'published',is_public:true},
    answer_surface_v1:{faq_blocks:faq},landing_surface_v1:{cta_bundle:[cta]}}).editorial_projection,{faq});
  }
 }
});

test('Traditional assessment guides preserve the published FAQ and each assessment destination',()=>{
 const selection='traditional-article-assessment-guides';
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,2);
 for (const [env,sourceRevisions] of [['staging',[708,760]],['production',[1173,920]]]) {
  const rows=readCandidateRows(root,binding.package_sha256,env).candidates;
  assert.deepEqual(rows.map(row=>row.source_expectations.source.published_revision_id),sourceRevisions);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection.faq.length),[5,5]);
  for (const row of rows) {
   const {faq,ctas}=row.snapshot.editorial_projection;
   const links=ctas??[{label:'開始測驗',href:'/zh/tests/'+row.article_metadata.related_test_slug}];
   const fixture={...row,snapshot:{content_md:'繁體文章',editorial_projection:{faq,ctas}}};
   const html='<html lang="zh-TW"><head><meta charset="utf-8"><meta name="robots" content="noindex"><link rel="canonical" href="https://fermatmind.com'+pathFor(row)+'"></head><body><main><h1>繁體文章</h1>'+faq.map(({question})=>'<p>'+question+'</p>').join('')+links.map(({label,href})=>'<a href="'+href+'">'+label+'</a>').join('')+'</main></body></html>';
   assert.doesNotThrow(()=>assertDom(fixture,html,{missing:true},env));
   assert.throws(()=>assertDom(fixture,html.replace(links.at(-1).href,'/zh/tests/missing'),{missing:true},env),/TRADITIONAL_ARTICLE_(ASSESSMENT_LINK|EDITORIAL_DOM)/);
  }
 }
});

test('Traditional interpretation batch binds all twelve reviewed sources to the correct environment',()=>{
 const selection='traditional-article-interpretation';
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,12);
 const staging=readCandidateRows(root,binding.package_sha256,'staging').candidates;
 const production=readCandidateRows(root,binding.package_sha256,'production').candidates;
 assert.deepEqual(staging.map(row=>row.source_expectations.source.published_revision_id),[410,411,658,417,428,393,659,651,652,649,389,409]);
 assert.deepEqual(production.map(row=>row.source_expectations.source.published_revision_id),[1066,737,1092,914,916,942,945,1115,1118,1062,1120,1125]);
 for (const [index, row] of staging.entries()) {
  const counterpart=production[index].source_expectations.existing_english;
  if (index < 7 || index >= 9) {
   assert.equal(row.source_expectations.existing_english,null);
   assert.equal(row.source_expectations.unpublished_english_slug,counterpart.slug);
  } else {
   assert.equal(row.source_expectations.existing_english.slug,counterpart.slug);
   assert.equal(row.source_expectations.existing_english.seo_present,false);
   assert.equal(counterpart.seo_present,true);
  }
  assert.equal(row.article_metadata.related_test_slug,'big-five-personality-test-ocean-model');
 }
 for (const rows of [staging,production]) {
  const faqRows=rows.filter(row=>row.snapshot.editorial_projection);
  assert.deepEqual(faqRows.map(row=>[row.identity.slug,row.snapshot.editorial_projection.faq.length]),[
   ['habits-and-big-five-traits-behavior-is-not-identity',4],['big-five-growth-guide',5],['big-five-narrative-portrait',5],['big-five-goal-setting-use-results-as-hypotheses',3],
  ]);
  assert.ok(faqRows.every(row=>row.editorial_package_v1.cta_slots.length===0));
 }
});

test('Traditional cross-group articles require the reviewed production pair and keep both CTA labels',()=>{
 const selection='traditional-article-cross-group';
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,12);
 for (const env of ['staging','production']) {
  const rows=readCandidateRows(root,binding.package_sha256,env).candidates;
  const paired=rows.slice(0,2), standard=rows.slice(2);
  assert.deepEqual(paired.map(row=>[row.source_expectations.source.id,row.source_expectations.existing_english.id]),[[78,79],[36,35]]);
  assert.deepEqual(standard.map(row=>row.source_expectations.source.published_revision_id),env==='staging'?[405,385,391,398,413,407,416,418,412,656]:[1065,1123,910,1132,915,702,909,917,912,913]);
  assert.deepEqual(standard.map(row=>row.source_expectations.existing_english?.id??null),env==='staging'?[null,null,null,null,null,null,null,null,null,null]:[237,239,228,225,250,246,227,252,242,247]);
  for (const row of paired) {
   assert.notEqual(row.source_expectations.source.translation_group_id,row.source_expectations.existing_english.translation_group_id);
   assert.equal(row.source_expectations.english_relationship,env==='production'?'reviewed_published_english':'independent_published_english');
   const {faq,ctas}=row.snapshot.editorial_projection;
   assert.equal(ctas.length,2);
   const fixture={...row,snapshot:{content_md:'繁體文章',editorial_projection:{faq,ctas}}};
   const html='<html lang="zh-TW"><head><meta charset="utf-8"><meta name="robots" content="noindex"><link rel="canonical" href="https://fermatmind.com'+pathFor(row)+'"></head><body><main><h1>繁體文章</h1>'+faq.map(({question})=>'<p>'+question+'</p>').join('')+ctas.map(({label,href})=>'<a href="'+href+'">'+label+'</a>').join('')+'</main></body></html>';
   assert.doesNotThrow(()=>assertDom(fixture,html,{missing:true},env));
   assert.throws(()=>assertDom(fixture,html.replace(ctas[1].label,'缺少原本按鈕'),{missing:true},env),/TRADITIONAL_ARTICLE_EDITORIAL_DOM/);
  }
 }
});

test('Traditional growth guides keep their original default links and all reviewed FAQs',()=>{
 const selection='traditional-article-reading-research';
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 const paths=['backend/content_assets/public_content_53/'+selection+'.json'];
 assert.equal(classifyPaths(paths).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,12);
 const scopes={production:[980,864],staging:[441,694]};
 for(const [environment,revisions] of Object.entries(scopes)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(0,2);
  assert.deepEqual(rows.map(row=>row.identity.slug),['mbti-growth-guide','iq-test-growth-guide']);
  assert.deepEqual(rows.map(row=>row.source_expectations.source.published_revision_id),revisions);
  assert.deepEqual(rows.map(row=>row.article_metadata.related_test_slug),[null,'iq-test-intelligence-quotient-assessment']);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection.faq.length),[5,5]);
  for(const row of rows){
   assert.deepEqual(row.editorial_package_v1.cta_slots,[]);
   assert.equal(Object.hasOwn(row.snapshot.editorial_projection,'ctas'),false);
  }
 }
});

test('Traditional career exploration binds current cover copy and the historical source proof separately',()=>{
 const selection='traditional-article-reading-research';
 const paths=['backend/content_assets/public_content_53/'+selection+'.json'];
 assert.equal(classifyPaths(paths).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,12);
 const revisions={production:[[1008,1009],[439,653]],staging:[[746,749],[439,667]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(2,4);
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english.published_revision_id]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection.faq.length),[5,5]);
  assert.deepEqual(rows.map(row=>row.snapshot.author_name),['Fermat Institute','FermatMind Editorial']);
  assert.equal(Object.hasOwn(rows[0].source_expectations.source,'legacy_cover_image_alt'),false);
  const proof=rows[1].source_expectations.source;
  assert.equal(Object.hasOwn(proof,'legacy_cover_image_alt'),environment==='production');
  if(environment==='production') assert.notEqual(createHash('sha256').update(proof.legacy_cover_image_alt).digest('hex'),proof.snapshot_sha256.cover_image_alt);
 }
});

test('Traditional Big Five comparisons preserve FAQ and original language availability',()=>{
 const selection='traditional-article-reading-research';
 const paths=['backend/content_assets/public_content_53/'+selection+'.json'];
 assert.equal(classifyPaths(paths).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,12);
 const revisions={production:[[1131,1141],[883,1138]],staging:[[427,null],[717,718]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(4,6);
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english?.published_revision_id??null]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection.faq.length),[3,4]);
  assert.deepEqual(rows.map(row=>row.snapshot.author_name),['費馬測試編輯部','費馬測試編輯部']);
  for(const row of rows) assert.deepEqual(row.editorial_package_v1.cta_slots,[]);
 }
});

test('Traditional Big Five research reading preserves versions, formulas and default reader actions',()=>{
 const selection='traditional-article-reading-research';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,12);
 const revisions={production:[[817,1068],[816,1067],[1094,1095],[1114,1160],[1063,1073],[717,775]],staging:[[654,null],[653,null],[431,null],[650,null],[419,null],[429,null]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(6,12);
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english?.published_revision_id??null]),expected);
  for(const [index,row] of rows.entries()){
   assert.equal(row.snapshot.author_name,'費馬測試編輯部');
   assert.equal(row.article_metadata.related_test_slug,'big-five-personality-test-ocean-model');
   if(index===2) assert.match(row.snapshot.content_md,/\[9\]/u);
   else if(index<4) assert.match(row.snapshot.content_md,/y = 6 − x/u);
   else if(index===4) assert.match(row.snapshot.content_md,/負荷量恆等性（metric invariance）/u);
   else {
    assert.match(row.snapshot.content_md,/遍歷性/u);
    assert.equal(row.snapshot.editorial_projection.faq.length,4);
   }
   assert.match(row.snapshot.content_md,/Unknown/u);
  }
 }
});

test('Traditional Big Five teamwork preserves original versions and structured FAQ',()=>{
 const selection='traditional-article-big-five-application';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,10);
 const revisions={production:[[1129,1163],[1112,1166],[1122,1140],[688,606]],staging:[[397,null],[396,null],[394,null],[402,null]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(0,4);
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english?.published_revision_id??null]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection?.faq.length??0),[4,3,4,0]);
  assert.deepEqual(rows.map(row=>row.snapshot.author_name),Array(4).fill('費馬測試編輯部'));
  assert.match(rows[0].snapshot.content_md,/15 分鐘/u);
  assert.match(rows[1].snapshot.content_md,/35 分鐘/u);
  assert.match(rows[2].snapshot.content_md,/articlebig5zh017bodyv1/u);
  assert.match(rows[3].snapshot.content_md,/social-battery-test/u);
 }
});

test('Traditional Big Five planning preserves example time, revision and assessment destinations',()=>{
 const selection='traditional-article-big-five-application';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,10);
 const revisions={production:[[709,727],[1041,1042]],staging:[[406,null],[657,null]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(4,6);
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english?.published_revision_id??null]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.author_name),['費馬測試編輯部','費馬測試編輯部']);
  assert.match(rows[0].snapshot.content_md,/17:30/u);
  assert.match(rows[1].snapshot.content_md,/v0\.2.*v0\.3/u);
  assert.match(rows[1].snapshot.content_md,/\/zh\/tests\/explore\/perfectionism/u);
  assert.match(rows[1].snapshot.content_md,/\/zh\/tests\/explore\/procrastinator-type-test/u);
 }
});

test('Traditional Big Five score-use keeps the source body visual information and original assessment actions',()=>{
 const selection='traditional-article-big-five-application';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,10);
 const revisions={production:[[1110,1108],[983,999],[1116,1117],[1099,1103]],staging:[[332,712],[392,null],[425,null],[384,null]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(6,10);
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english?.published_revision_id??null]),expected);
  assert.match(rows[0].snapshot.content_md,/原流程圖的繁體文字版/u);
  assert.match(rows[0].snapshot.body_visual.image_url,/^https:\/\/assets\.fermatmind\.com\/storage\/media-library\/variants\/org-0\/media-asset-\d+-[^/]+zh-twbody-visualv1\/hero_1600x900\.jpg$/u);
  assert.equal(rows[0].snapshot.body_visual.fallback_authorized,false);
  assert.match(rows[0].snapshot.content_md,/每天可記下的問題/u);
  assert.deepEqual(rows[0].editorial_package_v1.cta_slots.map(slot=>[slot.key,slot.href]),[
   ['mid_article','/zh/tests/big-five-personality-test-ocean-model'],
   ['end_article','/zh/tests/big-five-personality-test-ocean-model'],
  ]);
  assert.equal(rows[0].source_expectations.english_relationship,environment==='production'?'reviewed_published_english':'independent_published_english');
  assert.equal(rows[1].snapshot.editorial_projection.faq.length,4);
  assert.match(rows[2].snapshot.content_md,/Everything DiSC/u);
  assert.equal([...rows[3].snapshot.content_md.matchAll(/^[-] \*\*[NEOAC][1-6] [A-Za-z -]+（/gmu)].length,30);
  assert.match(rows[3].snapshot.content_md,/articlebig5zh007bodyv1/u);
 }
});

test('Traditional IQ articles retain the complete published FAQ and numeric examples',()=>{
 const selection='traditional-article-assessment-reading';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,10);
 const identities={production:[[260,807,261,1038],[6,1010,196,1021],[50,1174,197,1178]],staging:[[206,479,207,480],[6,475,196,476],[50,690,197,691]]};
 for(const [environment,expected] of Object.entries(identities)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(0,3);
  assert.deepEqual(rows.map(({source_expectations:{source,existing_english:english}})=>[source.id,source.published_revision_id,english.id,english.published_revision_id]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection.faq.length),[9,5,7]);
  assert.deepEqual(rows.slice(0,2).map(row=>row.editorial_package_v1.cta_slots),[[],[]]);
  assert.match(rows[0].snapshot.content_md,/100 \+ 15×1 = 115/u);
  assert.match(rows[1].snapshot.content_md,/18\/30/u);
  assert.deepEqual(rows[2].editorial_package_v1.cta_slots,[{
   key:'primary_iq_test',kind:'start_test',href:'/zh/tests/iq-test-intelligence-quotient-assessment',label:'開始 IQ 智商測驗',
  }]);
  assert.match(rows[2].snapshot.content_md,/100×Φ\(\(S−100\)÷15\)/u);
  assert.equal([...rows[2].snapshot.content_md.matchAll(/^\| \d+ \| \d+ \| [\d.]+% \|$/gmu)].length,31);
 }
});

test('Traditional IQ practice articles keep the published diagrams, observed paths and official destinations',()=>{
 const selection='traditional-article-assessment-reading';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,10);
 const revisions={production:[[1015,1056],[1032,1084],[1033,1035]],staging:[[700,701],[696,697],[710,711]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(3,6);
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english.published_revision_id]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection.faq.length),[4,5,4]);
  assert.ok(rows.every(row=>row.article_metadata.related_test_slug==='iq-test-intelligence-quotient-assessment'));
  assert.equal([...rows[0].snapshot.content_md.matchAll(/^```text$/gmu)].length,9);
  assert.deepEqual([...rows[0].snapshot.content_md.matchAll(/答案為([A-D])/gu)].map(match=>match[1]),['C','C','B','C','D']);
  assert.match(rows[1].snapshot.content_md,/https:\/\/www\.mensa\.org\.hk\/prior-evidence/u);
  assert.match(rows[2].snapshot.content_md,/n20261015a/u);
  assert.match(rows[2].snapshot.content_md,/當時顯示的「超過人群比例」（模型換算）/u);
 }
});

test('Traditional EQ and AI guides retain reviewed evidence boundaries and their distinct actions',()=>{
 const selection='traditional-article-assessment-reading';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,10);
 const revisions={production:[[1029,1088],[482,1144]],staging:[[791,470],[434,675]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(6,8);
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english.published_revision_id]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection.faq.length),[7,4]);
  assert.deepEqual(rows.map(row=>row.snapshot.author_name),[null,'FermatMind Editorial']);
  assert.equal(rows[0].editorial_package_v1.cta_slots[0].href,'/zh/tests/eq-test-emotional-intelligence-assessment');
  assert.deepEqual(rows[0].snapshot.body_visual,{
   image_url:'https://assets.fermatmind.com/storage/media-library/variants/articleeqscoreemotional-intelligencepillarbody-visualv1/hero_1600x900.jpg',
   fallback_authorized:false,
  });
  assert.deepEqual(rows[1].editorial_package_v1.cta_slots,[]);
  assert.match(rows[0].snapshot.content_md,/POMP=100×\(S−15\)÷60/u);
  assert.match(rows[1].snapshot.content_md,/298/u);
 }
});

test('Traditional MBTI reading guides preserve preference codes, report scales and the original assessment action',()=>{
 const selection='traditional-article-assessment-reading';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,10);
 const revisions={production:[[1078,1091],[1151,893]],staging:[[787,677],[655,729]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates.slice(8,10);
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english.published_revision_id]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection.faq.length),[5,0]);
  assert.equal(rows[1].source_expectations.english_relationship,'independent_published_english');
  assert.deepEqual(rows.map(row=>row.snapshot.author_name),[null,'Fermat Institute']);
  assert.ok(rows.every(row=>row.editorial_package_v1.cta_slots[0].href==='/zh/tests/mbti-personality-test-16-personality-types'));
  assert.match(rows[0].snapshot.content_md,/2 × 2 × 2 × 2/u);
  assert.match(rows[0].snapshot.content_md,/articlembtibasicspillarbody-visualv1/u);
  assert.match(rows[1].snapshot.content_md,/93\/144.*0–100.*50 為中點.*A\/T/u);
  assert.deepEqual(rows[1].snapshot.body_visual,{
   image_url:'https://assets.fermatmind.com/storage/media-library/variants/articlembti-full-report-career-relationship-communicationbody-visualv3/hero_1600x900.jpg',
   fallback_authorized:false,
  });
 }
});

test('Traditional Enneagram and career guides preserve admissions context and restored staging identities',()=>{
 const selection='traditional-article-enneagram-career-guides';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,6);
 const revisions={production:[[1053,1054],[1155,1157],[987,996],[986,1142],[1005,1006],[1168,1169]],staging:[[789,705],[713,714],[65,672],[64,668],[38,70],[843,null]]};
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates;
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english?.published_revision_id??null]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection?.faq.length??0),[6,5,8,0,5,0]);
  assert.deepEqual(rows.map(row=>row.editorial_package_v1?.cta_slots.length??0),[1,2,2,2,0,0]);
  assert.match(rows[0].snapshot.content_md,/25×（原始強度＋2）/u);
  assert.match(rows[2].snapshot.content_md,/6 月 22 日至 28 日/u);
  assert.match(rows[3].snapshot.content_md,/專業組.*調劑/su);
  assert.match(rows[4].snapshot.content_md,/偏好清晰度/u);
  assert.match(rows[5].snapshot.content_md,/12÷20＝60%.*12÷40＝30%/su);
  assert.equal(rows[5].source_expectations.source.id,environment==='staging'?291:295);
  assert.equal(rows[5].source_expectations.unpublished_english_slug,environment==='staging'?'mbti-vs-enneagram-reading-results':undefined);
 }
});

test('Traditional admissions and reading guides preserve reviewed sources and navigation',()=>{
 const selection='traditional-article-admissions-and-reading';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,6);
 const revisions={
  production:[[988,1145],[1002,1003],[683,759],[965,963],[1047,1048],[1051,1052]],
  staging:[[78,670],[75,671],[436,25],[835,null],[839,null],[841,null]],
 };
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates;
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english?.published_revision_id??null]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection?.faq.length??0),[4,0,5,2,0,0]);
  assert.deepEqual(rows.map(row=>row.editorial_package_v1?.cta_slots.length??0),[1,2,1,1,0,0]);
  assert.match(rows[0].snapshot.content_md,/服從調劑.*退檔/su);
  assert.match(rows[1].snapshot.content_md,/一分一段表.*800 字/su);
  assert.match(rows[3].snapshot.content_md,/SBII/u);
  assert.match(rows[4].snapshot.content_md,/15÷60=25%/u);
  assert.match(rows[5].snapshot.content_md,/58\.3/u);
  assert.deepEqual(rows.slice(3).map(row=>row.source_expectations.source.id),environment==='staging'?[283,287,289]:[281,287,291]);
  assert.deepEqual(rows[3].taxonomy_display.tags.map(({id,slug})=>[id,slug]),
   [[18,'mbti'],[environment==='staging'?178:189,'777c03dffbf5']]);
 }
});

test('Traditional relationship and guide articles preserve reviewed FAQs and exact source versions',()=>{
 const selection='traditional-article-relationship-and-guides';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,5);
 const revisions={
  production:[[1179,1180],[1043,1044],[886,887],[1079,1080],[437,652]],
  staging:[[845,847],[838,null],[833,null],[435,722],[437,660]],
 };
 for(const [environment,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates;
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english?.published_revision_id??null]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection?.faq.length??0),[0,0,0,4,5]);
  assert.ok(rows.every(row=>!row.snapshot.editorial_projection?.ctas));
  assert.match(rows[3].snapshot.content_md,/每組五題.*平均分數/u);
  const preference=rows[4].snapshot.editorial_projection.faq[1].answer;
  assert.match(preference,/單憑內向或外向/u);
  assert.ok(rows[4].snapshot.content_md.includes(preference));
  assert.equal(rows[0].taxonomy_display.tags.find(tag=>tag.slug==='e983e8f35861')?.id,
   environment==='staging'?179:193);
  assert.equal(Object.hasOwn(rows[4].source_expectations.source,'legacy_cover_image_alt'),environment==='production');
 }
});

test('Traditional article uses the reviewed visible assessment CTA rather than a related-test identifier',()=>{
 const root=resolve(fileURLToPath(new URL('../../backend',import.meta.url)));
 const selection='traditional-article-relationship-and-guides';
 const binding=inspectPackage(root,selection);
 for (const environment of ['staging','production']) {
  const rows=readCandidateRows(root,binding.package_sha256,environment).candidates;
  const article=rows.find(row=>row.identity.slug==='seven-deadly-sins-and-seven-virtues');
  assert.equal(article.article_metadata.related_test_slug,'seven-sins-virtues-test');
  assert.equal(article.source_expectations.editorial_cta_targets.some(cta=>cta.kind==='start_test'),false);
  const fixture={...article,snapshot:{content_md:'繁體文章'}};
  const html='<html lang="zh-TW"><head><meta charset="utf-8"><meta name="robots" content="noindex"><link rel="canonical" href="https://fermatmind.com'+pathFor(article)+'"></head><body><main><h1>繁體文章</h1></main></body></html>';
  assert.doesNotThrow(()=>assertDom(fixture,html,{missing:true},environment));

  const iq=rows.find(row=>row.identity.slug==='can-chatgpt-estimate-your-iq');
  const href=iq.source_expectations.editorial_cta_targets.find(cta=>cta.kind==='start_test').href;
  const iqFixture={...iq,snapshot:{content_md:'繁體文章'}};
  const iqHtml=html.replace(pathFor(article),pathFor(iq));
  assert.throws(()=>assertDom(iqFixture,iqHtml,{missing:true},environment),/TRADITIONAL_ARTICLE_ASSESSMENT_LINK/);
  assert.doesNotThrow(()=>assertDom(iqFixture,iqHtml.replace('</main>','<a href="'+href+'">開始測驗</a></main>'),{missing:true},environment));
 }
});

test('Traditional admissions checklists require reviewed native visuals in API and reader DOM',()=>{
 const selection='traditional-article-admissions-checklists';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,3);
 const revisions={staging:[84,79,90],production:[1150,1148,1149]};
 for (const [env,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,env).candidates;
  assert.deepEqual(rows.map(row=>row.source_expectations.source.published_revision_id),expected);
  for(const row of rows){
   const {faq,ctas}=row.snapshot.editorial_projection;
   const {image_url:image}=row.snapshot.body_visual;
   assert.equal(row.snapshot.body_visual.fallback_authorized,false);
   assert.ok(image.startsWith('https://assets.fermatmind.com/storage/media-library/'));
   assert.equal(row.article_metadata.cover_image_variants.editorial_package_v1.body_visual_image_url,image);
   const article={...row.identity,status:'published',is_public:true,body_visual:row.snapshot.body_visual};
   assert.deepEqual(projection(row,{ok:true,article}).body_visual,row.snapshot.body_visual);
   assert.equal(projection(row,{ok:true,article:{...article,body_visual:null}}).body_visual,null);
   const fixture={...row,snapshot:{content_md:'繁體文章',body_visual:row.snapshot.body_visual,editorial_projection:{faq,ctas}}};
   const img='<img src="'+image+'" alt="繁體文章插圖">';
   const html='<html lang="zh-TW"><head><meta charset="utf-8"><meta name="robots" content="noindex"><link rel="canonical" href="https://fermatmind.com'+pathFor(row)+'"></head><body><main><h1>繁體文章</h1><article data-testid="article-detail-content">'+img+'</article>'+faq.map(({question})=>'<p>'+question+'</p>').join('')+ctas.map(({label,href})=>'<a href="'+href+'">'+label+'</a>').join('')+'</main></body></html>';
   assert.doesNotThrow(()=>assertDom(fixture,html,{missing:true},env));
   assert.throws(()=>assertDom(fixture,html.replace(img,''),{missing:true},env),/TRADITIONAL_ARTICLE_BODY_VISUAL/);
   assert.throws(()=>assertDom(fixture,html.replace('alt="繁體文章插圖"','alt=""'),{missing:true},env),/TRADITIONAL_ARTICLE_BODY_VISUAL/);
  }
 }
});

test('Traditional evidence and AI articles keep historical evidence and copyable prompts',()=>{
 const selection='traditional-article-evidence-and-ai';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,3);
 const revisions={production:[[1049,1050],[1082,1083],[481,964]],staging:[[840,null],[831,null],[783,784]]};
 for(const [env,expected] of Object.entries(revisions)){
  const rows=readCandidateRows(root,binding.package_sha256,env).candidates;
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.published_revision_id,row.source_expectations.existing_english?.published_revision_id??null]),expected);
  assert.deepEqual(rows.map(row=>row.snapshot.editorial_projection?.faq.length??0),[0,0,5]);
  assert.match(rows[0].snapshot.content_md,/18:00.*National Careers Service/su);
  assert.match(rows[1].snapshot.title,/煞車踏板支架斷裂/u);
  assert.match(rows[1].snapshot.content_md,/11 月 5 日.*100km\/h/su);
  const content=rows[2].snapshot.content_md;
  assert.match(content,/E 70%、I 30%.*40 個百分點.*120 分鐘/su);
  const prompts=[...content.matchAll(/^```text\n([\s\S]*?)^```$/gm)].map(match=>match[1]);
  assert.equal(prompts.length,2);
  assert.ok(prompts.every(prompt=>prompt.includes('Unknown') && !/[请这为过对读语给结报]/u.test(prompt)));
 }
});

test('Traditional lineage batch retains English roots, Chinese copy and independent same-slug readers',()=>{
 const selection='traditional-article-source-lineage';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const binding=inspectPackage(root,selection);
 assert.equal(binding.expected_row_count,5);
 for(const [env,expected] of Object.entries({
  staging:[[285,null],[282,null],[280,294],[292,284],[284,292]],
  production:[[284,null],[274,null],[264,265],[297,283],[283,297]],
 })){
  const rows=readCandidateRows(root,binding.package_sha256,env).candidates;
  assert.deepEqual(rows.map(row=>[row.source_expectations.source.id,
   (row.source_expectations.existing_chinese??row.source_expectations.existing_english)?.id??null]),expected);
  const [career,truity,admissions,chinese,english]=rows;
  assert.deepEqual(rows.map(row=>row.source_identity.source_locale),['en','en','en','zh-CN','en']);
  assert.equal(truity.snapshot.editorial_projection.faq.length,3);
  assert.equal(career.snapshot.editorial_projection,undefined);
  assert.equal(admissions.source_expectations.existing_chinese.source_article_id,admissions.source_expectations.source.id);
  assert.equal(admissions.source_expectations.existing_chinese.translation_group_id,admissions.source_expectations.source.translation_group_id);
  assert.match(admissions.snapshot.title,/英格蘭/u);
  assert.equal(chinese.identity.slug,'free-personality-tests-results-compared');
  assert.equal(english.identity.slug,chinese.identity.slug+'-english-source');
  assert.notEqual(chinese.source_identity.translation_group_id,english.source_identity.translation_group_id);
  assert.notEqual(chinese.snapshot.content_md,english.snapshot.content_md);
 }
});

test('Traditional article media batch uses verified variants and keeps inline and standalone images separate',()=>{
 const selection='traditional-article-localized-media';
 assert.equal(classifyPaths(['backend/content_assets/public_content_53/'+selection+'.json']).operations.public_content_53_package,selection);
 const {package_sha256:digest,expected_row_count:count}=inspectPackage(root,selection);
 assert.equal(count,4);
 const canonical=/^https:\/\/assets\.fermatmind\.com\/storage\/media-library\/variants\/org-0\/media-asset-\d+-[^/]+zh-tw/u;
 for(const env of ['staging','production']){
  const [usage,mismatch,tracking,repeat]=readCandidateRows(root,digest,env).candidates;
  assert.deepEqual([usage,mismatch,tracking,repeat].map(row=>row.source_expectations.source.published_revision_id),
   env==='staging'?[842,92,104,94]:[1061,811,758,757]);
  assert.equal(usage.snapshot.body_visual,undefined);
  const usageImages=[...usage.snapshot.content_md.matchAll(/!\[[^\]]+\]\(([^)]+)\)/gu)];
  assert.equal(usageImages.length,1);
  assert.match(usageImages[0][1],canonical);
  assert.match(usageImages[0][1],/body-visualv1\/hero_1600x900\.jpg$/u);
  assert.doesNotMatch(usage.snapshot.content_md,/articlemonthly-usage-2026-10formszhv1/u);
  for(const row of [mismatch,tracking,repeat]){
   assert.match(row.snapshot.body_visual.image_url,canonical);
   assert.equal(row.snapshot.body_visual.fallback_authorized,false);
   assert.ok(!row.snapshot.content_md.includes(row.snapshot.body_visual.image_url));
  }
  for(const row of [usage,tracking,repeat]){
   assert.match(row.article_metadata.cover_image_url,canonical);
   assert.match(row.snapshot.seo_text.og_image_url,canonical);
   assert.notEqual(row.article_metadata.cover_image_url,row.snapshot.seo_text.og_image_url);
  }
  assert.deepEqual([mismatch,tracking,repeat].map(row=>row.snapshot.editorial_projection.faq.length),[6,6,7]);
 }
});

test('Traditional article index release checks the complete cohort and preserves staging protection',async()=>{
 const selection='traditional-article-index';
 const assets='content_assets/public_content_53';
 const filename=selection+'.json';
 assert.equal(classifyPaths(['backend/'+assets+'/'+filename]).operations.public_content_53_package,null);
 const fixture=mkdtempSync(resolve(tmpdir(),'traditional-index-online-'));
 const digest=value=>createHash('sha256').update(value).digest('hex');
 const body='## 繁體標題\n\n繁體文章';
 const title='繁體文章';
 const identity=(id,slug,locale)=>({id,slug,locale,published_revision_id:id+1000,translation_group_id:slug,
  canonical:'https://fermatmind.com/'+(locale==='zh-CN'?'zh':locale)+'/articles/'+slug});
 const rows=offset=>Array.from({length:115},(_,i)=>{
  const slug='index-proof-'+(i+1);
  return {target:identity(offset+i+1,slug,'zh-TW'),peers:[{...identity(offset+i+116,slug,'en'),hreflang_gate:null}],
   source_id:offset+i+116,source_locale:'en',llms_eligible:true,body_sha256:digest(body),title_sha256:digest(title)};
 });
 const manifest={schema:'fermatmind.traditional_article_index.v1',environments:{staging:rows(0),production:rows(400)}};
 manifest.environments.staging[0].peers[0].staging_control={indexable:false,has_seo:false,canonical_url:null,robots:null};
 try {
  symlinkSync(resolve(root,'app'),resolve(fixture,'app'),'dir');
  mkdirSync(resolve(fixture,assets),{recursive:true});
  writeFileSync(resolve(fixture,assets,filename),JSON.stringify(manifest));
  const sha=packageDigest(fixture,selection);
  for (const environment of ['staging','production']) {
   const stage=environment==='staging';
   const host=stage?'https://staging.fermatmind.com':'https://fermatmind.com';
   const bySlug=new Map(manifest.environments[environment].map(row=>[row.target.slug,row]));
   const local=identity=>host+new URL(identity.canonical).pathname;
   const html=(row,locale)=> {
    const subject=[row.target,...row.peers].find(member=>member.locale===locale);
    const links=stage?'':[row.target,...row.peers].map(member=>'<link rel="alternate" hreflang="'+member.locale+'" href="'+member.canonical+'">').join('');
    return '<html lang="'+locale+'"><head><meta charset="utf-8"><meta name="robots" content="'+(stage?'noindex':'index,follow')+'"><link rel="canonical" href="'+subject.canonical+'">'+links+'</head><body><main><article data-testid="article-detail-content"><h1>'+title+'</h1>繁體文章</article></main></body></html>';
   };
   const fetcher=(active,{leak=false,wrongAlternate=false}={})=>async url=>{
    const {pathname,searchParams,hostname}=new URL(url);
    const urls=active||leak?[...bySlug.values()].map(row=>local(row.target)):[];
    if (pathname.endsWith('/seo/sitemap-source')) return Response.json({ok:true,source:'backend_sitemap_generator',items:urls.map(loc=>({loc}))});
    if (pathname==='/sitemap.xml') return stage?new Response(null,{status:410}):new Response('<urlset>'+urls.map(loc=>'<loc>'+loc+'</loc>').join('')+'</urlset>');
    const match=pathname.match(/\/articles\/([^/]+)(\/seo)?$/);
    const row=bySlug.get(match[1]);
    if (!hostname.includes('api.')) return new Response(html(row,pathname.split('/')[1]));
    const locale=searchParams.get('locale');
    const members=[row.target,...row.peers];
    const subject=members.find(member=>member.locale===locale);
    const control=subject.staging_control;
    const index=control?.indexable ?? (active||locale!=='zh-TW');
    if (match[2]) {
     const alternates=row.peers.some(peer=>peer.staging_control)?{}:Object.fromEntries(members.map(member=>[member.locale,local(member)]));
     if (wrongAlternate) {
      if (stage) alternates.fr=local(subject);
      else delete alternates.en;
     }
     return Response.json({meta:{canonical:local(subject),robots:index?'index,follow':'noindex,nofollow',article_authority_v1:{alternate_eligibility:{alternates}}}});
    }
    return Response.json({ok:true,article:{...subject,org_id:0,title,content_md:body,status:'published',is_public:true,
     source_article_id:locale==='zh-TW'?row.source_id:null,source_locale:locale==='zh-TW'?'en':locale,
     is_indexable:index,sitemap_eligible:index,llms_eligible:index,
     seo_meta:control?null:active?{hreflang_gate_v1:{enabled:true}}:{}}});
   };
   const before=await readOnlinePrestate(environment,fixture,fetcher(false),undefined,sha);
   assert.equal(Object.keys(before).length,115);
   if (stage) await assert.rejects(readOnlinePrestate(environment,fixture,fetcher(false,{leak:true}),undefined,sha),/TRADITIONAL_INDEX_SITEMAP/);
   const renderer=async url=>html(bySlug.get(new URL(url).pathname.split('/').at(-1)),'zh-TW');
   const accepted=await verifyOnlineCandidates(environment,fixture,fetcher(true),renderer,before,sha);
   assert.deepEqual([accepted.api_readback_count,accepted.dom_readback_count,accepted.discovery_readback_count,
    accepted.reciprocal_peer_readback_count,accepted.browser_readback_count],[115,115,115,115,1]);
   await assert.rejects(verifyOnlineCandidates(environment,fixture,fetcher(true,{wrongAlternate:true}),renderer,before,sha),/TRADITIONAL_INDEX_SEO/);
  }
 } finally {
  rmSync(fixture,{recursive:true,force:true});
 }
});
