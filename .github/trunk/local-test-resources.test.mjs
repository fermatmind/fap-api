import assert from 'node:assert/strict';
import test from 'node:test';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtempSync,mkdirSync,writeFileSync,readFileSync,rmSync,existsSync,symlinkSync,cpSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {createServer} from 'node:net';
import {classifyPaths} from './classify-paths.mjs';
const root=resolve('.');
const helper=join(root,'backend/scripts/ci/test_resources.sh');
const shell=(code,env={})=>spawnSync('bash',['-ceu',code],{encoding:'utf8',env:{...process.env,GITHUB_ACTIONS:'false',...env}});
const run=(code,env={})=>new Promise((resolve,reject)=>{
 const p=spawn('bash',['-ceu',code],{env:{...process.env,GITHUB_ACTIONS:'false',...env}});let stdout='',stderr='';
 const timer=setTimeout(()=>p.kill('SIGTERM'),170000);p.once('close',()=>clearTimeout(timer));
 p.stdout.on('data',b=>stdout+=b);p.stderr.on('data',b=>stderr+=b);p.on('error',reject);p.on('close',status=>resolve({status,stdout,stderr}));
});
test('two concurrent real SQLite contexts isolate data, config, file cache and cleanup',async()=>{
 const barrier=mkdtempSync(join(tmpdir(),'fm-isolation-proof-'));
 try {
 const code=`source '${helper}'; fm_test_init; trap fm_test_cleanup EXIT
 export DB_DATABASE="$FM_TEST_RESOURCE_ROOT/test.sqlite"
 php -r '$d=new PDO("sqlite:".getenv("DB_DATABASE"));$d->exec("CREATE TABLE proof(value TEXT)");$d->prepare("INSERT INTO proof VALUES (?)")->execute([getenv("PROOF_VALUE")]);file_put_contents(getenv("LARAVEL_STORAGE_PATH")."/framework/cache/data/proof",getenv("PROOF_VALUE"));'
 printf '%s' "$FM_TEST_RESOURCE_ROOT" > "$PROOF_DIR/$PROOF_VALUE"
 for n in {1..200}; do [[ ! -f "$PROOF_DIR/a" || ! -f "$PROOF_DIR/b" ]] || break; sleep .05; done
 [[ -f "$PROOF_DIR/a" && -f "$PROOF_DIR/b" ]]
 if [[ "$PROOF_VALUE" == b ]]; then for n in {1..200}; do [[ -d "$(cat "$PROOF_DIR/a")" ]] || break; sleep .05; done
 [[ ! -d "$(cat "$PROOF_DIR/a")" ]]; fi
 php -r '$d=new PDO("sqlite:".getenv("DB_DATABASE"));if($d->query("SELECT value FROM proof")->fetchColumn()!==getenv("PROOF_VALUE"))exit(4);if(file_get_contents(getenv("LARAVEL_STORAGE_PATH")."/framework/cache/data/proof")!==getenv("PROOF_VALUE"))exit(5);'
 printf '%s\\n' "$FM_TEST_RESOURCE_ROOT|$APP_CONFIG_CACHE"`;
 const [a,b]=await Promise.all(['a','b'].map(PROOF_VALUE=>run(code,{PROOF_VALUE,PROOF_DIR:barrier})));
 for(const r of [a,b])assert.equal(r.status,0,r.stderr);
 assert.notEqual(a.stdout,b.stdout);
 for(const n of ['a','b'])assert.equal(existsSync(readFileSync(join(barrier,n),'utf8')),false);
 } finally {rmSync(barrier,{recursive:true,force:true});}
});
test('occupied port is rejected without terminating its listener',async()=>{
 const server=createServer();await new Promise(r=>server.listen(0,'127.0.0.1',r));
 try {const p=server.address().port;const r=shell(`source '${helper}'; fm_test_require_free_port '${p}'`);assert.equal(r.status,75,r.stderr);assert.equal(server.listening,true);const foreign=shell(`source '${helper}'; fm_test_port_owned '${p}' 99999999`);assert.equal(foreign.status,1);}
 finally{await new Promise(r=>server.close(r));}
});
test('foreign resource root and external SQLite reset are rejected',()=>{
 const dir=mkdtempSync(join(tmpdir(),'fm-foreign-'));
 try {writeFileSync(join(dir,'owner'),'other');const r=shell(`source '${helper}'; fm_test_init`,{FM_TEST_RESOURCE_ROOT:dir,FM_TEST_RESOURCE_TOKEN:'wrong'});assert.equal(r.status,73);assert.equal(readFileSync(join(dir,'owner'),'utf8'),'other');
 const f=join(dir,'business.sqlite');writeFileSync(f,'keep');const p=shell(`bash '${root}/backend/scripts/ci/prepare_sqlite.sh'`,{DB_DATABASE:f});assert.equal(p.status,73,p.stderr);assert.equal(readFileSync(f,'utf8'),'keep');}
 finally{rmSync(dir,{recursive:true,force:true});}
});
test('SQLite reset rejects traversal and symlink parents without changing other data',()=>{
 const dir=mkdtempSync(join(tmpdir(),'fm-path-proof-'));const owned=join(dir,'owned');mkdirSync(owned);writeFileSync(join(owned,'owner'),'proof');
 const foreign=join(dir,'foreign');mkdirSync(foreign);writeFileSync(join(foreign,'db.sqlite'),'keep');symlinkSync(foreign,join(owned,'link'));
 try {for(const file of [owned+'/../foreign/db.sqlite',join(owned,'link/db.sqlite')]){
 const r=shell(`bash '${root}/backend/scripts/ci/prepare_sqlite.sh'`,{FM_TEST_RESOURCE_ROOT:owned,FM_TEST_RESOURCE_TOKEN:'proof',DB_DATABASE:file});assert.equal(r.status,73,r.stderr);assert.equal(readFileSync(join(foreign,'db.sqlite'),'utf8'),'keep');
 }} finally {rmSync(dir,{recursive:true,force:true});}
});
test('server cleanup refuses a mismatched identity and stops only its own child',()=>{
 const r=shell(`source '${helper}'; sleep 30 & pid=$!; identity="$(fm_test_process_identity "$pid")"; fm_test_stop_server "$pid" wrong; kill -0 "$pid"; fm_test_stop_server "$pid" "$identity"; wait "$pid" 2>/dev/null || true; ! kill -0 "$pid" 2>/dev/null`);assert.equal(r.status,0,r.stderr);
});

function mbtiFixture() {
 const dir=mkdtempSync(join(tmpdir(),'fm-mbti-entry-'));const scripts=join(dir,'backend/scripts');mkdirSync(join(scripts,'ci'),{recursive:true});mkdirSync(join(dir,'bin'));
 cpSync(join(root,'backend/scripts/ci_verify_mbti.sh'),join(scripts,'ci_verify_mbti.sh'));
 for(const f of ['test_resources.sh','prepare_sqlite.sh','require_tools.sh'])cpSync(join(root,'backend/scripts/ci',f),join(scripts,'ci',f));
 writeFileSync(join(dir,'backend/.env.example'),'fixture');
 const php=spawnSync('which',['php'],{encoding:'utf8'}).stdout.trim();
 writeFileSync(join(dir,'bin/php'),`#!/bin/bash
if [[ "$1" == -r && "$2" == *vendor/autoload.php* ]]; then exit 0; fi
if [[ "$1" == -r ]]; then exec '${php}' "$@"; fi
printf '%s|%s\n' "$FM_TEST_RESOURCE_ROOT" "$*" >> "$PROOF_LOG"
if [[ "$*" == *migrate* && "\${PROOF_MODE:-}" == fail ]]; then exit 42; fi
if [[ "$*" == *migrate* && "\${PROOF_MODE:-}" == term ]]; then sleep .5; fi
exit 0
`,{mode:0o755});
 writeFileSync(join(dir,'bin/composer'),'#!/bin/sh\nexit 0\n',{mode:0o755});
 return {dir,entry:join(scripts,'ci_verify_mbti.sh'),env:{PATH:join(dir,'bin')+':'+process.env.PATH,PROOF_LOG:join(dir,'calls')}};
}
test('actual MBTI local parity rejects fixed services before invoking artisan',()=>{
 const f=mbtiFixture();try {const r=shell(`bash '${f.entry}'`,{...f.env,CI_VERIFY_TOPOLOGY:'mysql8-redis6',CI_VERIFY_PARITY_ONLY:'1'});assert.equal(r.status,73,r.stderr);assert.equal(existsSync(join(f.dir,'calls')),false);assert.equal(existsSync(join(f.dir,'backend/.env')),false);}finally{rmSync(f.dir,{recursive:true,force:true});}
});
test('actual MBTI early migration failure cleans owned resources and preserves existing env',()=>{
 const f=mbtiFixture();try {writeFileSync(join(f.dir,'backend/.env'),'keep-existing');const r=shell(`bash '${f.entry}'`,{...f.env,PROOF_MODE:'fail'});assert.equal(r.status,42,r.stderr);const calls=readFileSync(join(f.dir,'calls'),'utf8');const resource=calls.split('|')[0];assert.ok(resource);assert.equal(existsSync(resource),false);assert.equal(readFileSync(join(f.dir,'backend/.env'),'utf8'),'keep-existing');assert.doesNotMatch(calls,/key:generate/);}finally{rmSync(f.dir,{recursive:true,force:true});}
});
test('actual MBTI CI parity early success cleans only its generated env',()=>{
 const f=mbtiFixture();try {const r=shell(`bash '${f.entry}'`,{...f.env,GITHUB_ACTIONS:'true',CI_VERIFY_TOPOLOGY:'mysql8-redis6',CI_VERIFY_PARITY_ONLY:'1',DB_CONNECTION:'mysql',DB_HOST:'127.0.0.1',DB_PORT:'3306',DB_DATABASE:'fap_ci',QUEUE_CONNECTION:'redis',REDIS_HOST:'127.0.0.1',REDIS_PORT:'6379'});assert.equal(r.status,0,r.stderr);assert.match(r.stdout,/parity gate OK/);assert.equal(existsSync(join(f.dir,'backend/.env')),false);}finally{rmSync(f.dir,{recursive:true,force:true});}
});
test('actual MBTI TERM preserves signal failure and cleans the local resource root',async()=>{
 const f=mbtiFixture();try {const p=spawn('bash',[f.entry],{env:{...process.env,GITHUB_ACTIONS:'false',...f.env,PROOF_MODE:'term'}});let stderr='';p.stderr.on('data',b=>stderr+=b);p.stdout.resume();const ended=new Promise(r=>p.on('close',status=>r(status)));
 const deadline=Date.now()+5000;while(!existsSync(join(f.dir,'calls'))&&Date.now()<deadline)await new Promise(r=>setTimeout(r,20));assert.ok(existsSync(join(f.dir,'calls')));const resource=readFileSync(join(f.dir,'calls'),'utf8').split('|')[0];p.kill('SIGTERM');assert.equal(await ended,143,stderr);assert.equal(existsSync(resource),false);
 }finally{rmSync(f.dir,{recursive:true,force:true});}
});
test('CI topology stays explicit and local resource initialization is a no-op there',()=>{
 const r=shell(`source '${helper}'; fm_test_init; printf '%s|%s|%s' "$DB_PORT" "$DB_DATABASE" "\${FM_TEST_RESOURCE_ROOT:-unset}"`,{GITHUB_ACTIONS:'true',DB_PORT:'3306',DB_DATABASE:'fap_ci'});assert.equal(r.status,0);assert.equal(r.stdout,'3306|fap_ci|unset');
});
test('foreign MySQL container fails before reuse or deletion',()=>{
 const dir=mkdtempSync(join(tmpdir(),'fm-docker-fixture-'));const log=join(dir,'calls');
 try {const php=spawnSync('which',['php'],{encoding:'utf8'}).stdout.trim();writeFileSync(join(dir,'php'),`#!/bin/sh\nexec '${php}' "$@"\n`,{mode:0o755});
 writeFileSync(join(dir,'docker'),`#!/bin/sh\nprintf '%s\\n' "$*" >> '${log}'\ncase "$1" in info) exit 0;; inspect) if [ "$2" = --format ]; then echo foreign-owner; fi; exit 0;; *) exit 91;; esac\n`,{mode:0o755});
 const r=shell(`bash '${root}/backend/scripts/ci/ensure_mysql.sh'`,{PATH:dir+':'+process.env.PATH,MYSQL_CONTAINER_NAME:'existing-foreign'});assert.equal(r.status,73,r.stderr);const calls=readFileSync(log,'utf8');assert.doesNotMatch(calls,/(^|\n)(rm|run|port) /);}
 finally{rmSync(dir,{recursive:true,force:true});}
});
test('actual MBTI entry uses isolated paths and refuses takeover; alias cleanup is ownership-bound',()=>{
 const s=readFileSync(join(root,'backend/scripts/ci_verify_mbti.sh'),'utf8');assert.match(s,/fm_test_init/);assert.match(s,/FM_TEST_RESOURCE_ROOT:-\/tmp/);assert.match(s,/fm_test_require_free_port "\$PORT"/);assert.match(s,/--tries=1/);assert.match(s,/ALIAS_CREATED:-0/);assert.doesNotMatch(s,/lsof[^\n]*kill|kill -9/);
});
test('worktree-local artifact path and explicit RUN_DIR remain compatible with existing consumers',()=>{
 const source=readFileSync(join(root,'backend/scripts/ci_verify_mbti.sh'),'utf8');const assignment=source.split('\n').find(l=>l.startsWith('RUN_DIR='));assert.ok(assignment);
 for(const [override,expected] of [['','/task/backend/artifacts/verify_mbti'],['/task/artifacts/run-a','/task/artifacts/run-a']]){
 const r=shell(`${assignment}; printf '%s' "$RUN_DIR"`,{BACKEND_DIR:'/task/backend',FM_TEST_RESOURCE_TOKEN:'unique-token',RUN_DIR:override});assert.equal(r.status,0,r.stderr);assert.equal(r.stdout,expected);
 }
 for(const consumer of ['accept_events_C.sh','accept_events_F_result_view_meta.sh'])assert.match(readFileSync(join(root,'backend/scripts',consumer),'utf8'),/\$BACKEND_DIR\/artifacts\/verify_mbti\/report\.json/);
});
test('validation-only scripts skip runtime but unknown and mixed runtime changes remain covered',()=>{
 const paths=['backend/scripts/ci/ensure_mysql.sh','backend/scripts/ci/prepare_sqlite.sh','backend/scripts/ci_verify_mbti.sh','backend/scripts/ci/test_resources.sh'];assert.equal(classifyPaths(paths).deploy,false);assert.equal(classifyPaths([...paths,'backend/app/Services/UnknownRuntime.php']).deploy,true);assert.equal(classifyPaths(['backend/scripts/ci/unknown.sh']).deploy,true);assert.equal(classifyPaths(['.github/trunk/classify-paths.mjs']).deploy,true);
});
test('two real MySQL contexts keep data separate and remove only their own containers', {skip:process.env.RUN_LOCAL_RESOURCE_MYSQL!=='1',timeout:180000},async()=>{
 const barrier=mkdtempSync(join(tmpdir(),'fm-mysql-proof-'));
 try {
 const php=join(barrier,'proof.php');writeFileSync(php,`<?php
 $db=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'));
 $db->exec('CREATE TABLE isolation_proof(value VARCHAR(10))');$db->prepare('INSERT INTO isolation_proof VALUES (?)')->execute([getenv('PROOF_VALUE')]);
 file_put_contents(getenv('PROOF_DIR').'/'.getenv('PROOF_VALUE'),json_encode(['port'=>getenv('DB_PORT'),'database'=>getenv('DB_DATABASE'),'container'=>getenv('MYSQL_CONTAINER_NAME')]));
 $deadline=time()+20;while((!file_exists(getenv('PROOF_DIR').'/a')||!file_exists(getenv('PROOF_DIR').'/b'))&&time()<$deadline)usleep(50000);
 if(!file_exists(getenv('PROOF_DIR').'/a')||!file_exists(getenv('PROOF_DIR').'/b'))exit(7);
 if(getenv('PROOF_VALUE')==='b'){usleep(2000000);}
 if($db->query('SELECT value FROM isolation_proof')->fetchColumn()!==getenv('PROOF_VALUE'))exit(8);
 if(getenv('PROOF_VALUE')==='a')exit(23);
 `);
 const results=await Promise.all(['a','b'].map(PROOF_VALUE=>run(`bash '${root}/backend/scripts/ci/ensure_mysql.sh' -- php '${php}'`,{PROOF_VALUE,PROOF_DIR:barrier})));
 assert.equal(results[0].status,23,results[0].stderr);assert.equal(results[1].status,0,results[1].stderr);
 const [a,b]=['a','b'].map(n=>JSON.parse(readFileSync(join(barrier,n),'utf8')));
 assert.notEqual(a.port,b.port);assert.notEqual(a.database,b.database);assert.notEqual(a.container,b.container);
 for(const c of [a,b])assert.notEqual(spawnSync('docker',['inspect',c.container]).status,0);
 } finally {rmSync(barrier,{recursive:true,force:true});}
});
test('real MySQL command TERM stops its child and removes its owned container', {skip:process.env.RUN_LOCAL_RESOURCE_MYSQL!=='1',timeout:120000},async()=>{
 const dir=mkdtempSync(join(tmpdir(),'fm-mysql-term-'));let p;
 try {
 const php=join(dir,'wait.php');writeFileSync(php,`<?php file_put_contents(getenv('PROOF_FILE'),json_encode(['pid'=>getmypid(),'container'=>getenv('MYSQL_CONTAINER_NAME'),'root'=>getenv('FM_TEST_RESOURCE_ROOT')])); sleep(60);`);
 p=spawn('bash',[join(root,'backend/scripts/ci/ensure_mysql.sh'),'--','php',php],{env:{...process.env,GITHUB_ACTIONS:'false',PROOF_FILE:join(dir,'ready')}});p.stdout.resume();let stderr='';p.stderr.on('data',b=>stderr+=b);const ended=new Promise(r=>p.on('close',status=>r(status)));
 const deadline=Date.now()+90000;while(!existsSync(join(dir,'ready'))&&Date.now()<deadline)await new Promise(r=>setTimeout(r,100));assert.ok(existsSync(join(dir,'ready')),stderr);
 const proof=JSON.parse(readFileSync(join(dir,'ready'),'utf8'));p.kill('SIGTERM');assert.equal(await ended,143,stderr);assert.equal(existsSync(proof.root),false);assert.notEqual(spawnSync('docker',['inspect',proof.container]).status,0);assert.throws(()=>process.kill(proof.pid,0));
 }finally{if(p?.exitCode===null)p.kill('SIGTERM');rmSync(dir,{recursive:true,force:true});}
});
