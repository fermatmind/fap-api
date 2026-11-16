#!/usr/bin/env python3
"""Check comparison Markdown against its extracted context and emit draft digests."""

import argparse
import json
from pathlib import Path
import re
import sys
from urllib.parse import urlsplit

from extract_pair_context import digest, sha256

REQUIRED = {'title', 'description', 'locale', 'pair_id', 'canonical', 'input_hash', 'faq_items'}
INTERNAL = re.compile(r'待核(?!对)|证据不足|执行降级模板|围绕本职业对象、流程、质量与交付完成工作|\b(?:TODO|TBD)\b')
URL = re.compile(r'https?://[^\s<>\])"]+')
ATS = {'smartrecruiters.com', 'greenhouse.io', 'myworkdayjobs.com', 'workday.com', 'lever.co'}
SALARY = re.compile(r'\b(?:wages?|salary|earnings|pay|percentile)\b|薪[资酬]|工资|年薪', re.I)
USD = re.compile(r'(?:US\$|USD\s*|\$)\s*\d|\d[\d,.]*\s*(?:USD|美元)', re.I)
COURSE_PLAN = re.compile(r'20\d{2}[-_]20\d{2}.*(?:course|curriculum|plan|bset|eet).*\.pdf$', re.I)
RANK = re.compile(r'\b(10th|50th|90th|median)\b', re.I)


def unique_object(items):
    result = {}
    for key, value in items:
        if key in result:
            raise ValueError(f'duplicate frontmatter field: {key}')
        result[key] = value
    return result


def read_draft(raw):
    lines = raw.decode().splitlines()
    if not lines or lines[0] != '---':
        raise ValueError('missing frontmatter')
    end = lines.index('---', 1)
    metadata = json.loads('\n'.join(lines[1:end]), object_pairs_hook=unique_object)
    if not isinstance(metadata, dict) or REQUIRED - metadata.keys():
        raise ValueError('frontmatter does not match the draft contract')
    if any(not isinstance(metadata[key], str) or not metadata[key].strip() for key in REQUIRED - {'faq_items'}):
        raise ValueError('frontmatter text fields must be non-empty')
    faqs = metadata['faq_items']
    if not isinstance(faqs, list) or not faqs:
        raise ValueError('faq_items must contain at least one visible question')
    for item in faqs:
        if not isinstance(item, dict) or set(item) != {'question', 'answer'}:
            raise ValueError('faq_items must contain question and answer objects')
        if any(not isinstance(value, str) or not value.strip() for value in item.values()):
            raise ValueError('FAQ questions and answers must be nonempty text')
    if len({item['question'].strip().casefold() for item in faqs}) != len(faqs):
        raise ValueError('duplicate FAQ question')
    return metadata, '\n'.join(lines[end + 1:])


def lint_links(text):
    for match in URL.finditer(text):
        url = urlsplit(match.group().rstrip('.,，。'))
        host = url.hostname or ''
        if any(host == domain or host.endswith('.' + domain) for domain in ATS):
            raise ValueError(f'job listing URL: {host}')
        if not url.path.strip('/') and (host.endswith(('.gov', '.gov.cn')) or host == 'gov.cn'):
            raise ValueError(f'government homepage as a source: {host}')
        if host.endswith('.edu') and COURSE_PLAN.search(url.path):
            raise ValueError(f'annual university course plan: {host}')


def lint_wage_rows(body, locale):
    for line in body.splitlines():
        row = line.removeprefix('> ').strip()
        if not row.startswith('|'):
            continue
        cells = [cell.strip() for cell in row.strip('|').split('|')]
        if len(cells) != 3 or not SALARY.search(cells[0]):
            continue
        dollars = [bool(USD.search(cell)) for cell in cells[1:]]
        if locale == 'zh-CN' and any(dollars) and not re.search(r'美国|美方|BLS', cells[0]):
            raise ValueError('U.S. wage in an unscoped Chinese pay row')
        if locale == 'en' and RANK.search(cells[0]) and any(dollars) and not all(dollars):
            raise ValueError('asymmetric U.S. pay percentile row; omit unavailable comparison')


def check(context_path, draft_path):
    context = json.loads(context_path.read_bytes())
    inputs = context['inputs']
    if digest(inputs) != context['input_hash']:
        raise ValueError('context input hash mismatch')
    raw = draft_path.read_bytes()
    metadata, body = read_draft(raw)
    for key in ('pair_id', 'locale', 'canonical'):
        if metadata[key] != inputs[key]:
            raise ValueError(f'{key} differs from the extracted pair context')
    if metadata['input_hash'] != context['input_hash']:
        raise ValueError('draft is stale relative to this context')
    sections = re.split(r'^##[ \t]+', body, flags=re.M)
    marker = '<!-- comparison-faq -->'
    if len(sections) != 9 or body.count(marker) != 1 or marker not in sections[-1]:
        raise ValueError('include eight module headings and one FAQ insertion marker in the last module')
    text = body + '\n' + json.dumps(metadata, ensure_ascii=False)
    if INTERNAL.search(text):
        raise ValueError('internal status, placeholder or unfinished text')
    lint_links(text)
    lint_wage_rows(body, inputs['locale'])
    return {
        'pair_id': inputs['pair_id'],
        'locale': inputs['locale'],
        'input_hash': context['input_hash'],
        'draft_hash': sha256(raw),
        'body_hash': digest(' '.join(body.split())),
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', nargs=2, type=Path, metavar=('CONTEXT', 'DRAFT'), action='append', required=True)
    args = parser.parse_args()
    results = []
    identities = set()
    bodies = set()
    try:
        for context, draft in args.check:
            result = check(context, draft)
            identity = (result['pair_id'], result['locale'])
            body_key = (result['body_hash'], result['locale'])
            if identity in identities or body_key in bodies:
                raise ValueError(f'duplicate pair, locale or body: {draft}')
            identities.add(identity)
            bodies.add(body_key)
            results.append(result)
    except (KeyError, OSError, TypeError, ValueError) as error:
        print(f'lint: {error}', file=sys.stderr)
        return 1
    print(json.dumps(results, ensure_ascii=False, indent=2))
    return 0


if __name__ == '__main__':
    sys.exit(main())
