import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
import {requiredSeconds, IQ_PUBLIC_SCALE_BUDGET_SECONDS} from './production-release-budget.mjs';
import {IQ_PUBLICATION_TRANSPORT_TIMEOUT_MS} from './iq-public-scale-publish.mjs';
import {IQ_CANDIDATE_READ_TIMEOUT_MS, IQ_HTTP_READ_TIMEOUT_MS} from './iq-public-scale-online-qa.mjs';
import {classifyPaths} from './classify-paths.mjs';
import {inspectPackage} from './public-content-53-package.mjs';
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
    'iq_public_scale_publish','iq_public_articles_publish','iq_eq_topic_publish','public_content_53_publish'];
  const costs=[2600,6000,2600,2033,8000,3000,1600+10*600];
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
  assert.ok(production.includes('timeout-minutes: ${{ fromJSON(needs.policy.outputs.classification).operations.public_content_53_publish && 190 || 120 }}'));
  assert.ok(production.includes('PRODUCTION_JOB_BUDGET_SECONDS: ${{ fromJSON(needs.policy.outputs.classification).operations.public_content_53_publish && 11400 || 7200 }}'));
  const admission=production.match(/          remaining=\$\(\([\s\S]*?          test "\$remaining" -ge "\$required"[^\n]*/)?.[0];
  assert.ok(admission);
  assert.match(admission,/remaining=\$\(\(PRODUCTION_JOB_BUDGET_SECONDS -/);
  const operations=classifyPaths(['backend/content_assets/iq_public/entry/20261009-v1/IQ-01-en.md']).operations;
  assert.equal(operations.iq_public_scale_publish,true);
  const script=admission.replace(/'\$\{\{ needs.policy.outputs.operations \}\}'/,`'${Buffer.from(JSON.stringify(operations)).toString('base64')}'`);
  const run=elapsed=>spawnSync('bash',['-eu','-c',script],{encoding:'utf8',env:{...process.env,
    deploy_minutes:'30',PRODUCTION_JOB_BUDGET_SECONDS:'7200',PRODUCTION_JOB_STARTED_EPOCH:String(Math.floor(Date.now()/1000)-elapsed)}});
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
  deploy_minutes:'60',PRODUCTION_JOB_BUDGET_SECONDS:String(cap),PRODUCTION_JOB_STARTED_EPOCH:String(Math.floor(Date.now()/1000)-elapsed)}});
 const original=run(10800,66);
 assert.notEqual(original.status,0);
 assert.match(original.stderr,/required=10960s/);
 assert.equal(run(11400,300).status,0);
 const exhausted=run(11400,441);
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
  deploy_minutes:'35',PRODUCTION_JOB_BUDGET_SECONDS:'10800',PRODUCTION_JOB_STARTED_EPOCH:String(Math.floor(Date.now()/1000)-elapsed)}});
 assert.equal(run(180).status,0);
 const rows=inspectPackage(fileURLToPath(new URL('../../backend',import.meta.url)),'riasec-code-samples').expected_row_count;
 const firstLateElapsed=10800-requiredSeconds(35,operations,rows,true)+1;
 const late=run(firstLateElapsed);
 assert.notEqual(late.status,0);
 assert.match(late.stderr,/Production budget insufficient/);
});
