import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { cp, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import test from 'node:test';

import { decodeHtmlEntitiesOnce } from '../../generated/_shared/decode-html-entities.mjs';
import { hasForbiddenHtmlCommentSyntax } from '../../generated/big-five-en52-translation/markdown-safety.mjs';

const execFileAsync = promisify(execFile);
const REPO_ROOT = path.resolve(import.meta.dirname, '..', '..');

test('detects every forbidden HTML comment marker without rejecting normal Markdown', () => {
  assert.equal(hasForbiddenHtmlCommentSyntax('# Safe Markdown\n\nVisible text.'), false);
  for (const value of [
    '<!-- hidden -->',
    '<!-- unclosed',
    'orphan -->',
    'orphan --!>',
    '<!-- outer <!-- inner --!> -->',
  ]) assert.equal(hasForbiddenHtmlCommentSyntax(value), true, value);
});

test('decodes HTML entities exactly once', () => {
  assert.equal(decodeHtmlEntitiesOnce('&quot;x&quot; &apos;y&apos; &lt;b&gt;'), '"x" \'y\' <b>');
  assert.equal(decodeHtmlEntitiesOnce('&amp;lt;'), '&lt;');
  assert.equal(decodeHtmlEntitiesOnce('&amp;amp;'), '&amp;');
});

test('EN52 validator fails closed with hidden_html_comment', async (context) => {
  const temporaryRoot = await mkdtemp(path.join(tmpdir(), 'en52-security-'));
  context.after(() => rm(temporaryRoot, { recursive: true, force: true }));
  const generatedRoot = path.join(temporaryRoot, 'generated');
  const packageRoot = path.join(generatedRoot, 'big-five-en52-translation');
  await cp(path.join(REPO_ROOT, 'generated/big-five-en52-translation'), packageRoot, { recursive: true });
  await cp(
    path.join(REPO_ROOT, 'generated/big-five-authority-v3'),
    path.join(generatedRoot, 'big-five-authority-v3'),
    { recursive: true },
  );

  const ledger = JSON.parse(await readFile(path.join(packageRoot, 'manifests/translation-ledger.json'), 'utf8'));
  const target = ledger.entries.find((entry) => entry.status === 'completed');
  assert.ok(target, 'expected a completed EN52 page');
  const targetPath = path.join(packageRoot, target.target_path);
  await writeFile(targetPath, `${await readFile(targetPath, 'utf8')}\n<!-- injected regression marker -->\n`);

  let stdout = '';
  try {
    await execFileAsync(process.execPath, [path.join(packageRoot, 'validate-authority.mjs'), '--expected-translated=52']);
    assert.fail('validator unexpectedly accepted forbidden comment syntax');
  } catch (error) {
    stdout = error.stdout;
  }
  const result = JSON.parse(stdout);
  assert.equal(result.qa_status, 'FAIL');
  assert.ok(result.errors.some((error) => error.gate === 'hidden_html_comment' && error.detail === target.target_path));
});
