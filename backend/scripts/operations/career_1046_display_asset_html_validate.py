#!/usr/bin/env python3
"""Validate one rendered Career job page without executing client JavaScript."""

from __future__ import annotations

import re
import sys
from html.parser import HTMLParser


class CareerPageParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.canonical: list[str] = []
        self.alternates: dict[str, str] = {}
        self.robots: list[str] = []
        self.test_ids: set[str] = set()
        self.cta_attribution = False
        self.ai_depth = 0
        self.ai_image = False
        self.text: list[str] = []
        self.ignored_depth = 0

    def handle_starttag(self, tag: str, attrs_list: list[tuple[str, str | None]]) -> None:
        attrs = {key: value or "" for key, value in attrs_list}
        if tag in {"script", "style", "noscript"}:
            self.ignored_depth += 1
        rel = {part.lower() for part in attrs.get("rel", "").split()}
        if tag == "link" and "canonical" in rel:
            self.canonical.append(attrs.get("href", ""))
        if tag == "link" and "alternate" in rel and attrs.get("hreflang"):
            self.alternates[attrs["hreflang"]] = attrs.get("href", "")
        if tag == "meta" and attrs.get("name", "").lower() == "robots":
            self.robots.append(attrs.get("content", "").lower())
        if attrs.get("data-testid"):
            self.test_ids.add(attrs["data-testid"])
        if self.ai_depth and tag == "img":
            self.ai_image = True
        if tag not in {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"}:
            if self.ai_depth:
                self.ai_depth += 1
            elif attrs.get("data-testid") == "career-ai-description-block":
                self.ai_depth = 1
        if (
            tag == "a"
            and attrs.get("data-entry-surface") == "career_job_detail"
            and attrs.get("data-source-page-type") == "career_job_detail"
            and attrs.get("data-target-action") == "start_riasec_test"
            and attrs.get("data-test-slug") == "holland-career-interest-test-riasec"
        ):
            self.cta_attribution = True

    def handle_endtag(self, tag: str) -> None:
        if tag in {"script", "style", "noscript"} and self.ignored_depth:
            self.ignored_depth -= 1
        if self.ai_depth:
            self.ai_depth -= 1

    def handle_data(self, data: str) -> None:
        if not self.ignored_depth and data.strip():
            self.text.append(data)


def main() -> int:
    if len(sys.argv) != 5:
        print("web_validator_contract")
        return 1
    path, slug, locale, segment = sys.argv[1:]
    expected_self = f"https://fermatmind.com/{segment}/career/jobs/{slug}"
    expected_en = f"https://fermatmind.com/en/career/jobs/{slug}"
    expected_zh = f"https://fermatmind.com/zh/career/jobs/{slug}"
    parser = CareerPageParser()
    with open(path, "r", encoding="utf-8") as stream:
        parser.feed(stream.read())

    if parser.canonical != [expected_self]:
        print("web_canonical")
        return 1
    if parser.alternates.get("en") != expected_en or parser.alternates.get("zh-CN") != expected_zh:
        print("web_hreflang")
        return 1
    if not parser.robots or any("noindex" in value or "nofollow" in value for value in parser.robots):
        print("web_robots")
        return 1
    required = {
        "career-display-surface",
        "career-display-hero",
        "definition-block",
        "responsibilities-block",
        "career-snapshot-primary",
        "career-display-faq",
        "career-ai-description-block",
        "career-path-block",
        "career-decision-action-block",
    }
    if not required.issubset(parser.test_ids):
        print("web_modules")
        return 1
    if not parser.cta_attribution:
        print("web_cta_attribution")
        return 1
    if parser.ai_image:
        print("web_ai_image")
        return 1
    visible = "\n".join(parser.text)
    if any(marker in visible for marker in (
        "display_asset_backed_directory_draft_shell",
        "暂不提供完整页面",
        "Full page is not available yet",
    )):
        print("web_degraded_shell")
        return 1
    if re.search(r"(?m)^\s*(?:#{1,6}\s|>\s)", visible) or "**" in visible:
        print("web_raw_markdown")
        return 1

    print("pass")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
