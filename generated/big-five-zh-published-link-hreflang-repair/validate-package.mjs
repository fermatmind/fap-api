import fs from 'node:fs';
import path from 'node:path';
const here = path.dirname(new URL(import.meta.url).pathname);
const seed = JSON.parse(fs.readFileSync(path.join(here, 'big_five_zh_published_link_hreflang_repair_20_seed.json'), 'utf8'));
const qa = JSON.parse(fs.readFileSync(path.join(here, 'qa_report.json'), 'utf8'));
if (seed.contract_version !== 'personality_public_asset.v1' || !Array.isArray(seed.assets) || seed.assets.length !== 20) throw new Error('invalid V1 envelope or row count');
if (seed.assets.filter((a) => a.entity_type === 'domain').length !== 5 || seed.assets.filter((a) => a.entity_type === 'polarity').length !== 15) throw new Error('invalid topology');
if (Object.values(qa.checks).some((value) => value !== true)) throw new Error(`QA failed: ${JSON.stringify(qa.checks)}`);
console.log('validated 20 zh-CN Domain/Range link + hreflang repairs');
