import fs from 'node:fs';
import path from 'node:path';
const here=path.dirname(new URL(import.meta.url).pathname);
const seed=JSON.parse(fs.readFileSync(path.join(here,'big_five_en_legacy_polarity_link_repair_10_seed.json'),'utf8'));
const qa=JSON.parse(fs.readFileSync(path.join(here,'qa_report.json'),'utf8'));
if(seed.contract_version!=='personality_public_asset.v1'||!Array.isArray(seed.assets)||seed.assets.length!==10) throw new Error('invalid V1 envelope');
if(Object.values(qa.checks).some(v=>v!==true)) throw new Error(`QA failed: ${JSON.stringify(qa.checks)}`);
console.log('validated 10 English Legacy polarity link repairs');
