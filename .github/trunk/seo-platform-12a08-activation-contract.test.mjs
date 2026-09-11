import assert from 'node:assert/strict';
import test from 'node:test';
import {mkdtempSync,writeFileSync,readFileSync,rmSync,mkdirSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {execFileSync} from 'node:child_process';
import {fingerprint,scopeFor,mayCarry,MISSIONS,scopedReceipt,CHECKS,digest} from './seo-platform-12a08-activation.mjs';
import {verifyState} from './seo-platform-12a08-release.mjs';
import {classifyPaths} from './classify-paths.mjs';
test('explicit shared versus mission dependencies exclude ordinary copy, retain identities and authority',()=>{
 for(const path of ['backend/routes/api.php','backend/composer.lock','backend/app/Http/Middleware/Auth.php','backend/content_assets/personality_public/current/manifest.json']) assert.deepEqual(scopeFor(path),['public']);
 assert.deepEqual(scopeFor('backend/docs/example.md'),[]);
 assert.deepEqual(scopeFor('backend/content_assets/personality_public/current/page/zh-CN.md'),[]);
 assert.deepEqual(scopeFor('backend/app/Services/SeoCouncil/Platform12/Evaluation/Platform12DailySecurityDriftEvaluator.php'),[MISSIONS[2]]);
 const result=classifyPaths(['backend/app/Services/SeoCouncil/Platform12/Platform12RuntimeControl.php','.github/workflows/deploy.yml']);
 assert.equal(result.operations.a08_gate_only,true);
 assert.equal(classifyPaths(['backend/app/Services/SeoCouncil/Platform12/Platform12RuntimeControl.php','backend/app/Services/Payments/Order.php']).operations.a08_gate_only,false);
});
test('carry requires real Git ancestry, both fingerprints and equal vectors; v1 never authorizes',()=>{
 const root=mkdtempSync(`${tmpdir()}/a08-git-`); const git=(...args)=>execFileSync('git',args,{cwd:root}).toString().trim();
 try {
  git('init','-q');git('config','user.email','test@example.test');git('config','user.name','Test');
  mkdirSync(`${root}/backend/routes`,{recursive:true});writeFileSync(`${root}/backend/routes/api.php`,'a');git('add','.');git('commit','-qm','initial');
  const source=git('rev-parse','HEAD'),fp=fingerprint(root,source),vector={policy:'x'};
  const manifest={schema_version:'seo.platform12_a08_activation.v2',repository:'fermatmind/fap-api',bound_production_sha:source,validation:{public_checks:{fingerprint:fp.public}},missions:{[MISSIONS[0]]:{checks:{fingerprint:fp[MISSIONS[0]]}}},runtime:{version_vector:vector}};
  writeFileSync(`${root}/README.md`,'copy');git('add','.');git('commit','-qm','copy');
  const candidate={production_sha:git('rev-parse','HEAD'),version_vector:vector};
  assert.equal(mayCarry(manifest,candidate,MISSIONS[0],root),true);
  assert.equal(mayCarry({...manifest,schema_version:'seo.platform12_a08_activation.v1'},candidate,MISSIONS[0],root),false);
  assert.equal(mayCarry(manifest,{...candidate,version_vector:{policy:'changed'}},MISSIONS[0],root),false);
  writeFileSync(`${root}/backend/routes/api.php`,'permissions changed');git('add','.');git('commit','-qm','auth');
  assert.equal(mayCarry(manifest,{...candidate,production_sha:git('rev-parse','HEAD')},MISSIONS[0],root),false);
  assert.equal(mayCarry({...manifest,bound_production_sha:git('rev-parse','HEAD')},candidate,MISSIONS[0],root),false);
 } finally {rmSync(root,{recursive:true,force:true});}
});
test('Current probe readback dependencies require M1 revalidation without content publication',()=>{
 const paths=['backend/app/Services/Ops/PublicContentDeliveryProbeService.php','backend/app/Services/Ops/PublicContentPublicationReadbackService.php'];
 for(const path of [...paths,'backend/app/Domain/Personality/Current/PersonalityCurrentPageReader.php']) assert.deepEqual(scopeFor(path),[MISSIONS[0]]);
 const result=classifyPaths([...paths,'backend/tests/Feature/Console/ProbePublicContentDeliveryCommandTest.php']);
 assert.equal(result.operations.a08_scoped_checks,true);
 assert.equal(result.deploy,true);
 assert.equal(result.operations.publisher_required,false);
 assert.equal(result.operations.seo_council_orchestration,false);
 assert.equal(result.flags.seo_discoverability,false);
});
test('offline receipts cannot omit test scope or manufacture real source evidence',()=>{
 assert.throws(()=>scopedReceipt('<testcase name="unrelated"/>',execFileSync('git',['rev-parse','HEAD']).toString().trim()),/COVERAGE|RESULTS/);
 assert.throws(()=>scopedReceipt('<testcase/><failure/>','a'.repeat(40)),/RESULTS/);
});
test('pause, generation, pending counts and exact SHA must be preserved',()=>{
 const state={gate_only:true,sha:'a'.repeat(40),paused:true,generation:'x',counts:{runs:0},selected_missions:[],business_guards_closed:true,operations_readonly:true};
 assert.equal(verifyState(state,state,state.sha),true);
 for(const extra of [{generation:'y'},{paused:false},{counts:{runs:1}},{selected_missions:[MISSIONS[0]]},{sha:'b'.repeat(40)}]) assert.throws(()=>verifyState(state,{...state,...extra},state.sha));
});
test('existing workflows publish completed scoped evidence without runtime operations',()=>{
 const deploy=readFileSync(new URL('../workflows/deploy.yml',import.meta.url),'utf8');
 assert.doesNotMatch(deploy,/workflow_dispatch:|seo:council-runtime (?:resume|pause)|seo:council-scheduled --acceptance/);
 assert.match(deploy,/needs: \[policy, staging, production\]/);
 assert.match(deploy,/a08_gate_only != true/);
 assert.match(deploy,/seo-council-a08-activation-\$\{\{/);
 const ci=readFileSync(new URL('../workflows/ci.yml',import.meta.url),'utf8');
 assert.match(ci,/--log-junit=/);assert.match(ci,/scoped-receipt/);
});
test('Nightly high-risk or unknown failures cannot hide behind daily or unrelated scoped tests',async()=>{
 const {assessNightly}=await import('./seo-platform-12a08-release.mjs');
 const run={id:1,head_sha:'a'.repeat(40)};
 const full=[{name:'Full PHPUnit regression and performance contracts',conclusion:'failure'}];
 assert.throws(()=>assessNightly(run,[{name:'CodeQL and Semgrep security scan',conclusion:'failure'}],'',{}),/HIGH_RISK/);
 assert.throws(()=>assessNightly(run,full,'FAILED  Test at tests/Feature/UnknownTest.php:12',{covered_classes:[]}),/UNKNOWN/);
 const result=assessNightly(run,full,'FAILED  Test at tests/Feature/PermissionTest.php:12',{sha:'b'.repeat(40),covered_classes:['Tests\\Feature\\PermissionTest']});
 assert.equal(result.disposition,'CURRENT_CANDIDATE_FOCUSED_REVALIDATION');
 assert.equal(result.check_scope,'weekly_full_checks');
});
test('Current package body digests may change while schema, authority and identity stay bound',async()=>{
 const {contentIdentity}=await import('./seo-platform-12a08-activation.mjs');
 const page={schema_version:'v1',identity:{slug:'one'},authority:'repository',blocks:[{text:'before'}]};
 assert.deepEqual(contentIdentity(page),contentIdentity({...page,blocks:[{text:'after'}]}));
 for(const change of [{schema_version:'v2'},{authority:'database'},{identity:{slug:'two'}}]) assert.notDeepEqual(contentIdentity(page),contentIdentity({...page,...change}));
 const manifest={aggregate_sha256:'old',schema_version:'v1',files:[{path:'one',canonical_slug:'one',sha256:'old',bytes:1}],set_hashes:{slug_set_sha256:'identity',source_semantic_aggregate_sha256:'old'}};
 const body=structuredClone(manifest);body.aggregate_sha256='new';body.files[0].sha256='new';body.files[0].bytes=2;body.set_hashes.source_semantic_aggregate_sha256='new';
 assert.deepEqual(contentIdentity(manifest,true),contentIdentity(body,true));body.files[0].canonical_slug='two';assert.notDeepEqual(contentIdentity(manifest,true),contentIdentity(body,true));
});
test('staging read-only transport uses the existing host identity instead of the repository agent key',()=>{
 const root=mkdtempSync(`${tmpdir()}/a08-ssh-`);
 try {
  const ssh=`#!/bin/sh\nfound=false\nfor arg do test "$arg" != fixture-host-key || found=true; done\n$found || exit 73\ncat >/dev/null\nprintf '{}\\n'\n`;
  writeFileSync(`${root}/ssh`,ssh,{mode:0o755});
  execFileSync('bash',['backend/scripts/deploy/seo_a08_transport.sh','state',`${root}/state.json`],{env:{...process.env,PATH:`${root}:${process.env.PATH}`,TARGET:'staging',DEPLOY_IDENTITY_FILE_STG:'fixture-host-key',DEPLOY_PATH:'/fixture',DEPLOY_PORT:'22',DEPLOY_USER:'fixture',DEPLOY_HOST:'example.test',A08_GATE_ONLY:'true'}});
 }finally{rmSync(root,{recursive:true,force:true});}
});

test('gate-only releases omit only the skipped sitemap warm postcondition',()=>{
 const recipe=readFileSync(new URL('../../deploy.php',import.meta.url),'utf8');
 const task=name=>recipe.split(`task('${name}', function () {`)[1].split("\n});")[0];
 assert.match(task('guard:career-discoverability-post-sitemap'),/a08_gate_only/);
 for(const name of ['guard:career-runtime-projection-authority','guard:career-discoverability-pre-sitemap']) assert.doesNotMatch(task(name),/a08_gate_only/);
 assert.match(recipe,/after\('guard:career-discoverability-post-sitemap', 'guard:public-content-release'\)/);
});

test('real source artifacts retain business HOLD while fixtures and forged terminal evidence fail closed', async()=>{
 const {sourceAcceptance,bindControlled}=await import('./seo-platform-12a08-release.mjs');
 const {digest}=await import('./seo-platform-12a08-activation.mjs');
 const sha='a'.repeat(40), id=MISSIONS[0], print='b'.repeat(64), vector={policy:'c'.repeat(64)}, artifact='sha256:'+'d'.repeat(64);
 const seal=body=>({...body,receipt_digest:digest(JSON.stringify(body))});
 const source=seal({schema_version:'seo.a08_source_check.v1',repository:'fermatmind/fap-api',environment:'production',sha,mission_id:id,
  real_runtime:true,mission_submitted:false,notification_sent:false,business_write_enabled:false,version_vector:vector,
  source_wiring_status:'VERIFIED',source_gaps:[],observed_verdict:'DATA_FRESHNESS_HOLD',sources:[],
  captured_at:new Date(Date.now()-1000).toISOString(),expires_at:new Date(Date.now()+60000).toISOString()});
 const acceptance=sourceAcceptance(source,sha,id,print,vector,artifact);
 assert.equal(acceptance.status,'pass');assert.equal(acceptance.observed_verdict,'DATA_FRESHNESS_HOLD');
 const {receipt_digest,...body}=source;
 assert.throws(()=>sourceAcceptance(seal({...body,real_runtime:false}),sha,id,print,vector,artifact),/BINDING/);
 assert.equal(sourceAcceptance(seal({...body,source_wiring_status:'HOLD',source_gaps:['missing']}),sha,id,print,vector,artifact).status,'pending');
 const manifest={bound_production_sha:sha,runtime:{version_vector:vector},missions:{[id]:{checks:{fingerprint:print},source_acceptance:acceptance}}};
 assert.throws(()=>bindControlled(manifest,source,artifact),/CONTROLLED/);
 const terminal=seal({schema_version:'seo.a08_controlled_acceptance.v1',environment:'production',sha,mission_id:id,
  source_receipt_digest:source.receipt_digest,version_vector:vector,fingerprint:print,terminal_committed:true,
  receipt_hash:'e'.repeat(64),receipt_to_ui_verified:true,runtime_boundaries_verified:true,business_write_enabled:false});
 assert.equal(bindControlled(manifest,terminal,artifact).missions[id].end_to_end_acceptance.status,'pass');
 const {receipt_digest:ignored,...terminalBody}=terminal;
 for (const extra of [{mission_id:MISSIONS[2]},{receipt_to_ui_verified:false},{terminal_committed:false},{source_receipt_digest:'f'.repeat(64)}]) {
  assert.throws(()=>bindControlled(manifest,seal({...terminalBody,...extra}),artifact),/CONTROLLED/);
 }
});


test('mixed content release retains its gates without invoking old Council ingestion for A08',()=>{
 const wiring='backend/app/Services/SeoCouncil/Platform12/Platform12ProductionEvidenceReader.php';
 const mixed=[wiring,'backend/database/migrations/2026_09_07_160000_publish_assessment_landing_en.php','backend/database/data/assessment_landing_en_20260907.json','.github/workflows/deploy.yml'];
 const result=classifyPaths(mixed);
 assert.equal(result.operations.a08_gate_only,false);
 assert.equal(result.operations.a08_readonly_wiring,true);
 assert.equal(result.operations.a08_scoped_checks,true);
 assert.equal(result.operations.seo_council_orchestration,true);
 assert.equal(result.flags.backward_compatible_migration,true);
 for(const path of ['backend/app/Services/SeoCouncil/Measurement/ReadOnlyMeasurementEvidenceBundleLoader.php','backend/routes/api.php','backend/app/Services/SeoCouncil/Platform12/Platform12ModelRuntime.php']) {
  assert.equal(classifyPaths([...mixed,path]).operations.a08_readonly_wiring,false);
 }
 const deploy=readFileSync(new URL('../workflows/deploy.yml',import.meta.url),'utf8');
 assert.match(deploy,/and \.classification\.operations\.a08_readonly_wiring != true/);
});

test('HMAC bootstrap atomically installs the fixed pair and refuses existing-key rotation',()=>{
 const deployer=readFileSync(new URL('../../deploy.php',import.meta.url),'utf8');
 const section=deployer.slice(deployer.indexOf('function deployInstallSeoIntelRuntimeEnvironment'),deployer.indexOf("task('runtime:configure-seo-intel'"));
 const script=section.match(/\$script = <<<'PHP'\n([\s\S]+?)\nPHP;/)[1];
 const dir=mkdtempSync(`${tmpdir()}/a08-hmac-`), env=`${dir}/env`, patch=`${dir}/patch`;
 const pair={SEO_AGENT_EVIDENCE_QUERY_HMAC_KEY:'a'.repeat(64),SEO_AGENT_EVIDENCE_QUERY_HMAC_KEY_VERSION:'v1-test'};
 const run=()=>execFileSync('php',['-d','display_errors=0','-r',script,env,patch],{stdio:['pipe','pipe','pipe']});
 try {
  writeFileSync(env,'UNRELATED="preserved"\n');writeFileSync(patch,JSON.stringify(pair));
  run();const initialized=readFileSync(env,'utf8');assert.match(initialized,/UNRELATED="preserved"/);
  run();assert.equal(readFileSync(env,'utf8'),initialized);
  writeFileSync(patch,JSON.stringify({...pair,SEO_AGENT_EVIDENCE_QUERY_HMAC_KEY:'b'.repeat(64)}));
  assert.throws(run);assert.equal(readFileSync(env,'utf8'),initialized);
  writeFileSync(patch,JSON.stringify({...pair,UNAPPROVED:'value'}));assert.throws(run);
  assert.equal(readFileSync(env,'utf8'),initialized);
 } finally {rmSync(dir,{recursive:true,force:true});}
 assert.match(deployer,/if \(deployBooleanOption\('a08_gate_only', false\)\) \{\n        deployInstallSeoIntelRuntimeEnvironment\(deploySeoQueryHmacEnvironment\(\)\);\n\n        return;/);
 const deploy=readFileSync(new URL('../workflows/deploy.yml',import.meta.url),'utf8');
 for(const key of Object.keys(pair)) assert.equal(deploy.split(`${key}: \${{ secrets.${key} }}`).length-1,2);
});

test('authorized M3 requires safe source and terminal artifacts plus preceding mission acceptance',async()=>{
 const {bindControlled}=await import('./seo-platform-12a08-release.mjs');
 const {digest}=await import('./seo-platform-12a08-activation.mjs');
 const sha='a'.repeat(40),id=MISSIONS[2], vector={policy:'b'.repeat(64)}, fingerprint='c'.repeat(64), receipt='d'.repeat(64);
 const proof=()=>({bound_production_sha:sha,runtime:{version_vector:vector},missions:Object.fromEntries(MISSIONS.map(m=>[m,{checks:{fingerprint},source_acceptance:{status:'pass',receipt_digest:receipt,observed_verdict:'READY'},end_to_end_acceptance:{status:m===id?'pending':'pass'}}]))});
 const body={schema_version:'seo.a08_controlled_acceptance.v1',environment:'production',sha,mission_id:id,version_vector:vector,
  fingerprint,source_receipt_digest:receipt,terminal_committed:true,receipt_hash:'e'.repeat(64),receipt_to_ui_verified:true,runtime_boundaries_verified:true,business_write_enabled:false,observed_verdict:'READY'};
 const seal=x=>({...x,receipt_digest:digest(JSON.stringify(x))});
 assert.equal(bindControlled(proof(),seal(body),'sha256:'+'f'.repeat(64)).missions[id].end_to_end_acceptance.status,'pass');
 assert.throws(()=>bindControlled(proof(),seal({...body,observed_verdict:'HOLD'}),'sha256:'+'f'.repeat(64)),/CONTROLLED/);
 const unsafe=proof();unsafe.missions[id].source_acceptance.observed_verdict='HOLD';
 assert.throws(()=>bindControlled(unsafe,seal(body),'sha256:'+'f'.repeat(64)),/CONTROLLED/);
 const dir=mkdtempSync(`${tmpdir()}/a08-m3-`), output=`${dir}/output`;
 const state={paused:false,generation:'1'.repeat(32),selected_missions:MISSIONS.slice(0,2)};
 const command=new URL('./seo-platform-12a08-transition.mjs',import.meta.url).pathname;
 const run=(manifest,pause=false)=>{
  writeFileSync(`${dir}/activation.json`,JSON.stringify(manifest));writeFileSync(`${dir}/a08-install-after.json`,JSON.stringify({...state,paused:pause}));writeFileSync(output,'');
  execFileSync(process.execPath,[command,'prepare','2'],{cwd:dir,env:{...process.env,GITHUB_OUTPUT:output}});
  return readFileSync(output,'utf8').trim();
 };
 try {
  assert.equal(run(proof()),'ready=true');
  assert.equal(JSON.parse(readFileSync(`${dir}/transition-2.json`)).expected_generation,state.generation);
  assert.equal(run(proof(),true),'ready=false');
  assert.equal(run(unsafe),'ready=false');
  const missing=proof();missing.missions[MISSIONS[1]].end_to_end_acceptance.status='pending';assert.equal(run(missing),'ready=false');
 }finally{rmSync(dir,{recursive:true,force:true});}
});


test('Current fingerprints retain exact semantics above the former 128 MiB package limit',()=>{
 const root=mkdtempSync(`${tmpdir()}/a08-large-current-`);
 const git=(...args)=>execFileSync('git',args,{cwd:root}).toString().trim();
 try {
  git('init','-q');git('config','user.email','test@example.test');git('config','user.name','Test');
  const directory='backend/content_assets/career/current/careers';
  mkdirSync(`${root}/${directory}`,{recursive:true});
  const rows=[], body='x'.repeat(1024*1024);
  for(let index=0;index<130;index++) {
   const path=`${directory}/career-${index}.json`, identity={schema_version:'v1',identity:{slug:`career-${index}`},authoring_structure:{status:'unfilled'}};
   writeFileSync(`${root}/${path}`,JSON.stringify({...identity,blocks:[{text:body}]}));
   rows.push(`${path}\0${JSON.stringify(identity)}`);
  }
  git('add','.');git('commit','-qm','synthetic large package');
  const result=fingerprint(root);
  assert.equal(result.public,digest(rows.sort().join('\0')));
  for(const id of MISSIONS) assert.equal(result[id],digest(''));
  const path=`${directory}/career-0.json`, value=JSON.parse(readFileSync(`${root}/${path}`,'utf8'));
  value.authoring_structure.status='mapped';
  writeFileSync(`${root}/${path}`,JSON.stringify(value));git('add','.');git('commit','-qm','change internal authority');
  assert.notEqual(fingerprint(root).public,result.public);
 } finally {rmSync(root,{recursive:true,force:true});}
});
