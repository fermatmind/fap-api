import assert from 'node:assert/strict';
import test from 'node:test';
import {spawnSync} from 'node:child_process';
import {executionDependencyText,phpConsumers,selectOperations} from './impact-consumers.mjs';
const matrix='backend/app/Console/Commands/CareerPublicResolutionTypeMatrix.php';
test('literal Career owner metadata does not execute the named command; dynamic expressions stay conservative',()=>{
 const literal="'owner_paths' => ['app/Console/Commands/CareerReleaseGateNoindexCleanup.php',]";
 assert.equal(executionDependencyText(matrix,literal),"'owner_paths' => []");
 assert.equal(executionDependencyText('other.php',literal),literal);
 const dynamic="'owner_paths' => [app_path('app/Console/Commands/CareerReleaseGateNoindexCleanup.php')]";
 assert.equal(executionDependencyText(matrix,dynamic),dynamic);
});
test('ordinary article consumers stop at metadata; changed policy and real Career dependencies are still selected',()=>{
 const graph=phpConsumers();
 assert.ok(graph.closure(['backend/app/Services/Career/AiImpactAssets/CareerAiImpactAssetImportService.php']).has(matrix));
 assert.ok(!graph.edges.get(matrix).includes('backend/app/Console/Commands/CareerReleaseGateNoindexCleanup.php'));
 assert.equal(selectOperations(['backend/app/Filament/Ops/Support/ContentReleaseAudit.php']).career_cache,false);
 for(const path of [matrix,'backend/app/Services/Career/PublicCareerAuthorityResponseCache.php','backend/bootstrap/app.php','backend/app/Services/UnknownDynamicReader.php']) assert.equal(selectOperations([path]).career_cache,true,path);
});

test('only complete literal path lists are removed',()=>{
 for(const array of ["['app/One.php']", "[ \n 'app/One.php',\t'app/Two.php', \n]", "['app/One.php' , 'app/Two.php']"]) {
  const source="'owner_paths' \t => "+array+", 'other' => 'app/Real.php'";
  assert.equal(executionDependencyText(matrix,source),"'owner_paths' \t => [], 'other' => 'app/Real.php'");
 }
 for(const array of ["[app_path('app/One.php')]", "['app/One.php', app_path('app/Two.php')]", "['app/One.php' 'app/Two.php']", "['app/One.php',", "['app/One.php'", "['app/One.php', 42]", "['app/One.php', 'config/app.php']", "['app/One.php' + $dynamic]", "['app/One.php'] + app_path('app/Two.php')", "['app/One.php\\name']"]) {
  const source="'owner_paths' => "+array;
  assert.equal(executionDependencyText(matrix,source),source);
 }
});
test('long malformed metadata finishes in a bounded independent process',()=>{
 const source=`import {executionDependencyText} from ${JSON.stringify(new URL('./impact-consumers.mjs',import.meta.url).href)};
 const body="'owner_paths'=>['app/&"+"\\t'app/&".repeat(100000);
 if(executionDependencyText(${JSON.stringify(matrix)},body)!==body)process.exit(1);`;
 const result=spawnSync(process.execPath,['--input-type=module','-e',source],{timeout:3000,encoding:'utf8'});
 assert.ifError(result.error);
 assert.equal(result.status,0,result.stderr);
});
