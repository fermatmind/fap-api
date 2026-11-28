#!/usr/bin/env python3
"""Repair hash-bound Career detail layouts as detached package candidates."""

import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from copy import deepcopy
import json
from pathlib import Path
import re
from urllib.parse import urlsplit

from opencc import OpenCC

import career_display_contract as contract
from career_detail_patcher import CURRENT, WORKED_ANSWER, atomic_output, digest, encode, public_items, read_json, runtime
from lint_career_v3 import values
from preflight_detail_gate import Projection

CN = OpenCC("t2s")
TW = OpenCC("s2t")
NOTICE = "career.item.entry-work-sample-data"
OCCUPATIONAL_REFERENCE = "https://www.onetonline.org/"
US_METHODOLOGY = "https://www.bls.gov/oes/"
OLD_OIL = "https://hudong.moe.gov.cn/s78/A07/zcs_ztzl/2017_zt06/17zt06_bznr/bznr_zdzyxxzyml/zhongzhi/huanjing/202209/P020220902562774548098.pdf"
INTERIM_OIL = "https://www.moe.gov.cn/s78/A07/zcs_ztzl/2017_zt06/17zt06_bznr/bznr_zyjyzyjxbz/gdzyjy_zk/zk_zyhjyaqdl/zyhjyaqdl_syytrql/202502/P020250207506149604191.pdf"
OIL_DIRECTORY = "https://www.hvust.edu.cn/news/newsDetail/12171"
CITATION_SUFFIX = re.compile(
    r"(?:,\s*)?\b(?:except (?:computer|engines|maids and housekeeping cleaners|special education)|all other)\b",
    re.I,
)
URL_LIKE = re.compile(r"[a-z][a-z0-9+.-]*\s*:|//|www\.", re.I)
TW_LABELS = {k: v for k, v in read_json((CURRENT / "careers/electrical-engineers/zh-TW.json").read_bytes())
             ["display"]["interface"].items() if "電機工程師" not in v}


def citation_label(value):
    trimmed = CITATION_SUFFIX.sub(
        lambda match: " (broad occupational group)" if "all other" in match[0].lower() else "",
        value,
    )
    return re.sub(r" +", " ", trimmed).strip(" ,")


def defaults(obj, fields):
    for key, value in fields.items():
        if key not in obj or obj[key] in ("", None):
            obj[key] = value


def short(value, limit=32):
    s = str(value or "").splitlines()[0].strip() if value else ""
    words = s.split()
    if re.search(r"[\u3400-\u9fff]", s):
        return s if len(s) <= limit else s[:limit].rstrip() + "…"
    return s if len(words) <= 13 else " ".join(words[:13]).rstrip() + "…"


class Repair:
    def __init__(self, page, ai_hosts, ai_urls):
        self.page = page
        self.ai_hosts, self.ai_urls = ai_hosts, ai_urls
        self.locale = page["locale"]
        self.slug = page["subject"]["canonical_slug"]
        self.name = page["subject"]["name"]
        self.display = page["display"]
        self.components = self.display["components"]
        self.old, self.native = Projection(page).build()
        self.items = {item["id"]: item for _, item, _ in public_items(page)}
        self.sources = [
            s for _, item, _ in public_items(page) if item["type"] == "sources"
            for s in item["data"]["entries"] if contract.linked(s.get("url"))
        ]
        self.sources = list({s["url"]: s for s in reversed(self.sources)}.values())[::-1]
        self.work = self.old.get("responsibilities_block") or [page["subject"].get("summary", self.name)]
        self.anchor = short(self.work[0]) or self.name
        self.followups = []

    def local(self, en, tw):
        return en if self.locale == "en" else CN.convert(tw) if self.locale == "zh-CN" else TW.convert(tw)

    def source_label(self, name):
        name = citation_label(name)
        if self.locale == "zh-TW":
            name = TW.convert(name).replace("作業治療師", "職能治療師").replace("校覈", "核對")
        return short(name) or self.local("Source record", "來源紀錄")

    def stale_link(self):
        if (self.slug, self.locale) != ("wellhead-pumpers", "zh-CN"):
            return
        for item in self.items.values():
            if item["type"] != "sources":
                continue
            for row in item["data"]["entries"]:
                if row["url"] not in {OLD_OIL, INTERIM_OIL}:
                    continue
                row.update(url=OIL_DIRECTORY, name="教育部《职业教育专业目录（2021年）》通知及附件（院校转载）",
                           period="2021年职业教育专业目录",
                           details=["中等职业教育专业目录将油气开采列为620404，归入石油与天然气类。"])
                self.followups.append("expired-oil-source")

    def base_path(self, part, slug):
        segment = contract.SEGMENTS[self.locale]
        return f"/{segment}/{part}/{slug}"

    def path_only(self, url):
        parsed = urlsplit(url)
        if parsed.hostname not in {None, "fermatmind.com", "www.fermatmind.com"}:
            return url
        return re.sub(r"^/(?:en|zh|zh-TW)(?=/)", f"/{contract.SEGMENTS[self.locale]}", parsed.path)

    def owning_url(self, node, value):
        if isinstance(node, dict) and "$link" in node:
            entry = next(row for row in self.items[node["$link"]]["data"]["entries"] if row["id"] == node["entry"])
            entry[node["field"]] = value
            return node
        return value

    def cta(self):
        cta = self.components.get("primary_cta")
        expected = f"/{contract.BASE_SEGMENTS[self.locale]}/tests/{contract.CTA_SLUG}"
        if isinstance(cta, dict) and self.old["primary_cta"]["href"] != expected:
            cta["href"] = self.owning_url(cta["href"], expected)

    def source_links(self, original, minimum=4, fit=False):
        result = []
        for row in original:
            cleanup = dict(row)
            href = row.get("href")
            old_url = cleanup.pop("url", None)
            if isinstance(href, str) and not (contract.linked(href) or contract.SOURCE_CONTEXT.fullmatch(href)):
                cleanup["href"] = old_url if contract.linked(old_url) else None
            defaults(cleanup, {"label": self.local("Evidence scope note", "來源適用範圍")})
            for key in ("scope", "usage"):
                if key in cleanup and not cleanup[key]:
                    cleanup.pop(key)
            result.append(cleanup)
        known = {(r.get("href"), r.get("label")) for r in result if isinstance(r.get("href"), str)}
        for s in self.sources:
            if len(result) >= minimum:
                break
            if (s["url"], s["name"]) in known:
                continue
            result.append({"href": s["url"], "label": self.source_label(s["name"]), **({} if fit else {"id": s["id"]})})
        if len(result) < minimum:
            raise ValueError(f"{self.slug}: insufficient approved source rows")
        return result

    def local_source(self, hosts, fallback, fallback_label):
        s = next((s for s in self.sources if contract.linked(s["url"], hosts)
                  and (self.locale != "en" or not urlsplit(s["url"]).hostname.endswith(".cn"))), None)
        return {"label": self.source_label(s["name"]), "href": s["url"]} if s else {"label": fallback_label, "href": fallback}

    def fit(self, x):
        self_x = self.old["personality_fit_block"]
        for raw, val in zip(x["assessments"], self_x["assessments"], strict=True):
            raw["cta_href"] = self.owning_url(raw["cta_href"], self.path_only(val["cta_href"]))
            if not raw["signals"]:
                raw["signals"] = [self.local("Check the explanation against a recent work example.",
                                              "用近期完成的一項工作核對自己的觀察。")]
        if len(x["signals"]) != 3:
            standard_signals = [
                {"id":"fit","tone":"positive","label":self.local("Fit","可能合適"),
                 "body":self.local(f"Test your interest in a routine assignment for {self.name}.", f"拿一件{self.name}的日常交件測試興趣。")},
                {"id":"caution","tone":"caution","label":self.local("Friction","可能卡住"),
                 "body":self.local("Observe how you respond when the same record needs a second review.", "留意同一筆紀錄重查時，工作節奏是否讓你吃力。")},
                {"id":"experiment","tone":"action","label":self.local("Try","怎麼驗證"),
                 "body":self.local("Compare one written handoff with the receiving team's requirements.", "選一份交接紀錄，對照接手團隊實際需要的欄位。")},
            ]
            existing = {row["id"]:row for row in x["signals"]}
            x["signals"] = [existing.get(row["id"], row) for row in standard_signals]
        if not x["directions"]:
            x["directions"] = [{"direction": self.name,
                "fit_signals": self.local("Look at the recurring work and where the team checks it.", "先看最常交接的紀錄與複核安排。"),
                "watchouts": self.local("Ask which tasks require approval before taking ownership.", "接手前先問清楚哪些工作需經主管同意。")}]
        question_pairs = [
            ("Which assignment would I repeat?", "Pick a regular deliverable and repeat it with the same review standard.", "哪件工作值得再做一次？", "選一項日常交件，以同一個複核標準再做一次。"),
            ("How do I handle an interruption?", "Note your last confirmed detail before responding to the interruption.", "途中被打斷怎麼辦？", "先記下最後確認的項目，回來時從該處接續。"),
            ("How do I respond to conflicting inputs?", "Keep both versions and identify the person who can settle the discrepancy.", "兩份資料對不上怎麼辦？", "保留兩個版本，把差異交能確認來源的人處理。"),
            ("Would the work schedule suit me?", "Compare the written shift terms with when you can work reliably.", "班表適合我嗎？", "對照書面班表與自己能穩定到班的時段。"),
            ("Can I set a boundary on requests?", "Describe what you would route to a qualified reviewer.", "我能分清職務權限嗎？", "先說清楚哪些請求要轉由具資格的同事確認。"),
            ("What makes a handoff useful?", "Ask a receiver which detail lets them continue without guessing.", "怎樣的交接有用？", "問接手的人，哪一項資料能讓他接續處理。"),
            ("What if feedback disagrees?", "Ask what criterion each reviewer applied and log what is unresolved.", "兩位複核者意見不同呢？", "問清楚兩邊採用的標準，記下還沒解決的部分。"),
            ("How do I compare employers?", "Request a sample workflow before comparing job titles.", "如何比較兩家雇主？", "先請對方說明一個實際流程，再比對職務名稱。"),
        ]
        for q,a,tq,ta in question_pairs[:max(0, 8-len(x["questions"]))]:
            x["questions"].append({"question":self.local(q,tq),"answer":self.local(a,ta)})
        x["source_links"] = self.source_links(x["source_links"], fit=True)

    def ai(self, x):
        p = self.local
        status = p("Occupation-specific adoption was not measured.", "未查得此職稱的工具採用率。")
        owner = p("Route exceptions to the responsible reviewer.", "把例外交給負責複核的人。")
        scope = p("Compare drafts and confirmed records separately.", "分開比較草稿與已確認的紀錄。")
        baseline = p("Use a non-sensitive record to test the workflow.", "用不含機密的資料試行流程。")
        evidence = [{"来源":self.source_label(s["name"]),"研究对象":self.name,"结论":p("Occupational or regulatory context", "職務或主管機關背景"),
                     "使用限制":status,"链接":s["url"] if self.ai_url(s["url"]) else None} for s in self.sources[:3]]
        generated = {
            "method_cards": [
                {"概念":p("Task support","單項工作"),"含义":p("Identify what the tool actually produces.","先確認工具實際產出的資料。")},
                {"概念":p("Adoption","工具採用"),"含义":status},
                {"概念":p("Accountability","複核責任"),"含义":owner},
            ],
            "task_rows": [
                dict(zip(contract.AI_ROWS["task_rows"][1].split(), (area,self.anchor if idx == 0 else task,change,control)))
                for idx,(area,task,change,control) in enumerate([
                    (p("Input","輸入"),self.name,p("Group material by type.","依資料類型整理。"),p("Confirm the approved input.","核對採用的輸入版本。")),
                    (p("Draft","草稿"),p("Record preparation","準備紀錄"),p("Flag blank fields.","提示空白欄位。"),p("Do not infer missing details.","缺資料則留待核對。")),
                    (p("Review","複核"),p("Version checks","比對版本"),p("Display conflicting entries.","把不一致的項目並列。"),owner),
                    (p("Handoff","交接"),p("Decision record","處理紀錄"),p("Summarize open items.","彙整待處理事項。"),p("Record who has authority to close the item.","標明有權結案的人。")),
                ])
            ],
            "evidence_rows": evidence,
            "difference_rows": [
                {"方向":p("Routine records","例行紀錄"),"AI主要改变":p("Group known fields.","整理已知欄位。"),"仍由人负责":p("Identify the authoritative version.","找出採用的版本。")},
                {"方向":p("Exceptions","例外案件"),"AI主要改变":p("Highlight variations.","標出資料差異。"),"仍由人负责":owner},
            ],
            "responsibility_steps":[{"步骤":p(en,tw),"说明":p(e,t)} for en,tw,e,t in [
                ("Set scope","確認範圍","Use one approved input.", "先同意一份輸入資料。"),
                ("Check","核對","Compare against the source record.","逐項對照原始紀錄。"),
                ("Route","轉交","Identify the person who resolves differences.","交給能處理差異的人。"),
                ("Record","收尾","Save the reviewed version and the decision.","留下複核版本與決定。"),
            ]],
            "risk_rows":[{"风险":p(en,tw),"为什么重要":p(e,t),"控制方式":p(c,d)} for en,tw,e,t,c,d in [
                ("Sensitive input","敏感資料","Access rules still apply.","資料處理仍受授權限制。","Use approved inputs.","採用經同意的資料。"),
                ("Changed versions","版本變更","A draft can replay old details.","舊資料可能進入新草稿。","Check the source timestamp.","比對原始紀錄時間。"),
                ("Authority","權責混淆","A suggestion can be mistaken for approval.","建議容易被誤當成已同意。","Separate reviewing and release.","把複核與發布分開記錄。"),
            ]],
            "action_rows":[{"人群":p(en,tw),"应对重点":p(e,t)} for en,tw,e,t in [
                ("New entrants","入門者","Keep a hand-reviewed work example.","留一份人工複核的作品。"),
                ("Current staff","在職人員","Record why an exception was escalated.","記下例外轉交的原因。"),
                ("Team leads","團隊主管","Agree the acceptance criteria first.","先明定驗收標準。"),
            ]],
            "questions":[{"问题":p(en,tw),"回答":ans,"来源":p("Editorial check","編輯整理"),"链接":None}
                         for en,tw,ans in [
                             ("How do I try a tool?","怎麼試用工具？",baseline),
                             ("Can an output replace sign-off?","工具能代替簽核嗎？",owner),
                             ("How can I compare results?","要怎麼比對結果？",scope),
                         ]],
            "authority_links":[{"来源":s["来源"],"类型":p("Source register","來源紀錄"),"适用范围":status,"链接":s["链接"]} for s in evidence],
        }
        for k,(n,_) in contract.AI_ROWS.items():
            arr = x[k]
            for idx,(row,old) in enumerate(zip(arr,self.old["ai_impact_table"][k])):
                for field in contract.AI_ROWS[k][1].split():
                    if field != "链接" and old.get(field) in (None, ""):
                        row[field] = p("Source detail not stated.", "該筆資料未載此欄。")
                url = old.get("链接")
                if contract.text(url) and URL_LIKE.search(url) and not self.ai_url(url):
                    row["链接"] = None
                if "链接" in row and row["链接"] == "":
                    row["链接"] = None
                if "链接" in row and not row["链接"] and k in {"evidence_rows","questions","authority_links"}:
                    row["链接"] = None
            for candidate in generated[k]:
                if len(arr) >= n:
                    break
                arr.append(candidate)

    def ai_url(self, url):
        return contract.linked(url, self.ai_hosts) or url in self.ai_urls

    def compare(self, x):
        p = self.local
        kept = []
        for raw, old in zip(x["evidence_links"], self.old["adjacent_career_comparison_table"]["evidence_links"]):
            if contract.linked(old.get("href"), contract.ALLOWED_COMPARISON):
                kept.append(raw)
        if not kept:
            kept.append(self.local_source(contract.ALLOWED_COMPARISON, OCCUPATIONAL_REFERENCE,
                        p("O*NET: U.S. occupation classification reference", "O*NET 美國職務分類參考")))
        x["evidence_links"] = kept
        options = [
            (self.name,self.anchor,p("Subject of this page","本頁所述職務"),p("Inspect the actual job description.","查看實際職缺的工作項目。")),
            (p("Support roles","協作職務"),p("Prepare inputs for the responsible person.","先準備承辦人需要的資料。"),p("Check the scope of support.","確認協作範圍。"),p("Ask who makes the final decision.","向雇主確認最後的決定由誰作出。")),
            (p("Review roles","複核職務"),p("Compare outputs with the approval criteria.","把交件與驗收標準逐項對照。"),p("Check responsibility for approving work.","確認誰有權同意交件。"),p("Ask how an exception is recorded.","問例外情形如何留下紀錄。")),
            (p("Coordination roles","協調職務"),p("Track handoffs and outstanding decisions.","追蹤交接及待決事項。"),p("Compare the type of evidence delivered.","比較需要交付哪些佐證。"),p("Look at a typical handoff schedule.","查看日常交接的時間安排。")),
        ]
        for row in options[:max(0,4-len(x["rows"]))]:
            x["rows"].append(dict(zip("职业方向 核心工作与产出 关键区别 更适合什么选择".split(),row)))

    def salary_primary(self, x):
        if self.locale == "en" and x.get("availability") in {"missing","unavailable"}:
            x.update(availability="unavailable",reason_code="source_locale_unavailable")
            return
        p = self.local
        s = x["salary"]
        no = p("No comparable figure is cited here.", "此處未列可直接比較的數字。")
        compare = p("Compare written terms for the same grade and work hours.", "比較書面條件時，先對齊職級與工時。")
        defaults(s, dict(china_name_row=p("Local compensation reference","本地薪資參考"), china_soc_row=self.name,
             china_class_row=p("Check which occupation or industry the table describes.","先核對統計職類與產業範圍。"),
             china_ai_row=p("The survey does not isolate an AI wage effect.","調查未單獨估計 AI 對薪資的影響。"),
             china_open=compare, us_growth=no, us_median=no))
        ref = s["china_ref"]
        if not isinstance(ref,dict) and ref.startswith("https://"):
            s["china_ref"] = p("Local wage authority", "本地薪資公表") + "｜" + ref
        # The renderer requires an accepted methodology link. Keep the local source
        # intact and label the U.S. reference separately, without attributing local figures to it.
        local_ref = self.old["career_snapshot_primary_locale"]["salary"]["china_ref"]
        allowed_ref = any(contract.linked(v,contract.ALLOWED_SALARY)
                          for v in re.findall(r"https://[^\s；;。]+", local_ref))
        s.setdefault("china_intl", self.local("BLS OEWS: U.S. survey methodology only","BLS OEWS：僅作美國調查方法參考")+"｜"+US_METHODOLOGY if not allowed_ref else
                     p("Keep the employer and occupation scope separate.","分開看用人單位與職類範圍。"))
        if "ui" in s:
            labels = [p("Official wage data","官方薪資資料"), p("Compensation questions","詢問計酬"),
                      p("Compare equivalent jobs","比較相近職務"),p("Scope","範圍"),p("Compensation","計酬"),
                      p("Read the survey scope","讀取調查口徑"),p("Factors to check","比較項目"),p("AI and compensation","AI 與計酬")]
            defaults(s["ui"], dict(zip(contract.UI.split(),labels)))
        table = s["china_salary_table"]
        for row in table:
            if "城市/區間" in row:
                row["城市/区间"] = row.pop("城市/區間")
            if "月薪參考" in row:
                row["月薪参考"] = row.pop("月薪參考")
        value_key = next((k for k in ("年工资参照（行业/岗位大类）","年工资参照（行业/人员大类）")
                          if table and all(k in row for row in table)), "月薪参考")
        for label,value in [(p("Scope","統計範圍"),no),(p("Fixed pay","固定月薪"),compare),
                            (p("Variable pay","變動給付"),p("Check when allowances and bonuses are paid.","分開核對津貼與獎金發放條件。"))]:
            if len(table) < 3:
                table.append({"城市/区间":label,value_key:value})
        s.setdefault("china_edu_table", [
            dict(zip("学历段 岗位方向 说明".split(), (p(e,t),self.name,p(d,v)))) for e,t,d,v in [
            ("Entry","入門","Compare the advertised requirements.","核對徵才條件。"),("Training","培訓","Ask who supervises early work.","確認培訓及督導安排。"),
            ("Work scope","工作範圍","Check what the role may approve.","確認可同意的工作範圍。"),("Review","複核","Check what evidence the employer accepts.","詢問雇主採認哪些紀錄。"),
        ]])
        s.setdefault("china_industry_table", [
            {"行业":p(e,t),"需求":p(d,v)} for e,t,d,v in [
                ("Industry","產業","Match the employer's main activity.","對照用人單位的主要業務。"),
                ("Occupation","職類","Check whether the survey uses a broader group.","注意公表是否採較大的職類。"),
                ("Location","地點","Use local job terms for a local comparison.","地點差異回到當地書面條件。"),
            ]])

    def salary_secondary(self, x):
        p = self.local
        no = p("No comparable figure is cited here.", "此處未列可直接比較的數字。")
        benchmark = p("U.S. reference data should retain its stated occupation and period.",
                      "引用美國資料時保留原統計職類與期間。")
        if "availability" in x:
            x.clear()
        defaults(x, dict(growth=no,median=no,heading=p("U.S. wage reference","美國薪資參考"), direct_answer=benchmark,
            wage_heading=p("Wage distribution","工資分布"),interpretation_heading=p("Reading the statistics","如何閱讀統計"),
            industry_heading=p("Industry scope","產業口徑"),factors_heading=p("Comparison factors","比較條件"),
            outlook_heading=p("Employment outlook","就業展望"),boundary=benchmark,
            authority_sources=p("BLS OEWS methodology","BLS OEWS 調查方法")+"｜"+US_METHODOLOGY,
            industry_period=p("See source period","依原表統計期間"),outlook_period=p("See projection period","依原預測期間")))
        existing_table = x.get("bls_table", [])
        for row in existing_table:
            defaults(row, {"说明": p("Read within the original statistical scope.", "依原表統計範圍閱讀。")})
            if row.get("数值") is None:
                row["数值"] = short(row["说明"]) if row["说明"] else no
            if "fact_ref" in row and row["fact_ref"] is None:
                del row["fact_ref"]
        existing_tiers = x.get("wage_tiers", [])
        if not existing_tiers and len(existing_table) not in {1,8}:
            existing_tiers = [
                {"label":row["指标"],"value":row["数值"],"equivalent":p("Retain the published unit","保留原公布單位"),
                 "interpretation":row["说明"]} for row in existing_table[:5]]
        x.setdefault("industry_rows",[])
        # Keep additional original rows and their reference IDs in the tabular
        # comparability area when the widget only supports one five-tier group.
        if len(existing_table) not in {1,8} or len(existing_tiers)>5:
            for row in existing_table:
                x["industry_rows"].append({"industry":row["指标"],"median":row["数值"],"note":row["说明"],
                                           **({"fact_ref":row["fact_ref"]} if "fact_ref" in row else {})})
            existing_table = []
        for row in x["industry_rows"]:
            defaults(row, {"median":no})
            if "fact_ref" in row and row["fact_ref"] is None:
                del row["fact_ref"]
        if not existing_table:
            existing_table=[{"指标":p("Scope of employment statistics","就業統計範圍"),"数值":x["growth"],"说明":benchmark}]
        x["bls_table"]=existing_table
        if len(existing_table)==1 or existing_tiers:
            x["wage_tiers"]=[{k:v for k,v in row.items() if k!="fact_ref"} for row in existing_tiers[:5]] or [
                {"label":p("Occupation-level estimate","職類工資"),"value":x["median"],
                 "equivalent":p("Use the original unit","採用原單位"),"interpretation":benchmark}]
            x.setdefault("wage_column_labels",[p("Percentile","分位"),p("Published value","原表數值"),
                                               p("Original unit","原統計單位"),p("Scope","統計範圍")])
            x.setdefault("industry_value_label", p("Published value","原表數值"))
        x.setdefault("interpretation_rows",[])
        x.setdefault("factor_rows",[])
        for en,tw,ex,tx in [
            ("Occupation","職類","Confirm the definition and any broader grouping.","確認職類定義及是否合併統計。"),
            ("Employment","受僱範圍","Check whether self-employment is included.","確認有沒有納入自營工作者。"),
            ("Survey period","統計期間","Keep the month or year with the quoted figure.","引用時一併列出統計月份或年度。"),
            ("Geography","地區","Compare local figures within the same survey.","區域差異要在同一調查內比較。"),
        ]:
            if len(x["interpretation_rows"]) <4:
                x["interpretation_rows"].append({"question":p(en,tw),"answer":p(ex,tx)})
            if len(x["factor_rows"]) <3:
                x["factor_rows"].append({"factor":p(en,tw),"answer":p(ex,tx)})
            if len(x["industry_rows"]) <4:
                x["industry_rows"].append({"industry":p(en,tw),"median":no,"note":p(ex,tx)})

    def source_card(self, x):
        p = self.local
        if not isinstance(x.get("eeat_signals"), dict):
            x["eeat_signals"] = {}
        defaults(x["eeat_signals"], {
                "author": "FermatMind",
                "source": p("See the dated source register for market and evidence scope.",
                           "統計期間與適用範圍見下方來源。"),
                "updated_at": max((s.get("accessed_at") or "" for s in self.sources), default="") or
                              p("See source dates","日期見各筆來源"),
            })
        for row, old in zip(x.get("secondary_links", []), self.old["source_card"].get("secondary_links", []), strict=True):
            if isinstance(old.get("href"),str):
                row["href"] = self.owning_url(row["href"], self.path_only(old["href"]))

    def journeys(self, key, x):
        p = self.local
        x["source_links"] = self.source_links(x["source_links"])
        if "context_links" in x and not x["context_links"]:
            x["context_links"] = [{"label":p("Qualifications and work boundaries","入行資格與權責"),
                                   "href":"#career-visual-group-entry"}]
        if key == "career_path_block":
            base = [("Observe","觀察","Identify the real deliverable.","先確認要交付的成果。"),
                    ("Assist","協作","Reconcile a record under supervision.","在督導下整理一筆紀錄。"),
                    ("Own a task","獨立處理","Document the assigned scope.","記下分配的職務範圍。"),
                    ("Review","複核","Learn the approval limits.","掌握同意權限。")]
            if not x["competence_ladder"]:
                x["competence_ladder"]=[{"stage":p(en,tw),"description":p(a,b)} for en,tw,a,b in base]
            if not x["tracks"]:
                x["tracks"] = [{"id":f"readiness-{i}","title":p(en,tw),"stages":[
                    {"role":p(e,t), "responsibility":p(a,b), "credentials":p(
                        "Confirm the employer's requirements.","核對用人單位的資格條件。"),
                     "next_moves":p("Ask for a worked example of accepted evidence.","詢問可採認的實例。"),
                     "readiness_evidence":p("A dated record with clear ownership.","能辨認負責人的日期紀錄。")}
                    for e,t,a,b in base]}
                    for i,(en,tw) in enumerate([("Work knowledge","職務知識"),("Documented output","交件紀錄"),
                                               ("Authorization","職務授權")],1)]
            return
        if key == "career_risk_cards":
            flags = [
                ("Scope change","職務變更","An assigned task changes hands.","原分配的工作改由另一人接手。",
                 "No one owns the remaining work.","未處理事項可能沒有負責人。","Assign the open item to a named role.","標明接續處理的職務。"),
                ("Input version","資料版本","Two copies differ.","同一份資料有兩個版本。","A decision may use old material.","可能採用舊資料作決定。","Check which copy the owner approved.","核對資料持有人同意的版本。"),
                ("Timing pressure","時間壓力","Several requests arrive together.","同時遇到多件急件。","Review time may be squeezed.","可能擠壓複核時間。","Arrange the order with the responsible team.","與負責團隊排定次序。"),
                ("Repair decisions","返工決定","The first result is rejected.","初版交付未獲採認。","Unclear feedback prolongs rework.","回饋不明確會延長返工。","Record the acceptance criterion.","記下採認條件。"),
                ("Protected records","受保護的資料","Another team asks for the work files.","其他團隊要求取得工作資料。","Disclosure can exceed the approved purpose.","揭露範圍可能超過同意用途。","Confirm permissions before sharing.","提供資料前先核對權限。"),
                ("Approval boundary","同意權責","A decision needs a qualified reviewer.","決定須由具資格的人複核。","A draft could be treated as approved.","草稿可能被誤當成定案。","Keep the decision with the authorized role.","由獲授權者作成決定。"),
            ]
            for i,(en,tw,e,t,c,d,m,n) in enumerate(flags[:max(0,6-len(x["risks"]))],1):
                x["risks"].append({"id":f"handoff-check-{i}","title":p(en,tw),"scenario":p(e,t),"consequence":p(c,d),
                                   "mitigation":p(m,n),"affected_roles":self.name,
                                   "evidence_refs":[x["source_links"][0]["id"]]})
            return
        no = p("No matching series is cited here.","此處未列同口徑的數列。")
        for row in x["outlook_evidence"]:
            defaults(row, {"horizon":p("See source period","依原表期間"),"value":no})
        for en,tw,e,t in [("Employment","就業人數","Match the occupation in the table.","核對公表的職類名稱。"),
                           ("Growth","成長","Retain the forecast period.","保留預測期間。"),
                           ("Openings","職缺機會","Separate replacement needs from net growth.","將遞補需求與淨增人數分開。")]:
            if len(x["outlook_evidence"]) < 3:
                x["outlook_evidence"].append({"metric":p(en,tw),"value":no,
                    "geography":x.get("geography",p("See original source","依原始來源")),
                    "horizon":p("See source period","依原表期間"),
                    "occupation_scope":self.name,"interpretation":p(e,t),"limitation":no})
        if not x["transitions"]:
            # Local benchmark route, explicitly framed as reading another profile,
            # rather than evidence of a promotion or occupational transition.
            related = {"actuaries":"statisticians","paramedics":"emts-and-paramedics",
                  "emts-and-paramedics":"paramedics","flight-attendants":"airline-pilots-copilots-and-flight-engineers",
                  "airline-pilots-copilots-and-flight-engineers":"flight-attendants",
                  "aircraft-mechanics-and-service-technicians":"aerospace-engineers",
                  "aerospace-engineers":"aircraft-mechanics-and-service-technicians",
                  "interpreters-and-translators":"editors","editors":"interpreters-and-translators",
                  "insurance-underwriters":"actuaries","art-therapists":"occupational-therapists",
                  "logistics-engineers":"supply-chain-managers","market-research-analysts":"statisticians",
                  "producers-and-directors":"film-and-video-editors"}
            slug=related.get(self.slug,"general-office-clerks" if self.slug!="general-office-clerks" else "administrative-services-managers")
            other=self.source_label(read_json((CURRENT/"careers"/slug/f"{self.locale}.json").read_bytes())["subject"]["name"])
            x["transitions"]=[{"target_slug":slug,"target_href":self.base_path("career/jobs",slug),"target_title":other,
                "transition_distance":p("Reference for comparing work","工作比較參考"),
                "shared_capabilities":p("Compare documentation and handoff requirements.","比較紀錄與交接要求。"),
                "capability_gaps":p("Check the other role's tasks and any statutory qualification separately.",
                                   "另查對方的工作項目與法定資格。")}]

    def quick(self, x):
        for row in x["items"]:
            if not row["table"]["rows"]:
                row["table"]["rows"].append({
                    "label":self.local("Occupation","職類"),"value":self.name,
                    "alternate_value":None,"secondary_value":None,
                })
            for cell in row["table"]["rows"]:
                defaults(cell, {"value":self.local("No figure in this row.","此列未附數值。")})
                for key in ("alternate_value","secondary_value"):
                    if cell.get(key) == "":
                        cell[key] = None

    def move_answer(self):
        answers = [(b, item, row) for b,item,_ in public_items(self.page) if item["type"]=="faq"
                   for row in item["data"]["entries"] if WORKED_ANSWER.search(row["question"])]
        if not answers:
            return
        if len(answers)!=1:
            raise ValueError(f"{self.slug}: expected one worked answer")
        _,faq,row=answers[0]
        identifier=self.slug+contract.WORKED_NOTICE_SUFFIX
        orig=next(entry for entry in self.display["native_items"] if entry["id"].endswith(".entry-work-sample-data.1"))
        notice={"id":identifier,"copy_key":NOTICE,"type":"notice","title":row["question"],
                "availability":"available","visibility":"public","data":{"paragraphs":[row["answer"]]},
                **{k:row[k] for k in ("source_refs","fact_refs") if row.get(k)}}
        path=next(b for b in self.page["blocks"] if b["id"]=="path")
        position=next(i for i,it in enumerate(path["items"]) if it["id"]==orig["id"])
        path["items"].insert(position+1,notice)
        self.display["native_items"].insert(self.display["native_items"].index(orig)+1,{**orig,"id":identifier,"type":"notice"})
        index=faq["data"]["entries"].index(row)
        faq["data"]["entries"].remove(row)
        for slot in self.page["authoring_structure"]["slots"].values():
            ref=slot.get("ref")
            if not ref or ref.get("id")!=faq["id"] or ref["path"][:2]!=["data","entries"]:
                continue
            part=ref["path"][2]
            field=ref["path"][-1]
            if part==index or part=={"id":row["id"]}:
                if field in {"question","answer"}:
                    ref.update(id=identifier,path=["title"] if field=="question" else ["data","paragraphs",0])
                else:
                    slot.update(status="unfilled",ref=None)
            elif isinstance(part,int) and part>index:
                ref["path"][2]-=1
        self.followups.append("worked-answer-notice")

    def preserve_links(self, before):
        after = {(node["$link"],node["entry"],node["field"]) for node in references(self.display) if "$link" in node}
        missing = [node for node in references(before) if "$link" in node and
                   (node["$link"],node["entry"],node["field"]) not in after]
        if missing:
            note = self.components["source_card"]["note"]
            self.components["source_card"]["note"] = {"$join":[note,*missing],"separator":"\n"}

    def bind_registered_blocks(self):
        # A valid display component alone does not mount its registered renderer.
        # The frontend also requires a public item with the matching block copy key.
        for index, component in contract.unmounted_blocks(self.page, self.components):
            block = self.page["blocks"][index]
            copy_key = contract.registered_copy_key(component)
            target = self.components[component]
            value = target["heading"]
            if not isinstance(value, str) or not value.strip():
                raise ValueError(f"{component}.heading: expected a heading to bind")
            identifier = f"{self.slug}.display.{component}.registered"
            block["items"].append({
                "id": identifier, "copy_key": copy_key, "type": "prose",
                "availability": "available", "data": {"paragraphs": [value]},
            })
            block["availability"] = "available"
            target["heading"] = {"$item": identifier, "block": block["id"], "copy_key": copy_key, "type": "prose"}
            self.followups.append(f"registered:{block['id']}")

    def presentation_labels(self):
        if self.locale == "en":
            for key, component in self.components.items():
                if not isinstance(component, dict) or not isinstance(component.get("source_links"), list):
                    continue
                projected = self.old.get(key)
                if not isinstance(projected, dict):
                    continue
                for raw, resolved in zip(component["source_links"], projected.get("source_links", [])):
                    if not isinstance(raw, dict) or not isinstance(resolved, dict):
                        continue
                    title = raw.get("label")
                    if isinstance(title, dict) and "$source" in title:
                        title = resolved.get("label")
                    if isinstance(title, str) and CITATION_SUFFIX.search(title):
                        raw["label"] = citation_label(title)
            ai = self.components.get("ai_impact_table", {})
            for group in ("evidence_rows", "authority_links"):
                for row in ai.get(group, []):
                    label = row.get("来源")
                    if isinstance(label, str):
                        row["来源"] = citation_label(label)
        if self.locale == "zh-TW":
            labels = self.display.setdefault("interface", {})
            defaults(labels, {
                "interface.quick_decision.suit_heading": f"哪些工作習慣有助於適應{self.name}？",
                "interface.quick_decision.caution_heading": f"哪些情況應慎重考慮{self.name}？",
                "interface.quick_decision.experiment_heading": "如何用紙筆試做評估自己是否適合？",
            })
            defaults(self.components["career_path_block"], {"entry_heading": "入門前如何觀察與準備"})
            for key, value in TW_LABELS.items():
                labels.setdefault(key, value)

    def apply(self, errors):
        before=deepcopy(self.display)
        self.stale_link()
        self.cta()
        todo=dict.fromkeys(k.removeprefix("original.") for k in errors)
        for key in self.display["component_order"]:
            if key not in todo:
                continue
            x=self.components[key]
            if key=="personality_fit_block":
                self.fit(x)
            elif key=="ai_impact_table":
                self.ai(x)
            elif key=="career_snapshot_primary_locale":
                self.salary_primary(x)
            elif key=="career_snapshot_secondary_locale":
                self.salary_secondary(x)
            elif key=="adjacent_career_comparison_table":
                self.compare(x)
            elif key=="source_card":
                self.source_card(x)
            elif key in {"career_path_block","career_risk_cards","market_signal_card"}:
                self.journeys(key,x)
            elif key=="career_ai_description_block":
                if "body" in x and isinstance(self.old[key]["body"],str):
                    x["body"] = [x["body"]]
                defaults(x,{"heading":self.local("AI and daily work","工具與日常工作"),
                    "body":[self.local(f"For {self.name}, check whether outputs still carry the approved record version.",
                                       f"{self.name}交件前要核對輸出是否仍標示採用的紀錄版本。")]})
            elif key=="career_quick_answers_block":
                self.quick(x)
        self.bind_registered_blocks()
        self.presentation_labels()
        self.preserve_links(before)
        self.move_answer()
        self.followups.extend(todo)
        return self.page


def references(value):
    if isinstance(value,dict):
        if set(value)&{"$link","$item"}:
            yield value
        else:
            for child in value.values():
                yield from references(child)
    elif isinstance(value,list):
        for child in value:
            yield from references(child)


def ai_policy(web_root):
    path = web_root/"components/career/display/CareerDossierAiImpact.tsx"
    text = path.read_text()
    return [set(re.findall(r'"([^"]+)"', re.search(r"const "+name+r" = new Set\(\[(.*?)\]\);",text,re.S)[1]))
            for name in ("AI_SOURCE_HOSTS","AI_SOURCE_URLS")]


def selection(path):
    rows={}
    for line in path.read_text().splitlines():
        sha, relative = line.split("  ",1)
        rel=Path(relative)
        if len(rel.parts)!=3 or rel.parts[0]!="careers" or rel.suffix!=".json" or rel.stem not in contract.SEGMENTS or not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*",rel.parts[1]) or not re.fullmatch(r"[0-9a-f]{64}",sha):
            raise ValueError(f"Invalid cohort entry: {line}")
        if relative in rows:
            raise ValueError(f"Duplicate cohort page: {relative}")
        rows[relative]=sha
    if Counter(Path(r).stem for r in rows)!={"zh-CN":70,"en":70,"zh-TW":30}:
        raise ValueError("Expected exact 170-page Wave 1 locale totals")
    return rows


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cohort",required=True,type=Path)
    parser.add_argument("--web-root",required=True,type=Path)
    parser.add_argument("--out",required=True,type=Path)
    parser.add_argument("--oracle",required=True,type=Path,help="JSONL results from the read-only production contract oracle")
    args=parser.parse_args()
    if args.out.resolve().is_relative_to(CURRENT):
        raise ValueError("Use a detached candidate directory")
    errors={(slug,loc):err for slug,loc,err in map(json.loads,args.oracle.read_text().splitlines())}
    policy=ai_policy(args.web_root)
    if policy != [set(contract.AI_LINK_POLICY[key]) for key in ("hosts", "urls")]:
        raise ValueError("Update the checked-in AI link policy from the selected renderer before repairing")

    def build(entry):
        relative,sha=entry
        disk=CURRENT/relative
        raw=disk.read_bytes()
        if digest(raw)!=sha:
            raise ValueError(f"{disk}: Current changed after the cohort was frozen")
        page=read_json(raw)
        protected = {key: deepcopy(page[key]) for key in ("seo", "hero", "subject", "fact_register")}
        repair=Repair(page,*policy)
        output=repair.apply(errors[repair.slug,repair.locale])
        try:
            encoded=runtime("repair-render",raw=encode(output))
        except ValueError as error:
            raise ValueError(f"{relative}: {error}") from error
        new=read_json(encoded)
        if any(new[key] != value for key, value in protected.items()):
            raise ValueError(f"{relative}: identity, SEO, hero or original facts changed")
        destination = args.out/relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        atomic_output(destination,encoded)
        return {"slug":repair.slug,"locale":repair.locale,"path":relative,
                "sha256":digest(encoded),"repairs":repair.followups}

    failures = []
    with ThreadPoolExecutor(max_workers=6) as pool:
        jobs = {relative: pool.submit(build, (relative,sha)) for relative,sha in selection(args.cohort).items()}
        entries = []
        for relative, job in jobs.items():
            try:
                entries.append(job.result())
            except (KeyError, TypeError, ValueError) as error:
                failures.append(f"{relative}: {error}")
    if failures:
        raise ValueError("\n".join(failures))
    atomic_output(args.out/"repairs.json", encode(entries))
    atomic_output(args.out/"selection.json",encode({
        "schema_version":"career.content_v3_batch_selection.v1",
        "pages":[{k:v for k,v in row.items() if k!="repairs"} for row in entries]}))
    print(json.dumps({"pages":len(entries),"repairs":Counter(k for r in entries for k in r["repairs"])}))


if __name__=="__main__":
    main()
