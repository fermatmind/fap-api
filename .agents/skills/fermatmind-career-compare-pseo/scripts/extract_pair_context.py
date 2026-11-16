#!/usr/bin/env python3
"""Extract one source-bound comparison context from an approved pilot or Table A."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
from urllib.parse import urlsplit

SNAPSHOT = '01499c4b07afc968c1dc08c7e72c2281a7e31940'
SNAPSHOT_PATH = 'data/pseo/occupations-enriched.json'
GATES = {'career_edge', 'manifest_evidence', 'riasec', 'typical_tasks', 'zh_name'}
ROUTE_LOCALES = {'zh-CN': 'zh', 'en': 'en'}
SEED = ('electrical-engineers', 'electricians')
PAIR_CELL = re.compile(r'`([a-z0-9-]+)` / `([a-z0-9-]+)`')
LINK = re.compile(r'https?://[^\s<>\])]+')
SKILL = Path(__file__).resolve().parents[1] / 'SKILL.md'


def sha256(raw):
    return hashlib.sha256(raw).hexdigest()


def digest(value):
    return sha256(json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode())


def read_json(path):
    return json.loads(path.read_bytes())


def canonical(route_base, pair, locale):
    url = urlsplit(route_base)
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment:
        raise ValueError('route base must be an HTTPS URL without credentials, query or fragment')
    if url.path not in ('', '/'):
        raise ValueError('route base must be the site origin')
    return f'{route_base.rstrip("/")}/{ROUTE_LOCALES[locale]}/career-compare/{"-vs-".join(pair)}'


def table_a_row(table, pair):
    match = []
    in_table_a = False
    for line in table.splitlines():
        if line.startswith('## '):
            in_table_a = line.startswith('## 补充表 A｜')
        if in_table_a and line.startswith('| `'):
            cells = [cell.strip() for cell in line.strip('|').split('|')]
            slugs = PAIR_CELL.fullmatch(cells[0])
            if slugs and slugs.groups() == pair:
                if len(cells) != 6:
                    raise ValueError('invalid Table A row')
                match.append(cells)
    if len(match) != 1:
        raise ValueError('pair must occur exactly once in Table A')
    return match[0]


def qualification(pair, row):
    if pair == SEED:
        return {
            'electrical-engineers': {
                'branch': 'scoped',
                'lead_urls': ['https://www.mohurd.gov.cn/gongkai/zc/xzgfxwjk/art/2026/art_2063777831.html'],
            },
            'electricians': {
                'branch': 'scoped',
                'lead_urls': ['https://www.mem.gov.cn/gk/zfxxgkpt/fdzdgknr/202605/t20260526_604988.shtml'],
            },
        }
    fields = re.split(r'，(?=后岗)', row[5], maxsplit=1)
    result = {}
    for index, slug in enumerate(pair):
        marker = ('前岗', '后岗')[index]
        entry = next((field for field in fields if field.startswith(marker + '已核资格线索')), '')
        urls = sorted(set(LINK.findall(entry)))
        result[slug] = {'branch': 'scoped' if urls else 'degraded', 'lead_urls': urls}
    return result


def current_names(root, pair, locale):
    manifest = read_json(root / 'manifest.json')
    names = {}
    for slug in pair:
        entry, = [item for item in manifest['files']
                  if item['canonical_slug'] == slug and item['locale'] == locale]
        raw = (root / entry['path']).read_bytes()
        if sha256(raw) != entry['sha256']:
            raise ValueError(f'Career Current file digest mismatch: {slug}/{locale}')
        page = json.loads(raw)
        if page['subject']['canonical_slug'] != slug or page['locale'] != locale:
            raise ValueError(f'Career Current identity mismatch: {slug}/{locale}')
        names[slug] = page['subject']['name']
    return names


def facts_for_pair(path, pair, locale):
    if not path:
        return []
    packet = read_json(path)
    if packet['pair_id'] != '-vs-'.join(pair) or packet['locale'] != locale:
        raise ValueError('additional evidence belongs to another pair or locale')
    facts = packet['facts']
    if not isinstance(facts, list):
        raise ValueError('additional evidence facts must be an array')
    ids = set()
    for fact in facts:
        fields = ('id', 'source_url', 'locator')
        if not isinstance(fact, dict) or any(
            not isinstance(fact.get(key), str) or not fact[key].strip() for key in fields
        ):
            raise ValueError('additional evidence must identify its source and locator')
        url = urlsplit(fact['source_url'])
        if fact['id'] in ids or url.scheme != 'https' or not url.hostname or 'value' not in fact:
            raise ValueError('additional evidence must have a unique id, HTTPS source and value')
        ids.add(fact['id'])
    return facts


def extract(args):
    pair = tuple(sorted(args.slugs))
    if pair[0] == pair[1]:
        raise ValueError('two distinct career slugs are required')
    snapshot = subprocess.check_output(
        ['git', '-C', str(args.snapshot_repo), 'show', f'{SNAPSHOT}:{SNAPSHOT_PATH}']
    )
    map_raw = args.name_map.read_bytes()
    table_raw = args.table_a.read_bytes()
    name_map = json.loads(map_raw)
    if name_map['snapshot_sha256'] != sha256(snapshot) or not SNAPSHOT.startswith(name_map['source_commit']):
        raise ValueError('name map is bound to a different snapshot')
    occupations = {item['slug']: item for item in json.loads(snapshot)['occupations']}
    records = [occupations[slug] for slug in pair]
    for item in records:
        gates = item['gates']
        if not item['index_eligible'] or not GATES <= gates.keys() or not all(gates[g]['pass'] for g in GATES):
            raise ValueError(f'career has not passed all five gates: {item["slug"]}')
    directions = [
        f'{source["slug"]} -> {target["slug"]}'
        for source, target in (records, records[::-1])
        if any(edge['target_slug'] == target['slug'] for edge in source['career_edges'])
    ]
    if not directions:
        raise ValueError('pair has no explicit career edge in the snapshot')
    row = None if pair == SEED else table_a_row(table_raw.decode(), pair)
    if row and row[3] != ('互链' if len(directions) == 2 else '单向'):
        raise ValueError('Table A edge does not match the snapshot')

    locale = args.locale
    live_names = current_names(args.current_root, pair, locale)
    careers = {}
    for item in records:
        slug = item['slug']
        name = name_map['zh_name_by_slug'][slug] if locale == 'zh-CN' else item['name_en']
        if name != live_names[slug]:
            raise ValueError(f'comparison name differs from Career Current: {slug}/{locale}')
        careers[slug] = {
            'name': name,
            'riasec': item['riasec'],
            'tasks': [task[locale] for task in item['typical_tasks'] if task.get(locale)],
            'evidence': item['evidence'],
        }

    inputs = {
        'pair_id': '-vs-'.join(pair),
        'slugs': list(pair),
        'locale': locale,
        'canonical': canonical(args.site_origin, pair, locale),
        'snapshot_commit': SNAPSHOT,
        'careers': careers,
        'edge_directions': directions,
        'scope': 'sample-1' if row is None else 'supplementary-table-a',
        'table_a_task_hint_zh': row[4] if row and locale == 'zh-CN' else None,
        'cn_qualification': qualification(pair, row) if locale == 'zh-CN' else {},
        'additional_evidence': facts_for_pair(args.evidence, pair, locale),
        'rules_sha256': sha256(SKILL.read_bytes()),
    }
    return {
        'batch_versions': {
            'snapshot_sha256': sha256(snapshot),
            'name_map_sha256': sha256(map_raw),
            'table_a_sha256': sha256(table_raw),
        },
        'input_hash': digest(inputs),
        'inputs': inputs,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('slugs', nargs=2)
    parser.add_argument('--locale', choices=ROUTE_LOCALES, required=True)
    parser.add_argument('--snapshot-repo', type=Path, required=True)
    parser.add_argument('--table-a', type=Path, required=True)
    parser.add_argument('--name-map', type=Path, required=True)
    parser.add_argument('--current-root', type=Path, required=True)
    parser.add_argument('--site-origin', default='https://fermatmind.com')
    parser.add_argument('--evidence', type=Path)
    args = parser.parse_args()
    try:
        context = extract(args)
    except (KeyError, OSError, TypeError, ValueError, subprocess.CalledProcessError) as error:
        print(f'extract: {error}', file=sys.stderr)
        return 1
    print(json.dumps(context, ensure_ascii=False, indent=2))
    return 0


if __name__ == '__main__':
    sys.exit(main())
