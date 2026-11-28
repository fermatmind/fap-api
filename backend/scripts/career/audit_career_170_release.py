#!/usr/bin/env python3
"""Audit the exact Wave 1 Career cohort through the Current PHP reader and display resolver."""

import argparse
from collections import Counter, defaultdict
from concurrent.futures import ThreadPoolExecutor
from hashlib import sha256
from html.parser import HTMLParser
from http.client import HTTPException
from itertools import combinations
import json
from pathlib import Path
import re
import socket
import subprocess
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlsplit
from urllib.request import Request, urlopen

from opencc import OpenCC

import lint_career_v3 as lint
import preflight_detail_gate as gate
from career_detail_patcher import CURRENT, HERE, public_items, read_json

HOST = "https://fermatmind.com"
SEGMENTS = {"zh-CN": "zh", "en": "en", "zh-TW": "zh-TW"}
COUNTS = {"zh-CN": 70, "en": 70, "zh-TW": 30}
CTA = re.compile(r"/(?:tests|career-compare|career/(?:compare|comparisons)|workbooks?|worksheets?)(?:/|$)")
SUFFIX = re.compile(r"\b(?:except computer|except engines|except special education|except maids and housekeeping cleaners|all other)\b", re.I)
TITLE_DISCLAIMER = re.compile(r"^(?:disclaimer|caution:|not legal advice|免责声明|免責聲明)|待核|資料不足|数据不足", re.I)
CONTEXT = re.compile(r"\d|\b(?:U\.S\.|United States|state|employer|licensed|scope|local|BLS|OEWS)\b|台灣|臺灣|中国大陆|中國大陸|法規|口径|口徑|不得|職類|行业|產業|需要|須|需|只有")
PRIMARY_TYPES = re.compile(r"government|official|primary|professional|regulator|law|standard|官方|政府|法規|公部門|协会|學會", re.I)
HIGH_RISK = re.compile(r"physician|medicin|pharmacy|therap|nurse|nursing|sonograph|radiologic|paramedic|^emts|"
                       r"veterinar|paralegal|tax-examin|explosive|aircraft|air-traffic|airline-pilot|flight-attendant")
SCOPE_BOUNDARY = re.compile(r"authoriz|licens|supervis|prescrib|paper.only|offline|diagnos|permission|"
                            r"授權|授权|診斷|诊断|許可|许可|紙面|纸面|法定|監督|监督|執業|执业|權責|权责", re.I)
PHP = r'''
require $argv[1].'/vendor/autoload.php';
$package = new App\Domain\Career\Display\CareerContentV3AuthorityPackage;
$reader = new App\Domain\Career\Display\CareerContentV3CanonicalReader($package, $argv[1]);
$projector = new App\Domain\Career\Display\CareerPageProjector(
    $reader, $package, new App\Domain\Career\Display\CareerContentV3FactResolver
);
foreach (json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as [$slug, $locale]) {
    try {
        $page = $projector->read($slug, $locale);
        $display = $page['display'] ?? null;
        if (!is_array($display)) {
            throw new RuntimeException('Current display unavailable');
        }
        $alternates = [];
        foreach (['zh-CN' => 'zh', 'en' => 'en', 'zh-TW' => 'zh-TW'] as $lang => $segment) {
            if ($reader->hasPublicBody($slug, $lang)) $alternates[$lang] = '/'.$segment.'/career/jobs/'.$slug;
        }
        echo json_encode(compact('slug', 'locale', 'display', 'alternates'), JSON_THROW_ON_ERROR)."\n";
        $reader->forgetLoadedPages();
    } catch (Throwable $failure) {
        echo json_encode(['slug' => $slug, 'locale' => $locale, 'error' => $failure->getMessage()], JSON_THROW_ON_ERROR)."\n";
    }
}
'''


def cohort(path):
    result = {}
    for line in path.read_text().splitlines():
        checksum, rel = line.split("  ", 1)
        parent, slug, filename = rel.split("/")
        locale, extension = filename.split(".")
        if (parent != "careers" or extension != "json" or locale not in COUNTS
                or not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", slug)
                or not re.fullmatch(r"[0-9a-f]{64}", checksum) or (slug, locale) in result):
            raise ValueError(f"invalid or duplicate cohort row: {line}")
        result[slug, locale] = (rel, checksum)
    if Counter(locale for _, locale in result) != COUNTS:
        raise ValueError("expected 70 zh-CN, 70 en and 30 zh-TW distinct pages")
    return result


def projections(identities):
    process = subprocess.run(
        ["php", "-r", PHP, str(HERE.parents[1])], input=json.dumps(list(identities)),
        capture_output=True, text=True, check=True,
    )
    return {(row["slug"], row["locale"]): row for line in process.stdout.splitlines()
            if (row := json.loads(line))}


def normalized(text):
    return " ".join(re.findall(r"\w+", text.casefold()))


def shingles(text, locale):
    parts = gate.WORD.findall(text.casefold()) if locale == "en" else re.findall(r"[\u3400-\u9fff]|[a-z0-9]+", text.casefold())
    n = 3 if locale == "en" else 5
    return set(zip(*(parts[i:] for i in range(n))))


def copy_text(value):
    return " ".join(text for _, text in lint.values(value) if not text.startswith(("http:", "https:", "/")))


def urls(value):
    for path, text in lint.values(value):
        if path[-1:] and path[-1] in {"href", "target_href", "url", "cta_href"}:
            yield path, text


def classification(url, status):
    host = urlsplit(url).hostname or ""
    if 200 <= status < 300:
        return "live"
    if status in {404, 410}:
        return "dead"
    if status == 403 and re.search(r"\.gov(?:\.[a-z]{2})?$|\.mil$", host):
        return "government_403"
    if status >= 500:
        return "server_error"
    return "unverified"


def probe(url):
    attempts = []
    for method in ("HEAD", "GET", "GET"):
        request = Request(url, headers={"User-Agent": "Mozilla/5.0 (compatible; FermatMind LinkAudit/1.0)"},
                          method=method)
        try:
            with urlopen(request, timeout=5) as response:
                attempts.append({"method": method, "status": response.status, "final_url": response.url})
                if method == "GET" or response.url == url and urlsplit(url).hostname not in {
                        "fermatmind.com", "www.fermatmind.com", "127.0.0.1", "localhost",
                }:
                    break
        except HTTPError as exc:
            attempts.append({"method": method, "status": exc.code, "final_url": exc.url})
            exc.close()
            if method == "GET" and exc.code < 500:
                break
        except (URLError, OSError, ValueError) as exc:
            reason = getattr(exc, "reason", exc)
            attempts.append({"method": method, "error": str(reason), "dns_code": getattr(reason, "errno", None)
                             if isinstance(reason, socket.gaierror) else None})
    last = attempts[-1]
    state = classification(url, last["status"]) if "status" in last else (
        "dns_failure" if last.get("dns_code") == socket.EAI_NONAME else "unverified")
    final = urlsplit(last.get("final_url", ""))
    if state in {"dead", "unverified"} and final.hostname == "www.fda.gov" and final.path.startswith("/apology_objects/"):
        state = "government_challenge"
    return {"url": url, "state": state, "attempts": attempts}


def comparison_specs(directory):
    locales = {"en": "en", "zh": "zh-CN", "zh-CN": "zh-CN", "zh-TW": "zh-TW"}
    result = []
    for file in sorted(directory.glob("*.ts")):
        text = file.read_text()
        start = text.find("ModularComparisonSpec = {")
        if start == -1:
            continue
        literals = dict(re.findall(r"^const (\w+) = ['\x22]([^'\x22]+)['\x22];", text[:start], re.M))
        header = text[start:].splitlines()[:5]
        fields = {}
        for line in header:
            if match := re.fullmatch(r"\s*(pairId|locale|title):\s*(['\x22`])(.+?)\2,\s*", line):
                fields[match[1]] = re.sub(r"\$\{(\w+)\}", lambda ref: literals[ref[1]], match[3])
        if fields.keys() != {"pairId", "locale", "title"}:
            raise ValueError(f"cannot read comparison identity and title: {file.name}")
        result.append((fields["pairId"], locales[fields["locale"]], fields["title"]))
    if len(result) != 45 or len({(pair, locale) for pair, locale, _ in result}) != 45:
        raise ValueError(f"expected 45 locale comparison specifications, got {len(result)}")
    return result


class PageHTML(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.links = defaultdict(list)
        self.anchors = set()
        self.robots = []
        self.schema = []
        self.faq_questions = []
        self.faq_answers = []
        self.reader_fields = []
        self.title = []
        self.capture = None
        self.field_tag = None
        self.field = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "link":
            for rel in a.get("rel", "").lower().split():
                if rel in {"canonical", "alternate"}:
                    self.links[a.get("hreflang", "").lower() if rel == "alternate" else rel].append(a.get("href", ""))
        if tag == "meta" and a.get("name", "").lower() == "robots":
            self.robots.append(a.get("content", ""))
        if tag == "a" and a.get("href"):
            self.anchors.add(a["href"])
        if tag == "script":
            self.capture = [] if a.get("type") == "application/ld+json" else None
        elif tag == "title":
            self.capture = self.title
        field = a.get("data-career-api-field", "")
        if field:
            self.field_tag = tag
            self.field = []
            self.reader_fields.append((field, self.field))
        if field.startswith("faq_block.items.") and field.endswith(".question"):
            self.faq_questions.append([])
            self.capture = self.faq_questions[-1]
        elif field.startswith("faq_block.items.") and field.endswith(".answer"):
            self.faq_answers.append([])
            self.capture = self.faq_answers[-1]

    def handle_endtag(self, tag):
        if tag == "script" and self.capture is not None:
            self.schema.append(json.loads("".join(self.capture)))
        if tag in {"script", "title", "span", "p"}:
            self.capture = None
        if tag == self.field_tag:
            self.field = self.field_tag = None

    def handle_data(self, data):
        if self.capture is not None:
            self.capture.append(data)
        if self.field is not None:
            self.field.append(data)


def fetch_html(origin, path):
    for attempt in range(2):
        status = None
        try:
            parser = PageHTML()
            with urlopen(urljoin(origin, path), timeout=18) as result:
                status = result.status
                parser.feed(result.read().decode("utf-8"))
            return path, status, parser, None
        except HTTPError as exc:
            exc.close()
            return path, exc.code, None, str(exc)
        except (HTTPException, OSError, ValueError) as exc:
            if attempt:
                return path, status, None, str(exc)


def check_html(origin, rows, comparisons, issue):
    page_paths = {row["php"]["path"]: row for row in rows if "php" in row}
    pair_paths = {f"/{SEGMENTS[entry['locale']]}/career-compare/{entry['pair']}": entry
                  for entry in comparisons}
    paths = list(page_paths | pair_paths)
    with ThreadPoolExecutor(max_workers=6) as pool:
        fetched = {path: (status, page, error)
                   for path, status, page, error in pool.map(lambda path: fetch_html(origin, path), paths)}
    cc = OpenCC("s2t")
    for path, row in page_paths.items():
        status, page, error = fetched[path]
        key = row["page"]
        row["html"] = {"status": status}
        if error or status != 200:
            issue("ssr-response", key, path, error or status)
            continue
        slug, locale = key.split("/")
        schemas = {value.get("@type"): value for value in page.schema if isinstance(value, dict)}
        questions = schemas.get("FAQPage", {}).get("mainEntity", [])
        visible = [("".join(question), "".join(answer)) for question, answer in zip(page.faq_questions, page.faq_answers)]
        row["html"].update(faq_nodes=len(questions), faq_questions=len(page.faq_questions),
                           robots=page.robots, schema=list(schemas))
        expected = {tag.lower(): [HOST + f"/{segment}/career/jobs/{slug}"] for tag, segment in SEGMENTS.items()}
        expected.update({"canonical": [HOST + path], "x-default": expected["en"]})
        if dict(page.links) != expected or not any(
                {s.strip() for s in value.split(",")} == {"index", "follow"} for value in page.robots):
            issue("ssr-seo", key, path, {"links": page.links, "robots": page.robots})
        if row["title"]["text"] not in "".join(page.title):
            issue("ssr-content-binding", key, path, "".join(page.title))
        breadcrumb = schemas.get("BreadcrumbList", {}).get("itemListElement", [])
        if (not breadcrumb or [v.get("position") for v in breadcrumb] != list(range(1, len(breadcrumb) + 1))
                or breadcrumb[-1].get("item") != HOST + path):
            issue("ssr-breadcrumb", key, path, breadcrumb)
        if not questions or len(questions) != len(page.faq_questions) or len(page.faq_questions) != len(page.faq_answers) or any(
                x.get("@type") != "Question" or not x.get("name")
                or x.get("acceptedAnswer", {}).get("@type") != "Answer"
                or not x.get("acceptedAnswer", {}).get("text")
                for x in questions):
            issue("ssr-faq", key, path, {"jsonld": len(questions), "visible": len(page.faq_questions)})
        elif [(item["name"], item["acceptedAnswer"]["text"]) for item in questions] != visible:
            issue("ssr-faq-copy", key, path, "visible FAQ differs from FAQPage text")
        for item in questions:
            if gate.WORKED_ANSWER.search(item.get("name", "")):
                issue("ssr-faq-spoiler", key, path, item["name"])
        for field, tokens in page.reader_fields:
            visible_text = "".join(tokens).strip()
            if locale == "en" and (gate.HAN.search(visible_text) or SUFFIX.search(visible_text)):
                issue("ssr-reader-language", key, field, visible_text)
            elif locale == "zh-TW" and (gate.TW_TERMS.search(visible_text) or cc.convert(visible_text) != visible_text):
                issue("ssr-reader-language", key, field, visible_text)
        ctas = {urlsplit(url).path for url in page.anchors if CTA.search(urlsplit(url).path)
                and urlsplit(url).hostname in {None, "fermatmind.com", "www.fermatmind.com"}}
        row["html"]["cta_count"] = len(ctas)
        for target in sorted(ctas):
            if not target.startswith(f"/{SEGMENTS[locale]}/"):
                issue("ssr-cta-locale", key, path, target)
    for path, entry in pair_paths.items():
        status, page, error = fetched[path]
        entry["local_http"] = status
        if error or status != 200:
            issue("comparison-response", entry["pair"], path, error or status)
            continue
        anchors = {urlsplit(url).path for url in page.anchors}
        for slug in entry["endpoints"]:
            endpoint = f"/{SEGMENTS[entry['locale']]}/career/jobs/{slug}"
            back = fetched.get(endpoint, (None, None, None))[1]
            if endpoint not in anchors:
                issue("comparison-missing-detail-link", entry["pair"], path, endpoint)
            if back is not None and path not in {urlsplit(url).path for url in back.anchors}:
                issue("detail-missing-comparison-link", page_paths[endpoint]["page"], endpoint, path)


def inspect(args):
    identities = cohort(args.cohort)
    php = projections(identities)
    specs = comparison_specs(args.compare_dir)
    targets = defaultdict(list)
    ctas = defaultdict(list)
    findings = []
    rows = []
    metadata = defaultdict(list)
    similarities = defaultdict(dict)
    cc = OpenCC("s2t")

    def issue(rule, page, path, detail):
        findings.append({"rule": rule, "page": page, "path": path, "detail": detail})

    for (slug, locale), (relative, checksum) in identities.items():
        key = f"{slug}/{locale}"
        raw = (CURRENT / relative).read_bytes()
        page = read_json(raw)
        row = {"page": key, "sha256": sha256(raw).hexdigest(), "changed_since_freeze": sha256(raw).hexdigest() != checksum}
        rows.append(row)
        for finding in gate.editorial(page, strict_duplicates=True):
            issue(finding.rule, key, gate.pointer(finding.path), finding.detail)
        for field, (low, high) in zip(("title", "description"),
                                      ((45, 65), (120, 160)) if locale == "en" else ((16, 32), (60, 110))):
            value = page["seo"][field].get("text", "")
            metadata[field, normalized(value)].append(key)
            count = len(value) if locale == "en" else len(re.sub(r"\s+", "", value))
            row[field] = {"text": value, "length": count}
            if not low <= count <= high:
                issue("serp-length", key, f"/seo/{field}/text", f"{count} outside {low}–{high}")
            if field == "title" and TITLE_DISCLAIMER.search(value):
                issue("serp-disclaimer-title", key, "/seo/title/text", value)

        projected = php.get((slug, locale), {})
        if not projected.get("display"):
            issue("php-projection", key, "/display", projected.get("error", "no PHP result"))
            continue
        display = projected["display"]
        expected = {lang: f"/{segment}/career/jobs/{slug}" for lang, segment in SEGMENTS.items()}
        row["php"] = {"path": display["path"], "alternate_candidate_paths": projected["alternates"]}
        if display["path"] != expected[locale] or projected["alternates"] != expected:
            issue("php-identity", key, "/display/path", row["php"])
        components = display["components"]
        row["php"]["visible_faq_count"] = len(components.get("faq_block", {}).get("items", []))
        traced, native = gate.Projection(page).build()
        for card, value in gate.visible_copy(traced, native):
            match = (SUFFIX.search(value) or gate.HAN.search(value)) if locale == "en" else (
                gate.TW_TERMS.search(value) or (cc.convert(value) != value and value) if locale == "zh-TW" else None)
            if match:
                issue("display-language", key, gate.pointer(value.path), match.group() if isinstance(match, re.Match) else match)
        salary = [components.get(f"career_snapshot_{loc}_locale", {}) for loc in ("primary", "secondary")]
        snippets = {
            "salary": next((block.get("direct_answer") or block.get("callout") for block in salary
                            if block.get("direct_answer") or block.get("callout")), ""),
            "entry": components.get("career_path_block", {}).get("direct_answer", ""),
            "work": components.get("definition_block", ""),
            "ai": components.get("ai_impact_table", {}).get("answer", ""),
        }
        for concept, value in snippets.items():
            text = copy_text(value)
            n = len(gate.WORD.findall(text)) if locale == "en" else sum(map(len, gate.HAN.findall(text)))
            low, high = (30, 80) if locale == "en" else (40, 100)
            row.setdefault("geo", {})[concept] = {"length": n, "has_context": bool(CONTEXT.search(text)), "text": text}
            if not (low <= n <= high and CONTEXT.search(text)):
                issue("geo-answer", key, f"/geo/{concept}", row["geo"][concept])
        if HIGH_RISK.search(slug):
            boundary = copy_text([components.get("career_path_block"), components.get("career_risk_cards"),
                                  components.get("boundary_notice"),
                                  [item for name, item in native.items() if name.endswith("entry-work-sample-data.1")]])
            row["scope_boundary_markers"] = sorted(set(SCOPE_BOUNDARY.findall(boundary)))
            if not row["scope_boundary_markers"]:
                issue("high-risk-boundary-review", key, "/display", "no explicit authority or offline marker")

        quick = copy_text(components.get("fit_decision_checklist", {}).get("boundary", ""))
        trial = " ".join(copy_text(item.get("data", {})) for name, item in native.items() if name.endswith("entry-work-sample-data.1"))
        for target, text in (("quick-decision-02", quick), ("paper-trial", trial), ("ai-impact", copy_text(snippets["ai"]))):
            token_set = shingles(text, locale)
            if token_set:
                similarities[locale, target][key] = token_set
            else:
                issue("comparison-copy-missing", key, f"/{target}", "no auditable text")

        sources = {}
        for block, item, path in public_items(page):
            if block == "source-register" and item["type"] == "sources":
                for index, source in enumerate(item["data"]["entries"]):
                    url = source.get("url")
                    if url and not gate.retired_source(source):
                        sources[url] = source
        primary = sorted(url for url, source in sources.items() if PRIMARY_TYPES.search(str(source.get("evidence_type", "")))
                         or re.search(r"\.gov(?:\.[a-z]{2})?$", urlsplit(url).hostname or ""))
        row["sources"] = {"unique": len(sources), "primary_candidate_urls": primary}
        if len(primary) < 2:
            issue("thin-primary-sources", key, "/blocks/source-register", len(primary))
        exact = {(path, text) for path, text in lint.values(page)
                 if path[-1] in {"url", "href", "cta_href", "target_href"} and text.startswith(("https://", "http://"))}
        exact_paths = {path for path, _ in exact}
        for path, url in sorted(exact | {(path, url) for path, url in gate.links(page) if path not in exact_paths}):
            parsed = urlsplit(url)
            if parsed.hostname not in {"fermatmind.com", "www.fermatmind.com"}:
                targets[url].append([key, gate.pointer(path)])
        for path, url in urls(display):
            parsed = urlsplit(url)
            if parsed.netloc not in {"", "fermatmind.com", "www.fermatmind.com"} or not CTA.search(parsed.path):
                continue
            ctas[parsed.path].append([key, gate.pointer(path)])
            # The traditional route uses the Chinese base href for the shared
            # renderer contract, then localizes the rendered CTA on the page.
            traditional_primary = (locale == "zh-TW" and path == ("components", "primary_cta", "href")
                                   and parsed.path.startswith("/zh/tests/"))
            if not traditional_primary and not parsed.path.startswith(f"/{SEGMENTS[locale]}/"):
                issue("cta-locale", key, gate.pointer(path), url)

    for (field, _), pages in metadata.items():
        if len(pages) > 1:
            issue("serp-duplicate", pages[0], f"/seo/{field}/text", pages)
    for (locale, target), copies in similarities.items():
        for (left, one), (right, two) in combinations(copies.items(), 2):
            score = len(one & two) / len(one | two)
            if score >= 0.60:
                issue("cross-page-similarity", left, f"/{target}", {"other": right, "jaccard": round(score, 4)})
    comparisons = []
    for pair, locale, title in specs:
        endpoints = pair.split("-vs-")
        outside = [slug for slug in endpoints if (slug, locale) not in identities]
        normalized_title = normalized(title)
        for row in rows:
            if row["page"].endswith("/" + locale) and normalized(row["title"]["text"]) == normalized_title:
                issue("comparison-title-collision", row["page"], "/seo/title/text", pair)
        comparisons.append({"pair": pair, "locale": locale, "endpoints": endpoints, "outside_cohort": outside})
        if len(endpoints) != 2 or outside:
            issue("comparison-cohort", f"{pair}/{locale}", "/comparison/endpoints", outside)

    if args.local_origin:
        check_html(args.local_origin, rows, comparisons, issue)
    link_rows = []
    if args.probe_links:
        jobs = [(url, "external", owner) for url, owner in targets.items()]
        destinations = [("public-cta", HOST)]
        if args.local_origin:
            destinations.append(("local-cta", args.local_origin))
        for kind, origin in destinations:
            jobs += [(urljoin(origin, path), kind, owner) for path, owner in ctas.items()]
        with ThreadPoolExecutor(max_workers=24) as pool:
            for (url, kind, owners), result in zip(jobs, pool.map(probe, (url for url, _, _ in jobs))):
                link_rows.append({**result, "kind": kind, "owners": owners})
                if result["state"] not in {"live", "government_403", "government_challenge"}:
                    issue("link-" + result["state"], owners[0][0], owners[0][1], url)
    coverage = {
        "php_backend": sum("php" in row for row in rows),
        "external_urls": len(targets), "cta_paths": len(ctas),
        "link_probe": "run" if args.probe_links else "not_run",
        "local_cta": "run" if args.probe_links and args.local_origin else "not_run",
        "html": sum(row.get("html", {}).get("status") == 200 for row in rows),
        "local_comparison_html": sum(row.get("local_http") == 200 for row in comparisons),
    }
    return {"coverage": coverage, "pages": rows, "comparisons": comparisons, "links": link_rows, "findings": findings}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cohort", type=Path, required=True)
    parser.add_argument("--compare-dir", type=Path, required=True)
    parser.add_argument("--out", type=Path, required=True)
    parser.add_argument("--probe-links", action="store_true")
    parser.add_argument("--local-origin")
    args = parser.parse_args()
    report = inspect(args)
    args.out.mkdir(parents=True, exist_ok=True)
    (args.out / "audit.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    counts = Counter(item["rule"] for item in report["findings"])
    table = ["# Wave 1 deterministic audit", "", "| Rule | Findings |", "|---|---:|"]
    table.extend(f"| {rule} | {count} |" for rule, count in sorted(counts.items()))
    table += ["", "## Page checks", "", "| Page | PHP projection | Title | Description | GEO ranges |",
              "|---|---|---:|---:|---|"]
    for row in report["pages"]:
        counts_text = ", ".join(f"{kind} {value['length']}" for kind, value in row.get("geo", {}).items())
        table.append(f"| {row['page']} | {'PASS' if 'php' in row else 'FAIL'} | {row['title']['length']} | {row['description']['length']} | {counts_text} |")
    (args.out / "audit.md").write_text("\n".join(table) + "\n")
    print(json.dumps({"coverage": report["coverage"], "findings": counts}, ensure_ascii=False))
    return int(bool(report["findings"]) or not args.probe_links or not args.local_origin)


if __name__ == "__main__":
    raise SystemExit(main())
