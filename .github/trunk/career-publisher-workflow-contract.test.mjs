import { spawnSync } from "node:child_process";
import assert from "node:assert/strict";
import { readFileSync, writeFileSync, mkdtempSync, mkdirSync, symlinkSync, existsSync, rmSync } from "node:fs";
import { join, resolve } from "node:path";
import { tmpdir } from "node:os";
import test from "node:test";

const ci = readFileSync(new URL("../workflows/ci.yml", import.meta.url), "utf8");
const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
const deployer = readFileSync(new URL("../../deploy.php", import.meta.url), "utf8");
const publisher = readFileSync(
  new URL("../../backend/scripts/operations/career_current_authority_publish.php", import.meta.url),
  "utf8",
);

test("CI cannot emit a green exact-SHA receipt when required Career package scan is absent", () => {
  assert.match(ci, /career-publisher-parity:/);
  assert.match(ci, /career\.current_authority_package_scan\.v1/);
  assert.match(ci, /CAREER_PARITY_RESULT/);
  assert.match(ci, /publisher:\{required:\$classification\[0\]\.operations\.publisher_required/);
  assert.match(ci, /\$required and \.status == "pass"/);
  assert.match(ci, /publisher:\{required:/);
});

test("deploy consumes the CI decision and leaves staging on its public cohort", () => {
  assert.match(deploy, /career_current="\$\(jq -r '\.publisher\.required == true'/);
  assert.doesNotMatch(deploy, /startsWith\("backend\/content_assets\/career\/current\/"\)/);
  assert.doesNotMatch(deploy, /Run staging zero-write 2092-page Career parity/);
  assert.match(deploy, /career_current_parity_required/);
  assert.match(deployer, /career:current-authority-production-preactivation-parity/);
  assert.match(deployer, /after\('artisan:config:cache', 'seo:competitive-evidence-preactivation'\)/);
  assert.doesNotMatch(deployer, /seo:competitive-measurement-refresh/);
  assert.match(deployer, /after\('seo:competitive-evidence-preactivation', 'career:current-authority-production-preactivation-parity'\)/);
  assert.match(deployer, /after\('career:current-authority-production-preactivation-parity', 'guard:sitemap-authority'\)/);
});

test("Career data recovery is classifier-bound while recommendation publication requires production authority", () => {
  assert.match(ci, /career_data_recovery=\$\(jq -r \.operations\.career_data_recovery/);
  assert.match(deploy, /career_data_recovery="\$\(jq -r '\.classification\.operations\.career_data_recovery == true'/);
  assert.equal((deploy.match(/-o career_data_recovery=/g) ?? []).length, 2);
  assert.match(deployer, /task\('career:recover-data'/);
  assert.match(deployer, /career:recover-guide-locale-corruption --execute --json/);
  assert.match(deployer, /career:compile-recommendation-subjects --no-interaction/);
  assert.match(deployer, /set\('career_recommendation_publish_required', true\)/);
  assert.match(deployer, /set\('career_recommendation_publish_required', false\)/);
  assert.match(deployer, /if \(\(bool\) get\('career_recommendation_publish_required'\)\)/);
  assert.match(deployer, /task\('healthcheck:career-data-recovery'/);
  assert.match(deployer, /after\('healthcheck:public-dns', 'healthcheck:career-data-recovery'\)/);
});

test("production publisher is bound to the preactivation receipt digest", () => {
  assert.match(deploy, /needs\.policy\.outputs\.career_current_release == 'true'/);
  assert.match(deploy, /CAREER_CURRENT_PUBLISH_PRODUCTION_PARITY_RECEIPT_DIGEST/);
  assert.doesNotMatch(deploy, /needs\.production\.outputs\.career_parity_digest/);
  assert.match(deploy, /Read production preactivation Career parity receipt/);
  assert.match(deploy, /production_parity_receipt="career-current-authority-production-preactivation-parity\.json"/);
  assert.match(deploy, /PRODUCTION_PARITY_RECEIPT_DIGEST="\$\(jq -r \.receipt_digest "\$production_parity_receipt"\)"/);
  assert.ok(deploy.includes("(.validation_scope.canonical_slugs | unique | length) == 1046"));
  assert.match(deploy, /\.validation_scope\.locale_page_count == 2092/);
  assert.match(deploy, /\.parity\.production_preactivation_receipt_digest == env\.PRODUCTION_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /CAREER_CURRENT_PUBLISH_PRODUCTION_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /validation_scope\.canonical_slugs/);
  assert.doesNotMatch(publisher, /CAREER_CURRENT_PUBLISH_STAGING_PARITY_RECEIPT_DIGEST/);
  assert.match(publisher, /CareerCurrentAuthorityParity::CONTRACT_VERSION/);
  assert.match(publisher, /CareerJobDetailCanonicalCacheReader::compilerDigest\(\)/);
  assert.match(publisher, /CareerJobDetailCanonicalCacheReader::codecDigest\(\)/);
});


test("Career publisher is part of production success and respects the publication decision", () => {
  const production = deploy.split("  production:")[1].split("  mbti-zh-result-publish:")[0];
  const expression = production.match(/- id: career-publish\n[^\n]*\n\s+if: ([^\n]+)/)?.[1];
  assert.ok(expression);
  assert.match(production, /Restore exact LKG after Career publisher failure/);
  for (const [skipped, boundary, authorized, expected] of [
    ["false", "true", "false", false],
    ["false", "true", "true", true],
    ["true", "true", "true", false],
    ["false", "false", "true", false],
  ]) {
    const resolved = expression
      .replaceAll("steps.baseline.outputs.skip", JSON.stringify(skipped))
      .replaceAll("needs.policy.outputs.career_current_release", JSON.stringify(boundary))
      .replaceAll("needs.policy.outputs.career_current", JSON.stringify(authorized));
    assert.equal(Function(`return (${resolved})`)(), expected);
  }
});

test("the actual production receipt predicate rejects sampled and incomplete inventories", () => {
  const block = deploy.split("- id: career-parity")[1].split("- name: Check fixed production")[0];
  const start = block.indexOf(".contract_version ==");
  const end = block.indexOf("' \"$receipt\"", start);
  assert.ok(start >= 0 && end > start);
  const predicate = block.slice(start, end).trim();
  const args = ["-e"];
  const values = { sha: "a".repeat(40), active: "b".repeat(40), package: "c".repeat(64), projection: "d".repeat(64), compiler: "e".repeat(64), codec: "f".repeat(64) };
  for (const [key, value] of Object.entries(values)) args.push("--arg", key, value);
  const receipt = {
    contract_version: "career.current_authority_parity.v2", status: "pass", mode: "production-preactivation",
    release_sha: values.sha, active_sha: values.active,
    package: { digest: values.package, projection_digest: values.projection, compiler_digest: values.compiler, codec_digest: values.codec },
    validation_scope: { canonical_slugs: Array.from({ length: 1046 }, (_, i) => `role-${i}`), slug_count: 1046, locales: ["en", "zh-CN"], locale_page_count: 2092 },
    full_scan: { delivery_mode: "file_authoritative", counts: {locale_pages: 2092, verified_files: 2092} },
    redis: { mode: "readonly" }, write_counts: { database_write_count: 0, cache_write_count: 0 }, receipt_digest: "a".repeat(64),
  };
  const accepted = value => spawnSync("jq", [...args, predicate], { input: JSON.stringify(value), encoding: "utf8" }).status === 0;
  assert.equal(accepted(receipt), true);
  for (const mutate of [
    r => { r.validation_scope.canonical_slugs = ["accountants-and-auditors"]; r.validation_scope.locale_page_count = 2; },
    r => { r.validation_scope.canonical_slugs[1045] = r.validation_scope.canonical_slugs[0]; },
    r => { r.full_scan.counts.verified_files = 2091; },
    r => { r.full_scan.delivery_mode = "cache_only"; },
    r => { r.write_counts.cache_write_count = 1; },
  ]) { const invalid = structuredClone(receipt); mutate(invalid); assert.equal(accepted(invalid), false); }
});

test("file cache publication stays classifier-bound and separate from private result authority", () => {
  assert.ok(deploy.includes('career_current="$(jq -r \'.publisher.required == true\' "$receipt")"'));
  assert.doesNotMatch(deploy, /career_current=false|career_current_full_scan=false/);
  assert.match(deploy, /if \.classification\.operations\.enneagram_private_result_authority_release == true\s+then true\s+elif \.classification\.operations\.seo_council_orchestration == true\s+then false\s+else true end/);
  assert.match(deploy, /needs\.policy\.outputs\.career_current_release == 'true'/);
  assert.match(deploy, /needs\.policy\.outputs\.career_current == 'true'/);
});

// Exercise the shipped shell, substituting only external transports/Deployer
// and a clock. No production command or mutation is used by these simulations.
function productionStep(marker) {
  const start = deploy.indexOf(`      - ${marker}` , deploy.indexOf('  production:\n'));
  assert.ok(start >= 0, marker);
  const end = deploy.indexOf('\n      - ', start + 1);
  const block = deploy.slice(start, end < 0 ? undefined : end);
  return {block, script:block.split('        run: |\n')[1]?.split('\n').map(line=>line.replace(/^ {10}/,'')).join('\n')};
}

const candidateSha='a'.repeat(40), lkgSha='b'.repeat(40);
const hashes={assets:'c'.repeat(64),manifest:'d'.repeat(64),projection:'e'.repeat(64),operation:'f'.repeat(64),
  ci:'1'.repeat(64),compiler:'2'.repeat(64),codec:'3'.repeat(64),parity:'4'.repeat(64)};
function resolvedShell(script) {
  return script.replace(/\$\{\{\s*([^}]+?)\s*\}\}/g,(_,expression)=>{
    if(expression==='steps.baseline.outputs.active') return lkgSha;
    if(expression==='needs.policy.outputs.operations') return Buffer.from(JSON.stringify({career_cache:true})).toString('base64');
    for(const [suffix,key] of [['assets_sha256','assets'],['projection_sha256','projection'],['parity_compiler_digest','compiler'],['parity_codec_digest','codec']]) {
      if(expression.endsWith('career_current_'+suffix)||expression.endsWith('career_'+suffix)) return hashes[key];
    }
    if(expression==='needs.policy.outputs.career_current'||expression.endsWith('.operations.career_cache')) return 'true';
    return 'false';
  });
}
function simulation() {
  const root=mkdtempSync(join(tmpdir(),'career-deadline-'));
  mkdirSync(join(root,'bin'));
  mkdirSync(join(root,'backend/scripts/operations'),{recursive:true});
  symlinkSync(resolve('backend/scripts/operations/career_current_authority_publish.php'),join(root,'backend/scripts/operations/career_current_authority_publish.php'));
  symlinkSync(resolve('.github'),join(root,'.github'),'dir');
  const binary=(name,body)=>writeFileSync(join(root,'bin',name),'#!/bin/bash\nset -euo pipefail\n'+body,{mode:0o755});
  writeFileSync(join(root,'clock'),'100\n');
  writeFileSync(join(root,'current'),lkgSha);
  const realTimeout=spawnSync('which',['timeout'],{encoding:'utf8'}).stdout.trim();
  assert.ok(realTimeout);
  binary('date','cat "$SIM_ROOT/clock"\n');
  binary('node',`case "\${1:-}" in .github/trunk/*) source='${resolve('.')}/'"$1"; shift; exec '${process.execPath}' "$source" "$@";; esac
    exec '${process.execPath}' "$@"\n`);

  binary('timeout',`args=(); for arg in "$@"; do
    case "$arg" in --kill-after=*) arg=--kill-after=0.1s;; 3600s|30m) arg=1s;; esac
    args+=("$arg")
  done
  exec '${realTimeout}' "\${args[@]}"\n`);
  binary('php',`case " $* " in
    *' deploy:code-only '*) echo restore >> "$SIM_ROOT/calls"; printf '%s' '${lkgSha}' > "$SIM_ROOT/current"; exit "\${ROLLBACK_RC:-0}";;
    *' fap:deploy-unlock-owned '*) echo unlock >> "$SIM_ROOT/calls";;
    *) echo deploy >> "$SIM_ROOT/calls"; if [ "\${PREACTIVATION:-false}" != true ]; then printf '%s' '${candidateSha}' > "$SIM_ROOT/current"; fi; exit "\${DEPLOY_RC:-0}";;
  esac\n`);
  binary('ssh',`echo ssh >> "$SIM_ROOT/calls"
    case "\${SSH_MODE:-revision}" in
      hang) sleep 10;;
      parity|publisher) cat "$SIM_ROOT/\${SSH_MODE}.json";;
      *) case "$*" in *REVISION*) cat "$SIM_ROOT/current";; *) exit 1;; esac;;
    esac\n`);
  const env={...process.env,PATH:join(root,'bin')+':'+process.env.PATH,SIM_ROOT:root,RUNNER_TEMP:root,
    GITHUB_ENV:join(root,'github-env'),GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',PRODUCTION_JOB_STARTED_EPOCH:'100',
    PRODUCTION_FORWARD_DEADLINE:'3700',DEPLOY_SHA:candidateSha,DEPLOY_PATH:'/managed',DEPLOY_PORT:'22',
    DEPLOY_USER:'test',DEPLOY_HOST:'test',ASSETS_SHA256:hashes.assets,SOURCE_MERGE_SHA:lkgSha,
    MANIFEST_SHA256:hashes.manifest,VERSIONLESS_PROJECTION_SHA256:hashes.projection,OPERATION_KEY:hashes.operation,
    CI_PACKAGE_SCAN_RECEIPT_DIGEST:hashes.ci,PARITY_COMPILER_DIGEST:hashes.compiler,PARITY_CODEC_DIGEST:hashes.codec,
    FULL_SCAN:'1',CAREER_CONTENT_PACKAGE:'false'};
  const parity={contract_version:'career.current_authority_parity.v2',status:'pass',mode:'production-preactivation',
    release_sha:candidateSha,active_sha:lkgSha,package:{digest:hashes.assets,projection_digest:hashes.projection,
      compiler_digest:hashes.compiler,codec_digest:hashes.codec},
    validation_scope:{canonical_slugs:Array.from({length:1046},(_,i)=>`role-${i}`),slug_count:1046,locales:['en','zh-CN'],locale_page_count:2092},
    full_scan:{counts:{locale_pages:2092,verified_files:2092},delivery_mode:'file_authoritative'},redis:{mode:'readonly'},
    write_counts:{database_write_count:0,cache_write_count:0},receipt_digest:hashes.parity};
  const publisher={contract_version:'career.current_authority_publish.v1',status:'PASS_CURRENT_AUTHORITY_PUBLISH',
    release_sha:candidateSha,source_merge_sha:lkgSha,manifest_sha256:hashes.manifest,assets_sha256:hashes.assets,
    versionless_projection_sha256:hashes.projection,operation_key:hashes.operation,
    package:{versionless_projection_sha256:hashes.projection,career_count:1046,locale_page_count:2092,file_count:2092},
    authority:{changed_locale_page_count:0,target_count:1046,unique_slug_count:1046,verified_identity_count:1046},
    changed_page_set_sha256:null,parity:{ci_package_scan_receipt_digest:hashes.ci,production_preactivation_receipt_digest:hashes.parity,
      compiler_digest:hashes.compiler,codec_digest:hashes.codec,content_package_sha256:null,content_package_receipt_digest:null},
    full_scan:true,resource_guard:{schema_version:'career.current_authority_publish.resource_guard.v1',timeout_seconds:900,
      nice_adjustment:15,ionice_class:2,ionice_priority:7,memory_limit_mb:1024},manual_hold_verified:true,
    file_readback:{delivery_mode:'file_authoritative',file_hash_match_count:2092,verified_slug_count:1046,verified_locale_page_count:2092},
    write_counts:{cache_candidate_write_count:0,occupation_write_count:0,generation_write_count:0,discoverability_write_count:0,search_submission_count:0},
    negative_guarantees:{task_3a_through_7b_executed:false,search_channel_executed:false},automatic_retry_allowed:false};
  writeFileSync(join(root,'parity.json'),JSON.stringify(parity));
  writeFileSync(join(root,'publisher.json'),JSON.stringify(publisher));
  const run=(script,extra={})=>spawnSync('bash',['-euo','pipefail','-c',resolvedShell(script)],{cwd:root,env:{...env,...extra},encoding:'utf8',timeout:5000});
  const calls=()=>existsSync(join(root,'calls'))?readFileSync(join(root,'calls'),'utf8').trim().split('\n'):[];
  return {root,env,run,calls,parity,publisher,close:()=>rmSync(root,{recursive:true,force:true})};
}

function activationScript() {
  const script=productionStep('name: Deploy once and automatically restore LKG after committed smoke failure').script;
  return 'competitive_evidence=false; production_council_closeout=false; remote_env=""; remote_writer_env=""; cleanup() { :; };\n'
    + script.slice(script.indexOf('release="trunk-'));
}
function lateRecoveryAllowed(activation,failedStep) {
  const condition=productionStep('name: Restore exact LKG after Career publisher failure').block.match(/\n        if: ([^\n]+)/)[1];
  return Function('return ('+condition.replaceAll('failure()','true').replace(/steps\.([\w-]+)\.outcome/g,(_,id)=>JSON.stringify(id==='activation'?activation:id===failedStep?'failure':'skipped'))+')')();
}

test('actual activation shell restores once after committed failure and never on preactivation failure',()=>{
  for(const [preactivation,rollbackRc] of [[true,'0'],[false,'0'],[false,'1']]) {
    const sim=simulation();
    try {
      const result=sim.run(activationScript(),{PREACTIVATION:String(preactivation),DEPLOY_RC:'1',ROLLBACK_RC:rollbackRc});
      assert.notEqual(result.status,0,result.stderr);
      assert.equal(sim.calls().filter(x=>x==='restore').length,preactivation?0:1,JSON.stringify({stderr:result.stderr,stdout:result.stdout,calls:sim.calls()}));
      assert.equal(lateRecoveryAllowed('failure','career-publish'),false);
      if(!preactivation) {
        const receipt=JSON.parse(readFileSync(join(sim.root,'lkg-rollback-receipt.json'),'utf8'));
        assert.equal(receipt.rollback.command_succeeded,rollbackRc==='0');
        assert.equal(receipt.rollback.restoration_completed,true);
      }
    } finally {sim.close();}
  }
});

test('actual forward readers share their deadline and reject malformed/incomplete installed authority',()=>{
  const parity=productionStep('id: career-parity').script, publisher=productionStep('id: career-publish').script;
  for(const failure of ['none','sha','missing-file','inventory','publisher-exit']) {
    const sim=simulation();
    try {
      if(failure==='sha')sim.parity.release_sha=lkgSha;
      if(failure==='inventory')sim.publisher.file_readback.file_hash_match_count=2091;
      writeFileSync(join(sim.root,'parity.json'),JSON.stringify(sim.parity));
      writeFileSync(join(sim.root,'publisher.json'),JSON.stringify(sim.publisher));
      if(failure==='missing-file')rmSync(join(sim.root,'parity.json'));
      const first=sim.run(parity,{SSH_MODE:'parity'});
      if(['sha','missing-file'].includes(failure)) {assert.notEqual(first.status,0);continue;}
      assert.equal(first.status,0,first.stderr);
      const second=sim.run(publisher,{SSH_MODE:failure==='publisher-exit'?'revision':'publisher'});
      assert.equal(second.status===0,failure==='none',second.stderr);
      if(failure==='none') {
        writeFileSync(join(sim.root,'clock'),'3700\n');
        const count=sim.calls().length;
        assert.equal(sim.run(publisher,{SSH_MODE:'publisher'}).status,124);
        assert.equal(sim.calls().length,count,'expired reader must not contact server');
      }
    } finally {sim.close();}
  }
});

test('hung or late failing forward readers retain the separate exact-LKG path',()=>{
  for(const step of ['career-parity','career-publish','production-state','production-evidence']) {
    assert.equal(lateRecoveryAllowed('success',step),true,step);
    assert.equal(lateRecoveryAllowed('failure',step),false,step);
  }
  for(const marker of ['id: career-parity','id: career-publish']) {
    const sim=simulation();
    try {
      if(marker.endsWith('career-publish'))writeFileSync(join(sim.root,'career-current-authority-production-preactivation-parity.json'),JSON.stringify(sim.parity));
      const before=Date.now();
      const result=sim.run(productionStep(marker).script,{PRODUCTION_FORWARD_DEADLINE:'101',SSH_MODE:'hang'});
      assert.equal(result.status,124,result.stderr);
      assert.ok(Date.now()-before<3000);
      writeFileSync(join(sim.root,'current'),candidateSha);
      const restored=sim.run(productionStep('name: Restore exact LKG after Career publisher failure').script);
      assert.equal(restored.status,0,restored.stderr);
      assert.equal(sim.calls().filter(x=>x==='restore').length,1);
    } finally {sim.close();}
  }
});

test('every required reader is bounded and state acceptance precedes outside business publishers',()=>{
  for(const marker of ['id: career-parity','id: career-publish','name: Read production competitive evidence receipt',
    'name: Read production SEO Evidence closeout receipt','name: Read production Policy Gateway closeout receipt',
    'name: Read production SEO Council closeout receipt','name: Verify production A08 pause and read-only Operations preservation']) {
    const script=productionStep(marker).script;
    assert.match(script,/PRODUCTION_FORWARD_DEADLINE - \$\(date/);
    assert.match(script,/timeout --signal=TERM --kill-after=30s "\$\{forward_remaining\}s" bash -euo pipefail/);
    assert.match(script,/exit 124/);
  }
  assert.ok(deploy.indexOf('id: production-state')<deploy.indexOf('id: eq-source-publish',deploy.indexOf('  production:\n')));
  assert.match(productionStep('name: Upload Career Current publish receipt').block,/timeout-minutes: 2/);
  assert.match(productionStep('name: Persist push-to-production timing receipt').block,/timeout-minutes: 1/);
  assert.match(productionStep('name: Restore exact LKG after Career publisher failure').block,/timeout-minutes: 36/);
  // 36m bounds the 30m+30s restore plus <=5m30s helpers. Together with
  // the 5m artifact/timing steps this remains inside the reserved 15m tail.
  assert.ok((36*60-(1800+30))+5*60 <= 900);
  assert.match(readFileSync(new URL('../../backend/scripts/deploy/run_career_current_authority_publisher.sh',import.meta.url),'utf8'),/readonly timeout_seconds=900/);
});

test('actual state acceptance rejects drift and routes it to the existing late restoration',()=>{
  for(const drift of [false,true]) {
    const sim=simulation();
    try {
      mkdirSync(join(sim.root,'backend/scripts/deploy'),{recursive:true});
      writeFileSync(join(sim.root,'backend/scripts/deploy/seo_a08_transport.sh'),'cat "$SIM_ROOT/state.json" > "$2"\n');
      const before={sha:lkgSha,paused:true,generation:4,selected_missions:[]};
      writeFileSync(join(sim.root,'a08-production-before.json'),JSON.stringify(before));
      writeFileSync(join(sim.root,'state.json'),JSON.stringify({...before,sha:candidateSha,paused:!drift,
        gate_only:false,business_guards_closed:true,operations_readonly:true}));
      const result=sim.run(productionStep('name: Verify production A08 pause and read-only Operations preservation').script);
      assert.equal(result.status===0,!drift,result.stderr);
      if(drift) {
        assert.equal(lateRecoveryAllowed('success','production-state'),true);
        writeFileSync(join(sim.root,'current'),candidateSha);
        assert.equal(sim.run(productionStep('name: Restore exact LKG after Career publisher failure').script).status,0);
        assert.equal(sim.calls().filter(x=>x==='restore').length,1);
      }
    } finally {sim.close();}
  }
});


test('actual ordinary deployment task bodies cannot call full maintenance transports', () => {
  const names = ['career:repair-published-detail-cache-coverage','guard:career-detail-cache-coverage',
    'guard:career-runtime-projection-authority','career:prune-public-cache-versions',
    'career:warm-public-authority-cache','career:rebuild-directory-after-detail-repair',
    'guard:career-discoverability-pre-sitemap','guard:career-discoverability-post-sitemap',
    'seo:url-truth-reconciliation-receipt','seo:url-truth-controlled-reconcile',
    'seo:warm-sitemap-source-cache','seo:refresh-sitemap-source-before-activation',
    'guard:public-content-release','healthcheck:sitemap-source'];
  for (const name of names) {
    const task=deployer.split(`task('${name}', function () {`)[1]?.split('\n});')[0];
    assert.ok(task, name);
    const run=spawnSync('php',['-r',`namespace Deployer;
function deployMode(){return 'standard';}
function writeln($message){}
function run(...$args){throw new \\RuntimeException('FULL_MAINTENANCE_CALLED');}
$task=function(){${task}};$task();echo 'focused';`],{encoding:'utf8'});
    assert.equal(run.status,0,`${name}: ${run.stderr}`);
    assert.equal(run.stdout,'focused',name);
  }
});

test('ordinary Career reader smoke executes and propagates transport failures', () => {
  const task=deployer.split("task('healthcheck:career-content-only', function () {")[1].split('\n});')[0];
  for(const environment of ['staging','production'])for(const failAt of [0,1,2]){
    const run=spawnSync('php',['-r',`namespace Deployer;
function deployUsesCareerContentPackage(){return false;}
function deployOperation($name){return $name==='career_cache';}
function currentHost(){return new class {function getAlias(){return '${environment}';}};}
function get($key,$default=null){return $key==='healthcheck_host'?'example.test':$default;}
function deploySafeHost($value,$label){return $value;}
function deployCurlResolveArg(...$args){return '';}
function deployHttpsUrlArg($host,$path){return $path;}
function deployShellArg($value){return $value;}
function run($command){static $calls=0;if(++$calls===${failAt})throw new \\RuntimeException('READER_SMOKE_FAILED');echo $command."\\n";}
$task=function(){${task}};$task();`],{encoding:'utf8',env:{...process.env,PUBLIC_WEB_BASE_URL_STG:''}});
    if(failAt){assert.notEqual(run.status,0);assert.match(run.stderr,/READER_SMOKE_FAILED/);}
    else {assert.equal(run.status,0,run.stderr);assert.equal(run.stdout.trim().split('\n').length,2);
      assert.ok(run.stdout.includes(`https://${environment==='staging'?'staging.':''}fermatmind.com/zh/career/jobs/accountants-and-auditors`));}
  }
  assert.doesNotMatch(deployer,/CAREER_PARITY_COMPACT_PAGE_CACHE=1/);
});
