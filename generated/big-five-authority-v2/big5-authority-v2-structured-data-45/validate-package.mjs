import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = dirname(fileURLToPath(import.meta.url));
const fixturePath = join(dir, 'structured-data-findings.json');
const fixtureBytes = readFileSync(fixturePath);
const fixture = JSON.parse(fixtureBytes.toString('utf8'));
const expectedRoutes = [
  '/en/articles/big-five-conscientiousness-low-procrastination-task-plan',
  '/en/articles/big-five-emotional-stability-stress-recovery-communication',
  '/en/articles/big-five-personality-test-vs-mbti',
  '/zh/articles/big-five-conscientiousness-low-procrastination-task-plan',
  '/zh/articles/big-five-emotional-stability-stress-recovery-communication',
  '/zh/articles/big-five-growth-guide',
  '/zh/articles/big-five-narrative-portrait',
  '/zh/articles/big-five-personality-test-vs-mbti',
  '/zh/articles/big-five-tool-guide',
];

const fail = (message) => {
  throw new Error(`[PR45] ${message}`);
};

if (fixture.schema_version !== 'big5-structured-data-findings.v1') fail('schema version mismatch');
if (fixture.source?.artifact_sha256 !== '60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65') fail('source artifact hash mismatch');
if (JSON.stringify(fixture.counts) !== JSON.stringify({ findings: 13, unique_assets: 9, faq_json_ld_fail: 9, article_breadcrumb_fail: 4 })) fail('count lock mismatch');
if (!Array.isArray(fixture.assets) || fixture.assets.length !== 9) fail('asset count mismatch');
if (new Set(fixture.assets.map((asset) => asset.asset_id)).size !== 9) fail('asset ids are not unique');
if (fixture.assets.filter((asset) => asset.assessment?.faq_json_ld === 'FAIL').length !== 9) fail('FAQ failure count mismatch');
if (fixture.assets.filter((asset) => asset.assessment?.json_ld === 'FAIL').length !== 4) fail('Article/Breadcrumb failure count mismatch');
if (fixture.assets.some((asset) => asset.authority_surface !== 'CMS Article')) fail('unexpected authority surface');
if (JSON.stringify(fixture.assets.map((asset) => asset.route)) !== JSON.stringify(expectedRoutes)) fail('route lock mismatch');
if (fixture.runtime_policy?.frontend_inference !== false || fixture.runtime_policy?.production_resolution_claimed !== false) fail('truth boundary mismatch');

const actualHash = createHash('sha256').update(fixtureBytes).digest('hex');
const recordedHash = readFileSync(join(dir, 'structured-data-findings.sha256'), 'utf8').trim().split(/\s+/)[0];
if (actualHash !== recordedHash) fail('fixture sha256 mismatch');

console.log(`[PR45] package valid: assets=9 findings=13 sha256=${actualHash}`);
