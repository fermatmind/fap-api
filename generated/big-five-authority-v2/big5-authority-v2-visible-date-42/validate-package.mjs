import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageDirectory = dirname(fileURLToPath(import.meta.url));
const fixturePath = join(packageDirectory, 'visible-date-findings.json');
const fixtureBytes = readFileSync(fixturePath);
const fixture = JSON.parse(fixtureBytes.toString('utf8'));
const expectedHash = readFileSync(join(packageDirectory, 'visible-date-findings.sha256'), 'utf8')
  .trim()
  .split(/\s+/u)[0];
const actualHash = createHash('sha256').update(fixtureBytes).digest('hex');

function invariant(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function distribution(rows, field) {
  return Object.fromEntries(
    [...rows.reduce((counts, row) => counts.set(row[field], (counts.get(row[field]) ?? 0) + 1), new Map())]
      .sort(([left], [right]) => left.localeCompare(right)),
  );
}

invariant(actualHash === expectedHash, `fixture SHA256 mismatch: ${actualHash}`);
invariant(fixture.schema_version === 'big5-visible-date-findings.v1', 'unexpected fixture schema');
invariant(
  fixture.source.artifact_sha256 === '60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65',
  'runtime closeout source hash changed',
);
invariant(fixture.source.selection === 'assessment.visible_date == FAIL', 'fixture selection is not fail-only');
invariant(fixture.counts.finding_count === 82 && fixture.findings.length === 82, 'expected exactly 82 findings');
invariant(new Set(fixture.findings.map(({ asset_id: assetId }) => assetId)).size === 82, 'asset ids must be unique');
invariant(new Set(fixture.findings.map(({ route }) => route)).size === 82, 'routes must be unique');
invariant(
  JSON.stringify(distribution(fixture.findings, 'page_family')) === JSON.stringify({
    domain: 5,
    facet: 30,
    facet_hub: 2,
    model_hub: 1,
    range: 40,
    test_landing: 2,
    topic_hub: 2,
  }),
  'page-family distribution changed',
);
invariant(
  JSON.stringify(distribution(fixture.findings, 'authority_surface')) === JSON.stringify({
    'CMS landing_surfaces/page_blocks': 2,
    'CMS personality_public_content_assets': 78,
    'CMS topic_profiles': 2,
  }),
  'authority-surface distribution changed',
);
invariant(
  JSON.stringify(distribution(fixture.findings, 'locale')) === JSON.stringify({ en: 64, 'zh-CN': 18 }),
  'locale distribution changed',
);
invariant(
  fixture.findings.every(({ assessment, observed_visible_date: observedVisibleDate }) =>
    assessment === 'FAIL' && observedVisibleDate === false),
  'fixture includes a row that is not an observed visible-date failure',
);
invariant(
  !fixtureBytes.toString('utf8').includes('/Users/') && !fixtureBytes.toString('utf8').includes('/private/'),
  'fixture leaks a private local path',
);

console.log(`visible-date package ok findings=82 sha256=${actualHash}`);
