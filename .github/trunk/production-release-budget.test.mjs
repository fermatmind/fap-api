import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
import {requiredSeconds, publicContentOnlineRows, IQ_PUBLIC_SCALE_BUDGET_SECONDS,TRADITIONAL_INDEX_BUDGET_SECONDS,productionPlan,productionJobBudget} from './production-release-budget.mjs';
import {IQ_PUBLICATION_TRANSPORT_TIMEOUT_MS} from './iq-public-scale-publish.mjs';
import {IQ_CANDIDATE_READ_TIMEOUT_MS, IQ_HTTP_READ_TIMEOUT_MS} from './iq-public-scale-online-qa.mjs';
import {classifyPaths} from './classify-paths.mjs';
import {inspectPackage,readCandidateRows,packageDigest} from './public-content-53-package.mjs';
const readNativeBudgetRows = () => readCandidateRows('backend',packageDigest('backend','mbti-native-leaf-copy')).candidates;
import {fileURLToPath} from 'node:url';

const read = path => readFileSync(new URL(path, import.meta.url),'utf8');
test('IQ allowance preserves both publication transports and actual reader limits',()=>{
  assert.equal(IQ_PUBLICATION_TRANSPORT_TIMEOUT_MS,800000);
  assert.equal(IQ_CANDIDATE_READ_TIMEOUT_MS,30000);
  assert.equal(IQ_HTTP_READ_TIMEOUT_MS,15000);
  const publish=read('./iq-public-scale-publish.mjs');
  assert.match(publish,/timeout: IQ_PUBLICATION_TRANSPORT_TIMEOUT_MS/);
  assert.match(publish,/mode: 'recover'/);
  const qa=read('./iq-public-scale-online-qa.mjs');
  assert.match(qa,/timeout: IQ_CANDIDATE_READ_TIMEOUT_MS/);
  assert.match(qa,/AbortSignal.timeout\(IQ_HTTP_READ_TIMEOUT_MS\)/);
  assert.match(qa,/verifyDOM\(await renderer\(page, \{ surface: 'entry', verifyDOM \}\)\)/);
  const browser=read('./iq-public-article-online-qa.mjs');
  assert.match(browser,/render timeout'\)\), 45000\)/);
  assert.match(browser,/timeout: 15000/);
  assert.match(browser,/await verifyDOM\?\.\(captured.html\)/);
  const assertion=qa.slice(qa.indexOf('export function assertSsrCandidate'),qa.indexOf('export function readCandidateRows'));
  assert.equal((assertion.match(/assertPublicSurface\(|articleDom\(html/g)??[]).length,2);
  assert.match(browser,/const dom = articleDom\(html/);
  const close=read('./eq-new-source-online-qa.mjs').split('export async function closeOwnedBrowser')[1].split('export async function renderedDocument')[0];
  assert.match(close,/close timeout'\)\),2000\)/);
  assert.equal((close.match(/waitExit\(1500\)/g)??[]).length,3);
  assert.equal(IQ_PUBLIC_SCALE_BUDGET_SECONDS,2033);
});

test('all selected publisher combinations add independently without sharing recovery',()=>{
  const names=['eq_new_source_articles_publish','eq_existing_public_pages_publish','eq_new_english_articles_publish',
    'iq_public_scale_publish','iq_public_articles_publish','iq_eq_topic_publish','public_content_53_publish','reviewed_science_pages_publish'];
  const costs=[2600,6000,2600,2033,8000,3000,1600+10*600,1500];
  for(const minutes of [30,35,60]) for(let mask=0;mask<2**names.length;mask++) {
    const operations=Object.fromEntries(names.map((key,i)=>[key,Boolean(mask&(1<<i))]));
    const expected=minutes*60+1800+900+60+costs.reduce((sum,cost,i)=>sum+((mask&(1<<i))?cost:0),0);
    assert.equal(requiredSeconds(minutes,operations,10),expected);
  }
  assert.equal(requiredSeconds(30,{}),4560);
  assert.equal(requiredSeconds(60,{career_current_authority_release:true}),6360);
  assert.equal(requiredSeconds(30,{iq_public_scale_publish:true}),6593);
  assert.ok(requiredSeconds(60,{career_current_authority_release:true,iq_public_scale_publish:true})>7200);
});

test('actual production admission enforces the configured cap and elapsed setup time',()=>{
  const workflow=read('../workflows/deploy.yml');
  const production=workflow.match(/\n  production:\n([\s\S]*?)(?=\n  [\w-]+:|$)/)?.[1];
  assert.ok(production.includes("timeout-minutes: ${{ fromJSON(needs.policy.outputs.production_timeout_minutes || '120') }}"));
  assert.ok(production.includes("PRODUCTION_JOB_BUDGET_SECONDS: ${{ needs.policy.outputs.production_budget_seconds || '7200' }}"));
  assert.ok(production.includes('deploy_minutes="$PRODUCTION_DEPLOY_MINUTES"'));
  assert.ok(production.includes('required="$PRODUCTION_REQUIRED_SECONDS"'));
  assert.doesNotMatch(production,/deploy_timeout=(?:30|35|60)m/);
  assert.ok(workflow.includes('production_deploy_minutes: ${{ steps.production-budget.outputs.deploy_minutes }}'));
  assert.ok(workflow.includes('production_required_seconds: ${{ steps.production-budget.outputs.required_seconds }}'));
  assert.ok(workflow.includes('run: node .github/trunk/production-release-budget.mjs plan >> "$GITHUB_OUTPUT"'));
  const admission=production.match(/          remaining=\$\(\([\s\S]*?          test "\$remaining" -ge "\$required"[^\n]*/)?.[0];
  assert.ok(admission);
  assert.match(admission,/remaining=\$\(\(PRODUCTION_JOB_BUDGET_SECONDS -/);
  const operations=classifyPaths(['backend/content_assets/iq_public/entry/20261009-v1/IQ-01-en.md']).operations;
  assert.equal(operations.iq_public_scale_publish,true);
  const script=admission.replace(/'\$\{\{ needs.policy.outputs.operations \}\}'/,`'${Buffer.from(JSON.stringify(operations)).toString('base64')}'`);
  const run=elapsed=>spawnSync('bash',['-eu','-c',script],{encoding:'utf8',env:{...process.env,
    deploy_minutes:'30',PRODUCTION_REQUIRED_SECONDS:String(requiredSeconds(30,operations)),PRODUCTION_JOB_BUDGET_SECONDS:'7200',PRODUCTION_JOB_STARTED_EPOCH:String(Math.floor(Date.now()/1000)-elapsed)}});
  assert.equal(run(60).status,0);
  const late=run(608);
  assert.notEqual(late.status,0);
  assert.match(late.stderr,/Production budget insufficient/);
});

test('five Chinese reading pages retain the 60m forward path and all recovery reserves after setup',()=>{
 const workflow=read('../workflows/deploy.yml');
 const production=workflow.match(/\n  production:\n([\s\S]*?)(?=\n  [\w-]+:|$)/)?.[1];
 const admission=production.match(/          remaining=\$\(\([\s\S]*?          test "\$remaining" -ge "\$required"[^\n]*/)?.[0];
 assert.ok(admission);
 const operations={public_content_53_publish:true,public_content_53_package:'zh-reading-links',career_current_authority_release:true};
 const rows=inspectPackage(fileURLToPath(new URL('../../backend',import.meta.url)),'zh-reading-links').expected_row_count;
 assert.equal(rows,5);
 assert.equal(requiredSeconds(60,operations,rows),10960);
 const script=admission.replace(/'\$\{\{ needs.policy.outputs.operations \}\}'/,`'${Buffer.from(JSON.stringify(operations)).toString('base64')}'`);
 const run=(cap,elapsed)=>spawnSync('bash',['-eu','-c',script],{encoding:'utf8',env:{...process.env,
  deploy_minutes:'60',PRODUCTION_REQUIRED_SECONDS:String(requiredSeconds(60,operations,rows)),PRODUCTION_JOB_BUDGET_SECONDS:String(cap),PRODUCTION_JOB_STARTED_EPOCH:String(Math.floor(Date.now()/1000)-elapsed)}});
 const original=run(10800,66);
 assert.notEqual(original.status,0);
 assert.match(original.stderr,/required=10960s/);
 const plan=productionPlan(operations,{careerCurrent:true},rows);
 assert.equal(run(plan.job_budget_seconds,300).status,0);
 const exhausted=run(plan.job_budget_seconds,plan.job_budget_seconds-plan.required_seconds+1);
 assert.notEqual(exhausted.status,0);
 assert.match(exhausted.stderr,/Production budget insufficient/);
});


test('the selected reviewed package admits with full forward, recovery and settlement budgets',()=>{
 const workflow=read('../workflows/deploy.yml');
 const production=workflow.match(/\n  production:\n([\s\S]*?)(?=\n  [\w-]+:|$)/)?.[1];
 const admission=production.match(/          remaining=\$\(\([\s\S]*?          test "\$remaining" -ge "\$required"[^\n]*/)?.[0];
 assert.ok(admission);
 const operations={public_content_53_publish:true,public_content_53_package:'riasec-code-samples'};
 const script=admission.replace(/'\$\{\{ needs.policy.outputs.operations \}\}'/,`'${Buffer.from(JSON.stringify(operations)).toString('base64')}'`);
 const run=elapsed=>spawnSync('bash',['-eu','-c',script],{encoding:'utf8',env:{...process.env,
  deploy_minutes:'35',PRODUCTION_REQUIRED_SECONDS:String(requiredSeconds(35,operations,inspectPackage(fileURLToPath(new URL('../../backend',import.meta.url)),'riasec-code-samples').expected_row_count,true)),PRODUCTION_JOB_BUDGET_SECONDS:'10800',PRODUCTION_JOB_STARTED_EPOCH:String(Math.floor(Date.now()/1000)-elapsed)}});
 assert.equal(run(180).status,0);
 const rows=inspectPackage(fileURLToPath(new URL('../../backend',import.meta.url)),'riasec-code-samples').expected_row_count;
 const firstLateElapsed=10800-requiredSeconds(35,operations,rows,true)+1;
 const late=run(firstLateElapsed);
 assert.notEqual(late.status,0);
 assert.match(late.stderr,/Production budget insufficient/);
});


test('native32 keeps all adapter phases but budgets the one actual online consumer', () => {
  const operations = {public_content_53_publish:true,public_content_53_package:'mbti-native-leaf-copy'};
  const rows = readNativeBudgetRows();
  assert.equal(rows.length,32);
  assert.equal(publicContentOnlineRows(rows),1);
  assert.equal(requiredSeconds(30,operations,publicContentOnlineRows(rows)),6760);
  assert.equal(publicContentOnlineRows([{kind:'page',page_id:'PAGE-01',identity:{slug:'example'}}]),1);
  assert.equal(publicContentOnlineRows([...rows,{kind:'page',page_id:'PAGE-01',identity:{slug:'example'}}]),2);
  assert.equal(requiredSeconds(30,operations,32),25360);
});

test('the real Career science and ten-page support union shares one production plan',()=>{
  const operations={career_current_authority_release:true,reviewed_science_pages_publish:true,
    public_content_53_publish:true,public_content_53_package:'support-contact'};
  const rows=readCandidateRows('backend',packageDigest('backend','support-contact')).candidates;
  assert.equal(rows.length,10);
  const plan=productionPlan(operations,{careerCurrent:true},publicContentOnlineRows(rows));
  assert.deepEqual(plan,{deploy_minutes:60,required_seconds:15460,setup_reserve_seconds:600,job_timeout_minutes:268,job_budget_seconds:16080});
  const cli=spawnSync(process.execPath,['.github/trunk/production-release-budget.mjs','plan'],{encoding:'utf8',env:{...process.env,
    OPERATIONS_BASE64:Buffer.from(JSON.stringify(operations)).toString('base64'),CAREER_CURRENT:'true',COMPETITIVE_EVIDENCE:'false',COUNCIL_RUNTIME_CLOSEOUT:'false'}});
  assert.equal(cli.status,0,cli.stderr);
  assert.deepEqual(Object.fromEntries(cli.stdout.trim().split('\n').map(line=>{const [key,value]=line.split('=');return [key,Number(value)];})),plan);
  const production=read('../workflows/deploy.yml').match(/\n  production:\n([\s\S]*?)(?=\n  [\w-]+:|$)/)?.[1];
  const admission=production.match(/          remaining=\$\(\([\s\S]*?          test "\$remaining" -ge "\$required"[^\n]*/)?.[0];
  const run=(cap,elapsed)=>spawnSync('bash',['-eu','-c',admission],{encoding:'utf8',env:{...process.env,
    deploy_minutes:String(plan.deploy_minutes),PRODUCTION_REQUIRED_SECONDS:String(plan.required_seconds),PRODUCTION_JOB_BUDGET_SECONDS:String(cap),
    PRODUCTION_JOB_STARTED_EPOCH:String(Math.floor(Date.now()/1000)-elapsed)}});
  const old=run(11400,64);assert.notEqual(old.status,0);assert.match(old.stderr,/required=15460s/);
  assert.equal(run(plan.job_budget_seconds,64).status,0);
  assert.notEqual(run(plan.job_budget_seconds,plan.job_budget_seconds-plan.required_seconds+1).status,0);
  assert.throws(()=>productionPlan(operations,{careerCurrent:true}),/ROW_COUNT_INVALID/);
});

test('the verified publisher context selects the forward deadline once',()=>{
  assert.equal(productionPlan({}).deploy_minutes,30);
  assert.equal(productionPlan({},{competitiveEvidence:true}).deploy_minutes,35);
  assert.equal(productionPlan({},{competitiveEvidence:true,councilRuntimeCloseout:true}).deploy_minutes,60);
  assert.equal(productionPlan({},{councilRuntimeCloseout:true}).deploy_minutes,30);
  assert.equal(productionPlan({},{careerCurrent:true}).deploy_minutes,60);
  assert.equal(productionPlan({career_cache:true}).deploy_minutes,60);
  assert.equal(productionPlan({}).job_timeout_minutes,120);
  assert.equal(productionPlan({public_content_53_publish:true},{},1).job_timeout_minutes,190);
  assert.throws(()=>productionPlan({},{careerCurrent:'true'}),/CONTEXT_INVALID/);
  assert.throws(()=>productionPlan({career_cache:'true'}),/OPERATION_INVALID/);
});

test('capacity refuses above the hosted limit instead of shrinking any reserve',()=>{
  assert.equal(productionJobBudget(21000,true).job_timeout_minutes,360);
  assert.throws(()=>productionJobBudget(21001,true),/EXCEEDS_PLATFORM_LIMIT/);
  assert.throws(()=>productionJobBudget(Infinity),/BUDGET_INVALID/);
  assert.throws(()=>productionJobBudget(0),/BUDGET_INVALID/);
});


test('bounded Traditional index batches fit the existing deploy window',()=>{
  const operations={public_content_53_publish:true,public_content_53_package:'traditional-article-index'};
  const plan=productionPlan(operations,{},115);
  assert.equal(plan.required_seconds,30*60+1800+900+60+TRADITIONAL_INDEX_BUDGET_SECONDS);
  assert.ok(plan.job_timeout_minutes<=360);
  assert.deepEqual(productionPlan(operations,{},16),plan);
  assert.throws(()=>productionPlan(operations,{},116),/TRADITIONAL_INDEX_ROW_COUNT_INVALID/);
  assert.throws(()=>productionPlan({...operations,public_content_53_package:'assets'},{},115),/PRODUCTION_BUDGET_EXCEEDS_PLATFORM_LIMIT/);
});
