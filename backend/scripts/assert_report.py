#!/usr/bin/env python3
import argparse
import json
import sys
from typing import Any, Dict


def die(msg: str, code: int = 2) -> None:
    print(f"[ASSERT][FAIL] {msg}", file=sys.stderr)
    sys.exit(code)


def load_json(path: str) -> Dict[str, Any]:
    try:
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f)
    except FileNotFoundError:
        die(f"file not found: {path}", 2)
    except json.JSONDecodeError as e:
        die(f"invalid JSON in {path}: {e}", 2)
    except Exception as e:
        die(f"cannot read {path}: {e}", 2)


def pick_first(*vals: Any) -> Any:
    for v in vals:
        if v not in (None, "", [], {}, False):
            return v
    return ""


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--report", required=True, help="path to report.json")
    ap.add_argument("--share", required=True, help="path to share.json")
    ap.add_argument("--expect-pack-prefix", default="", help="substring that must appear in content_pack_id")
    ap.add_argument("--expect-locale", default="zh-CN", help="expected locale, e.g. zh-CN")
    args = ap.parse_args()

    report_path = args.report
    share_path = args.share

    report_root = load_json(report_path)
    share_root = load_json(share_path)

    # --- top-level ok ---
    if report_root.get("ok") is not True:
        die(f"report.ok != true (got {report_root.get('ok')})")
    if share_root.get("ok") is not True:
        die(f"share.ok != true (got {share_root.get('ok')})")

    report = report_root.get("report") or {}
    versions = report.get("versions") or {}

    # content_pack_id fallback chain
    content_pack_id = pick_first(
        versions.get("content_pack_id"),
        versions.get("content_pack"),
        report.get("content_pack_id"),
        report_root.get("content_pack_id"),
        "",
    )
    if not content_pack_id:
        die("report.versions.content_pack_id missing")

    # sections.traits.cards
    sections = report.get("sections") or {}
    traits = sections.get("traits") or {}
    cards = traits.get("cards") or []
    if not isinstance(cards, list) or len(cards) <= 0:
        die("report.sections.traits.cards empty or missing")

    # share_id
    share_id = share_root.get("share_id")
    if not share_id:
        die("share.share_id missing")

    # attempt_id: require existence AND consistency if both sides present
    attempt_id_report = report_root.get("attempt_id") or ""
    attempt_id_share = share_root.get("attempt_id") or ""
    attempt_id = pick_first(attempt_id_share, attempt_id_report, "")
    if not attempt_id:
        die("attempt_id missing in share/report root")

    if attempt_id_report and attempt_id_share and attempt_id_report != attempt_id_share:
        die(f"attempt_id mismatch: share={attempt_id_share} report={attempt_id_report}")

    # type_code: prefer share.type_code; fallback to report.profile.type_code; also allow report_root.type_code
    profile = report.get("profile") or {}
    report_type_code = pick_first(profile.get("type_code"), report_root.get("type_code"), "")
    share_type_code = pick_first(share_root.get("type_code"), "")

    if share_type_code and report_type_code and share_type_code != report_type_code:
        die(f"type_code mismatch: share={share_type_code} report={report_type_code}")

    final_type_code = pick_first(share_type_code, report_type_code, "")

    # identity_card.locale
    idcard = report.get("identity_card") or {}
    locale = idcard.get("locale")
    expect_locale = args.expect_locale
    if locale not in (expect_locale, expect_locale.replace("-", "_")):
        die(f"identity_card.locale unexpected: {locale} (expect {expect_locale})")

    # expect pack prefix
    if args.expect_pack_prefix and (args.expect_pack_prefix not in str(content_pack_id)):
        die(
            f"content_pack_id does not contain expected prefix: {args.expect_pack_prefix} "
            f"(got {content_pack_id})"
        )

    # ---- ok ----
    print("[ASSERT][OK] report/share assertions passed")
    print(f"  attempt_id={attempt_id}")
    print(f"  type_code={final_type_code}")
    print(f"  content_pack_id={content_pack_id}")
    print(f"  share_id={share_id}")
    print(f"  report_path={report_path}")
    print(f"  share_path={share_path}")


if __name__ == "__main__":
    main()