import test from 'node:test';
import assert from 'node:assert/strict';
import { assertSsrCandidate } from './iq-public-scale-online-qa.mjs';

const row = { patch: {
  landing_copy: 'Understand this visual reasoning exercise.',
  why_choose: { intro: 'Use raw results carefully.', items: [
    { title: 'Example', body: '| A | B |\n| --- | --- |\n| 2 | 4 |\n\nRead [the method](https://fermatmind.com/en/articles/iq-method) before **starting**.' },
  ] },
  faq: [{ q: 'Is this a clinical IQ score?', a: 'No. The Beta indicator is simulated.' }],
} };
const html = '<main data-test-landing-read-source="fresh"><p>Understand this visual reasoning exercise.</p><p>Use raw results carefully.</p><h2>Example</h2><table><tr><td>A</td><td>B</td></tr><tr><td>2</td><td>4</td></tr></table><p>Read <a>the method</a> before <strong>starting</strong>.</p><details><summary>Is this a clinical IQ score?</summary><p>No. The Beta indicator is simulated.</p></details></main>';

test('SSR acceptance reads table cells, Markdown text and collapsed FAQ content', () => {
  assert.doesNotThrow(() => assertSsrCandidate(html, row));
});
test('matching navigation or structured data cannot substitute for a missing visible body', () => {
  assert.throws(() => assertSsrCandidate(`<script type="application/ld+json">${JSON.stringify(row)}</script>`, row), /IQ_SSR_(?:BODY_MISMATCH|MAIN_INVALID)/);
});
test('a stale FAQ answer fails even when every other paragraph matches', () => {
  assert.throws(() => assertSsrCandidate(html.replace('No. The Beta indicator is simulated.', 'A standardized clinical score.'), row), /IQ_SSR_BODY_MISMATCH/);
});
test('a missing table cell fails', () => {
  assert.throws(() => assertSsrCandidate(html.replace('<td>4</td>', ''), row), /IQ_SSR_BODY_MISMATCH/);
});

test('hidden content cannot replace the visible assessment body', () => {
  assert.throws(() => assertSsrCandidate(html.replace('<main ', '<main hidden '), row), /IQ_SSR_BODY_MISMATCH/);
  assert.throws(() => assertSsrCandidate(html.replace('<main ', '<main style="display: none" '), row), /IQ_SSR_BODY_MISMATCH/);
  assert.throws(() => assertSsrCandidate(html.replace('<main ', '<main class="hidden" '), row), /IQ_SSR_BODY_MISMATCH/);
});
test('matching text outside the actual main surface does not satisfy acceptance', () => {
  assert.throws(() => assertSsrCandidate(html.replace('<main ', '<footer ').replace('</main>', '</footer>') + '<main data-test-landing-read-source="fresh">Old entry</main>', row), /IQ_SSR_BODY_MISMATCH/);
});
