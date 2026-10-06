import test from 'node:test';import assert from 'node:assert/strict';
import {changedTestPlan,runChangedTests} from './run-changed-tests.mjs';
test('changed PHP JS Python Shell execute real runner commands; removed and unsupported are explicit',()=>{
 const p=changedTestPlan(['backend/tests/Feature/AmbtiTest.php','.github/trunk/x.test.mjs','backend/tests/Sre/test_a.py','backend/tests/Sre/test_a.sh'].map(path=>({status:'A',path})).concat([{status:'D',path:'backend/tests/Feature/RemovedTest.php'}]));
 assert.equal(p.removed.length,1);const calls=[];
 const old=process.env.FEATURE_CONTENT_STORE_V2;process.env.FEATURE_CONTENT_STORE_V2='true';
 try{runChangedTests(p,{run:(cmd,args,options)=>{calls.push({cmd,args,env:options.env.FEATURE_CONTENT_STORE_V2});return {status:0};}});}finally{if(old===undefined)delete process.env.FEATURE_CONTENT_STORE_V2;else process.env.FEATURE_CONTENT_STORE_V2=old;}
 assert.deepEqual(calls.map(c=>c.cmd),['node','python3','bash','php']);assert.ok(calls.every(c=>c.env==='true'));
 assert.throws(()=>changedTestPlan([{status:'A',path:'backend/tests/Sre/test.rs'}]),/Unsupported/);
 assert.throws(()=>runChangedTests(p,{run:()=>({status:1})}),/failed/);
});

import {mkdtempSync,mkdirSync,writeFileSync,rmSync,readFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
test('language fixtures really execute assertions and propagate failures',()=>{
 const root=mkdtempSync(tmpdir()+'/changed-runners-');
 try {
  mkdirSync(root+'/tests');
  writeFileSync(root+'/tests/x.test.mjs',`import test from 'node:test';import assert from 'node:assert/strict';import {writeFileSync} from 'node:fs';test('env',()=>{assert.equal(process.env.FEATURE_CONTENT_STORE_V2,'true');writeFileSync('tests/node-executed','executed');});`);
  writeFileSync(root+'/tests/test_x.py',`import unittest
class TestEnvironment(unittest.TestCase):
 def test_assertion(self): self.assertEqual(2+2,4)
`);
  writeFileSync(root+'/tests/test_x.sh',`set -euo pipefail
test "$FEATURE_CONTENT_STORE_V2" = true
printf executed > tests/shell-executed
`);
  const plan=changedTestPlan(['tests/x.test.mjs','tests/test_x.py','tests/test_x.sh'].map(path=>({path,status:'A'})));
  const old=process.env.FEATURE_CONTENT_STORE_V2;process.env.FEATURE_CONTENT_STORE_V2='true';
  try{assert.equal(runChangedTests(plan,{root}).length,3);}finally{if(old===undefined)delete process.env.FEATURE_CONTENT_STORE_V2;else process.env.FEATURE_CONTENT_STORE_V2=old;}
  assert.equal(readFileSync(root+'/tests/node-executed','utf8'),'executed');
  assert.equal(readFileSync(root+'/tests/shell-executed','utf8'),'executed');
  writeFileSync(root+'/tests/test_x.py','value=42');
  assert.throws(()=>runChangedTests({...plan,node:[],shell:[]},{root}),/execution failed/);
  writeFileSync(root+'/tests/test_x.sh','exit 4');
  assert.throws(()=>runChangedTests({...plan,node:[],python:[]},{root}),/execution failed/);
 }finally{rmSync(root,{recursive:true,force:true});}
});
