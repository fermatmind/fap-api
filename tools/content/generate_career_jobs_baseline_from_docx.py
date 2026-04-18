#!/usr/bin/env python3
"""Generate the zh-CN career jobs baseline from the 342 DOCX source files.

The important bit is that DOCX body content is traversed as real document
blocks, not as separate paragraph/table collections. This preserves paragraph,
table, heading, and list order in the emitted Markdown.
"""

from __future__ import annotations

import argparse
import copy
import datetime as dt
import json
import re
from pathlib import Path
from typing import Any

from docx import Document
from docx.oxml.table import CT_Tbl
from docx.oxml.text.paragraph import CT_P
from docx.table import Table
from docx.text.paragraph import Paragraph


SOURCE = "docx_342_career_batch"
ALL_MBTI = [
    "INTJ",
    "INTP",
    "ENTJ",
    "ENTP",
    "INFJ",
    "INFP",
    "ENFJ",
    "ENFP",
    "ISTJ",
    "ISFJ",
    "ESTJ",
    "ESFJ",
    "ISTP",
    "ISFP",
    "ESTP",
    "ESFP",
]
SECTION_MAP = {
    "01": ("day_to_day", 10),
    "02": ("skills_explained", 20),
    "03": ("growth_story", 30),
    "04": ("work_environment", 40),
    "05": ("related_reading_intro", 50),
    "06": ("related_reading_intro", 50),
}


def clean_text(value: str) -> str:
    return re.sub(r"[ \t]+", " ", value.replace("\xa0", " ")).strip()


def markdown_escape_cell(value: str) -> str:
    return clean_text(value).replace("|", r"\|").replace("\n", "<br>")


def table_to_rows(table: Table) -> list[list[str]]:
    rows: list[list[str]] = []
    for row in table.rows:
        rows.append([clean_text(cell.text) for cell in row.cells])
    return rows


def table_to_markdown(rows: list[list[str]]) -> str:
    if not rows:
        return ""
    width = max(len(row) for row in rows)
    normalized = [row + [""] * (width - len(row)) for row in rows]
    lines = [
        "| " + " | ".join(markdown_escape_cell(cell) for cell in normalized[0]) + " |",
        "| " + " | ".join("---" for _ in range(width)) + " |",
    ]
    for row in normalized[1:]:
        lines.append("| " + " | ".join(markdown_escape_cell(cell) for cell in row) + " |")
    return "\n".join(lines)


def iter_body_blocks(document: Document) -> list[dict[str, Any]]:
    blocks: list[dict[str, Any]] = []
    for child in document.element.body.iterchildren():
        if isinstance(child, CT_P):
            paragraph = Paragraph(child, document)
            text = clean_text(paragraph.text)
            if text:
                blocks.append({"type": "paragraph", "text": text, "style": paragraph.style.name})
        elif isinstance(child, CT_Tbl):
            table = Table(child, document)
            rows = table_to_rows(table)
            if rows:
                blocks.append({"type": "table", "rows": rows})
    return blocks


def filename_parts(path: Path) -> tuple[int, str, str]:
    match = re.match(r"^(?P<num>\d+)_([^_]+)_(?P<slug>.+)\.docx$", path.name)
    if not match:
        raise ValueError(f"Unsupported DOCX filename: {path.name}")
    return int(match.group("num")), match.group(2), match.group("slug")


def is_heading_text(text: str) -> bool:
    return text in {"职业快照", "数据来源"} or re.match(r"^\d{2}\s+", text) is not None


def paragraph_to_markdown(text: str, title: str) -> str:
    if text == title:
        return f"# {text}"
    if is_heading_text(text):
        return f"## {text}"
    return text


def table_pairs(rows: list[list[str]]) -> dict[str, str]:
    pairs: dict[str, str] = {}
    for row in rows:
        for i in range(0, len(row) - 1, 2):
            key = clean_text(row[i])
            value = clean_text(row[i + 1])
            if key:
                pairs[key] = value
    return pairs


def parse_int(value: str | None) -> int | None:
    if not value:
        return None
    digits = re.sub(r"[^0-9-]", "", value)
    return int(digits) if digits not in {"", "-"} else None


def parse_float(value: str | None) -> float | int | None:
    if not value:
        return None
    match = re.search(r"-?\d+(?:\.\d+)?", value.replace(",", ""))
    if not match:
        return None
    parsed = float(match.group(0))
    return int(parsed) if parsed.is_integer() else parsed


def parse_pct(value: str | None) -> int | None:
    if not value:
        return None
    match = re.search(r"\((-?\d+)%\)", value)
    if match:
        return int(match.group(1))
    match = re.search(r"(-?\d+)%", value)
    return int(match.group(1)) if match else None


def section_code(text: str) -> str | None:
    match = re.match(r"^(\d{2})\s+", text)
    return match.group(1) if match else None


def collect_sections(blocks: list[dict[str, Any]]) -> list[dict[str, Any]]:
    grouped: dict[str, dict[str, Any]] = {}
    current_code: str | None = None

    for block in blocks:
        if block["type"] != "paragraph":
            if current_code and current_code in SECTION_MAP:
                grouped[SECTION_MAP[current_code][0]]["body_parts"].append(table_to_markdown(block["rows"]))
            continue

        text = block["text"]
        if text == "数据来源":
            current_code = None
            continue

        code = section_code(text)
        if code in SECTION_MAP:
            current_code = code
            key, sort_order = SECTION_MAP[code]
            grouped.setdefault(
                key,
                {
                    "section_key": key,
                    "headings": [],
                    "body_parts": [],
                    "sort_order": sort_order,
                },
            )
            grouped[key]["headings"].append(text)
            continue

        if current_code and current_code in SECTION_MAP:
            grouped[SECTION_MAP[current_code][0]]["body_parts"].append(text)

    sections: list[dict[str, Any]] = []
    for key in ["day_to_day", "skills_explained", "growth_story", "work_environment", "related_reading_intro"]:
        group = grouped.get(key)
        if not group:
            continue
        headings = group["headings"]
        body_parts = [part for part in group["body_parts"] if part]
        sections.append(
            {
                "section_key": key,
                "title": " / ".join(headings),
                "render_variant": "rich_text",
                "body_md": "\n".join(body_parts),
                "body_html": None,
                "payload_json": {
                    "source": SOURCE,
                    "source_headings": headings,
                    "docx_block_order_preserved": True,
                },
                "sort_order": group["sort_order"],
                "is_enabled": True,
            }
        )
    return sections


def data_sources(blocks: list[dict[str, Any]]) -> list[dict[str, str]]:
    sources: list[dict[str, str]] = []
    in_sources = False
    for block in blocks:
        if block["type"] != "paragraph":
            continue
        text = block["text"]
        if text == "数据来源":
            in_sources = True
            continue
        if not in_sources:
            continue
        if "：" in text:
            label, url = text.split("：", 1)
        elif ":" in text:
            label, url = text.split(":", 1)
        else:
            continue
        sources.append({"label": clean_text(label), "url": clean_text(url)})
    return sources


def build_body_md(blocks: list[dict[str, Any]], title: str, route_line: str | None, source_docx: str) -> str:
    parts: list[str] = []
    for block in blocks:
        if block["type"] == "paragraph":
            text = block["text"]
            if text.startswith("FermatMind"):
                continue
            if route_line and text == route_line:
                continue
            parts.append(paragraph_to_markdown(text, title))
        elif block["type"] == "table":
            parts.append(table_to_markdown(block["rows"]))
    parts.append(f"<!-- source_docx: {source_docx} -->")
    return "\n\n".join(part for part in parts if part).rstrip() + "\n"


def update_job_from_docx(path: Path, template: dict[str, Any] | None) -> dict[str, Any]:
    sort_order, title_from_filename, slug = filename_parts(path)
    document = Document(path)
    blocks = iter_body_blocks(document)
    paragraphs = [block["text"] for block in blocks if block["type"] == "paragraph"]
    if len(paragraphs) < 4:
        raise ValueError(f"DOCX has too few paragraphs: {path}")

    title = paragraphs[1]
    route_line = paragraphs[2] if "/career/jobs/" in paragraphs[2] else None
    subtitle = None
    if route_line:
        subtitle = clean_text(route_line.split("·", 1)[0])
    excerpt = paragraphs[3]

    snapshot = next((block for block in blocks if block["type"] == "table"), None)
    snapshot_pairs = table_pairs(snapshot["rows"]) if snapshot else {}
    sources = data_sources(blocks)
    sections = collect_sections(blocks)
    section_by_key = {section["section_key"]: section for section in sections}
    salary_json = (template or {}).get("salary_json")
    outlook_json = (template or {}).get("outlook_json")
    if snapshot_pairs:
        salary_json = {
            "annual_median_usd": parse_int(snapshot_pairs.get("年薪中位数")),
            "hourly_median_usd": parse_float(snapshot_pairs.get("时薪中位数")),
            "annual_raw": snapshot_pairs.get("年薪中位数"),
            "hourly_raw": snapshot_pairs.get("时薪中位数"),
            "source": SOURCE,
        }
        outlook_json = {
            "jobs_2024": parse_int(snapshot_pairs.get("2024 岗位数")),
            "projected_jobs_2034": parse_int(snapshot_pairs.get("2034 预计岗位数")),
            "employment_change": parse_int(snapshot_pairs.get("十年变化")),
            "outlook_pct_2024_2034": parse_pct(snapshot_pairs.get("就业展望")),
            "outlook_raw": snapshot_pairs.get("就业展望"),
            "source": SOURCE,
        }

    job = copy.deepcopy(template) if template else {}
    job.update(
        {
            "job_code": slug,
            "slug": slug,
            "locale": "zh-CN",
            "title": title,
            "subtitle": subtitle,
            "excerpt": excerpt,
            "hero_kicker": snapshot_pairs.get("所属方向") or job.get("hero_kicker"),
            "hero_quote": job.get("hero_quote"),
            "cover_image_url": job.get("cover_image_url"),
            "industry_slug": job.get("industry_slug"),
            "industry_label": snapshot_pairs.get("所属方向") or job.get("industry_label"),
            "body_md": build_body_md(blocks, title, route_line, path.name),
            "body_html": None,
            "salary_json": salary_json or {"source": SOURCE},
            "outlook_json": outlook_json or {"source": SOURCE},
            "skills_json": {
                "core": [
                    line.removeprefix("• ").strip()
                    for line in (section_by_key.get("skills_explained", {}).get("body_md") or "").splitlines()
                    if line.startswith("• ")
                ],
                "supporting": [],
                "mapping_status": "pending_personality_review",
                "source": SOURCE,
            },
            "work_contents_json": {
                "items": [
                    line.removeprefix("• ").strip()
                    for line in (section_by_key.get("day_to_day", {}).get("body_md") or "").splitlines()
                    if line.startswith("• ")
                ],
                "source": SOURCE,
            },
            "growth_path_json": {
                "raw": [
                    line
                    for line in (section_by_key.get("growth_story", {}).get("body_md") or "").splitlines()
                    if line
                ],
                "source": SOURCE,
            },
            "fit_personality_codes_json": job.get("fit_personality_codes_json") or ALL_MBTI,
            "mbti_primary_codes_json": job.get("mbti_primary_codes_json") or ALL_MBTI,
            "mbti_secondary_codes_json": job.get("mbti_secondary_codes_json") or ALL_MBTI,
            "riasec_profile_json": job.get("riasec_profile_json") or {"R": 0, "I": 0, "A": 0, "S": 0, "E": 0, "C": 0},
            "big5_targets_json": job.get("big5_targets_json") or {"note": "DOCX occupation batch does not contain personality-specific Big5 calibration."},
            "iq_eq_notes_json": job.get("iq_eq_notes_json") or {"note": "DOCX occupation batch does not contain IQ/EQ calibration."},
            "market_demand_json": job.get("market_demand_json") or {"source": SOURCE},
            "status": job.get("status") or "published",
            "is_public": job.get("is_public", True),
            "is_indexable": job.get("is_indexable", True),
            "published_at": job.get("published_at"),
            "scheduled_at": job.get("scheduled_at"),
            "sort_order": sort_order,
            "sections": sections,
            "seo_meta": {
                "seo_title": f"{title}｜FermatMind 职业库",
                "seo_description": excerpt,
                "canonical_url": None,
                "og_title": None,
                "og_description": None,
                "og_image_url": None,
                "twitter_title": None,
                "twitter_description": None,
                "twitter_image_url": None,
                "robots": "index,follow",
                "jsonld_overrides_json": {
                    "source_docx": path.name,
                    "source_docx_sort_order": sort_order,
                    "source_docx_title_from_filename": title_from_filename,
                    "docx_block_order_preserved": True,
                    "soc_code": snapshot_pairs.get("SOC 代码"),
                    "data_sources": sources,
                },
            },
        }
    )
    return job


def load_template(path: Path) -> dict[str, dict[str, Any]]:
    if not path.exists():
        return {}
    payload = json.loads(path.read_text(encoding="utf-8"))
    return {str(job["slug"]): job for job in payload.get("jobs", [])}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--docx-dir", default="/Users/rainie/Desktop/342职业")
    parser.add_argument("--output", default="content_baselines/career_jobs/career_jobs.zh-CN.json")
    parser.add_argument("--template", default="content_baselines/career_jobs/career_jobs.zh-CN.json")
    args = parser.parse_args()

    docx_dir = Path(args.docx_dir)
    output = Path(args.output)
    templates = load_template(Path(args.template))
    paths = [
        path
        for path in docx_dir.glob("*.docx")
        if not path.name.startswith("~$") and re.match(r"^\d+_.+_.+\.docx$", path.name)
    ]
    paths.sort(key=lambda path: filename_parts(path)[0])
    if len(paths) != 342:
        raise RuntimeError(f"Expected 342 DOCX files, found {len(paths)} in {docx_dir}")

    jobs = [update_job_from_docx(path, templates.get(filename_parts(path)[2])) for path in paths]
    payload = {
        "meta": {
            "schema_version": "v1",
            "locale": "zh-CN",
            "source": SOURCE,
            "generated_at": dt.datetime.now(dt.UTC).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
            "source_dir": str(docx_dir),
            "docx_job_count": len(paths),
            "job_count": len(jobs),
            "docx_block_order_preserved": True,
        },
        "jobs": jobs,
    }
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"wrote {output} jobs={len(jobs)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
