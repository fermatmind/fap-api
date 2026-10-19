import assert from 'node:assert/strict';
import {mkdtempSync,writeFileSync,readFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {spawnSync} from 'node:child_process';
import test from 'node:test';

const root=resolve(import.meta.dirname,'../..');
const helper=join(root,'backend/scripts/ci/composer_install.sh');
function runFixture(scenario,options=[]) {
 const directory=mkdtempSync(join(tmpdir(),'fm-composer-test-'));
 try {
  writeFileSync(join(directory,'composer'),`#!/usr/bin/env bash
set -eu
printf '%s|%s\\n' "$COMPOSER_MAX_PARALLEL_HTTP" "$*" >> "$FIXTURE_DIR/calls"
if [[ " $* " == *" --download-only "* ]]; then
 count=0; if [ -f "$FIXTURE_DIR/count" ]; then count=$(cat "$FIXTURE_DIR/count"); fi
 count=$((count+1)); printf '%s' "$count" > "$FIXTURE_DIR/count"
 case "$SCENARIO" in
  recover) if [ "$count" -gt 1 ]; then exit 0; fi ;;
  success|install_failure) exit 0 ;;
  forbidden) echo 'Failed to download private/package from dist: (HTTP/2 403 )'; exit 100 ;;
  dependency) echo 'Your lock file does not contain a compatible set of packages'; exit 2 ;;
  mixed) echo 'Failed to download other/package from dist: (HTTP/2 503 )' ;;
  unknown) echo 'RuntimeException: unexpected filesystem error' ;;
  original_log) cat "$FIXTURE_DIR/original-composer-download.txt" ;;
  wrong_status) echo 'Failed to download mpdf/mpdf from dist: (HTTP/2 429 )'; exit 1 ;;
 esac
 echo 'Failed to download mpdf/mpdf from dist: file could not be downloaded (HTTP/2 429 )'
 echo 'Source fallback is disabled. Not trying alternative sources.'
 exit 100
fi
if [ "$SCENARIO" = install_failure ]; then echo 'script failure'; exit 17; fi
`,{mode:0o755});
  writeFileSync(join(directory,'sleep'),'#!/usr/bin/env bash\nprintf "%s\\n" "$1" >> "$FIXTURE_DIR/delays"\n',{mode:0o755});
  if(scenario==='original_log') writeFileSync(join(directory,'original-composer-download.txt'),readFileSync(join(root,'.github/trunk/fixtures/composer-download-http429.txt'),'utf8'));
  const result=spawnSync('bash',[helper,join(directory,'composer'),...options],{encoding:'utf8',env:{...process.env,PATH:`${directory}:${process.env.PATH}`,FIXTURE_DIR:directory,SCENARIO:scenario}});
  const calls=readFileSync(join(directory,'calls'),'utf8').trim().split('\n');
  let delays=[]; try {delays=readFileSync(join(directory,'delays'),'utf8').trim().split('\n');}catch {}
  return {...result,calls,delays};
 } finally {rmSync(directory,{recursive:true,force:true});}
}
test('429 download recovery preserves caller arguments and installs exactly once',()=>{
 const result=runFixture('recover',['--no-interaction','--prefer-dist','--no-dev','--optimize-autoloader']);
 assert.equal(result.status,0,result.stderr); assert.equal(result.calls.length,3); assert.deepEqual(result.delays,['20']);
 assert.ok(result.calls.every(line=>line.startsWith('3|install --no-interaction --prefer-dist --no-dev --optimize-autoloader')));
 assert.equal(result.calls.filter(line=>!line.includes('--download-only')).length,1);
 assert.ok(result.calls.slice(0,2).every(line=>line.endsWith('--download-only --no-scripts')));
 assert.ok(!result.calls[2].includes('--no-scripts'));
});
test('persistent 429 stops after three downloads and never installs',()=>{
 const result=runFixture('limited'); assert.equal(result.status,100); assert.equal(result.calls.length,3); assert.deepEqual(result.delays,['20','60']);
 assert.ok(result.calls.every(line=>line.includes('--download-only')));
});
test('the original download output with error-handler and exception diagnostics still retries',()=>{
 const result=runFixture('original_log'); assert.equal(result.status,100); assert.equal(result.calls.length,3); assert.deepEqual(result.delays,['20','60']);
});
for(const [scenario,status] of [['forbidden',100],['dependency',2],['mixed',100],['unknown',100],['wrong_status',1]]) test(`${scenario} is not retried`,()=>{
 const result=runFixture(scenario); assert.equal(result.status,status); assert.equal(result.calls.length,1); assert.deepEqual(result.delays,[]);
});
test('installation failure remains a failure and is never retried',()=>{
 const result=runFixture('install_failure'); assert.equal(result.status,17); assert.equal(result.calls.length,2); assert.deepEqual(result.delays,[]);
});
test('normal success has one download and one installation',()=>{
 const result=runFixture('success'); assert.equal(result.status,0); assert.equal(result.calls.length,2); assert.deepEqual(result.delays,[]);
});
test('all twelve workflow and server installation callers use the shared helper',()=>{
 let total=0;
 for(const file of ['.github/workflows/ci.yml','.github/workflows/nightly.yml','.github/workflows/deploy.yml']) {
  const text=readFileSync(join(root,file),'utf8');
  assert.ok(!/\bcomposer install\b/.test(text),file);
  total+=(text.match(/bash scripts\/ci\/composer_install\.sh composer /g)||[]).length;
 }
 const deploy=readFileSync(join(root,'deploy.php'),'utf8');
 assert.match(deploy,/bash scripts\/ci\/composer_install\.sh \{\{bin\/composer\}\} --no-interaction --prefer-dist --optimize-autoloader --no-dev/);
 assert.doesNotMatch(deploy,/\{\{bin\/composer\}\} install/);
 assert.equal(total+1,12);
 assert.ok(!/--ignore-platform|--no-blocking|--no-security-blocking/.test(readFileSync(helper,'utf8')));
});
