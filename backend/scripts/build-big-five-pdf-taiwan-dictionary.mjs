import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const [packageDirectory, candidatePath] = process.argv.slice(2);
const pkg = resolve(packageDirectory);
assert.equal(JSON.parse(readFileSync(resolve(pkg, 'package.json'))).version, '1.4.2');
const { configs } = await import(pathToFileURL(resolve(pkg, 'dist/esm-lib/preset/cn2t.js')));
const config = configs.s2twp;
const group = dictionaries => Object.fromEntries([...dictionaries].reverse().flatMap(
  dictionary => dictionary.split('|').map(entry => entry.split(' ').slice(0, 2)),
));
const candidate = JSON.parse(readFileSync(candidatePath));
const data = {
  normalization: group(config.normalizationChain[0]),
  segmentation: Object.keys(group(config.segmentation)),
  stages: config.conversionChain.map(group),
  reviewedTerms: { '許可權': '權限', '宜人性': '親和性', '畫像結構': '人格輪廓' },
  copy: Object.fromEntries(candidate.units.map(({ source, translated }) => [source, translated])),
};
const output = new URL('../resources/i18n/big-five-pdf/opencc-twp.json', import.meta.url);
writeFileSync(output, JSON.stringify(data) + '\n');
writeFileSync(new URL('OPENCC-LICENSES.md', output), readFileSync(resolve(pkg, 'THIRD_PARTY_LICENSES.md')));
