import assert from 'node:assert/strict';
import test from 'node:test';
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
