# CLINICAL_COMBO_68 执行计划补丁 v2（Business Logic Constraints）

## Summary
本补丁只做一件事：把 `Comprehensive Depression and Anxiety Inventory（抑郁焦虑综合测评【学术专业版】）` 的算法边界写死，避免在 `ClinicalCombo68ScorerV1.php` 和 `policy.json` 生成时出现映射漂移、Q68 误加总、或风控条件错判。

## A. Changed Files List
Added:
1. 无（本轮为计划补丁，不改仓库业务代码）。

Modified:
1. PR-01 说明（`policy.json` 固定参数）。
2. PR-02 说明（双轨计分、Q68 独立、矛盾点公式）。
3. PR-03 说明（伦理付费墙物理边界）。
4. Assumptions & Defaults（算法默认值收敛）。

## B. Copy-paste Blocks（插入到原计划对应位置）

### 1) 插入到 PR-02 的 `Step 1：字母→分数（按 options_set + reverse）` 下方
```text
[Business Constraint: Dual-Track Mapping]
- 模块 1、2、4（Q1-30, Q58-68）：默认映射 A=0,B=1,C=2,D=3,E=4。
- 反向题仅 Q18/Q19：按 0..4 体系反向，A=4,B=3,C=2,D=1,E=0。
- 模块 3（Q31-57，完美主义）必须映射 A=1,B=2,C=3,D=4,E=5。
- 严禁将 Q31-57 按 0..4 计分（否则 Raw_Perf 会系统性少 27 分）。
```

### 2) 插入到 PR-02 的 `Step 3：六大维度 Raw` 下方
```text
[Business Constraint: Q68 Boundary]
- Raw_OCD = Sum(Q58..Q67)，范围 0-40。
- Q68 不计入 Raw_OCD，也不计入任一六维 raw 分。
- Q68 仅用于：
  1) crisis_alert 触发条件（Q68>=3）；
  2) 全局功能受损标签/文案解释。
```

### 3) 插入到 PR-02 的 `Step 2：质量与效度（quality）` 下方
```text
[Business Constraint: Inconsistency Pair Formula]
- inconsistency_pair_q17_q18 触发条件：
  IF (x17 >= 3 AND x18_reversed >= 3) THEN inconsistency_flag=true
- 注意 x18_reversed 指 Q18 反向计分后的数值。
```

### 4) 插入到 PR-03 的 `合规模块内容策略` 下方
```text
[Business Constraint: Ethical Paywall Physical Boundary]
- Free Core（永远免费）：
  depression.level, anxiety.level, ocd.level, stress.level
- Paid Blocks（允许付费）：
  resilience 深度解读、perfectionism + 5 子量表溯源、CBT action plan
- crisis_alert=true 时强制：
  offers=[]，且 Free Core 信息仍完整返回。
```

### 5) 插入到 PR-01 的 `policy.json` 说明（固定参数，禁止改写）
```json
"scoring_rules": {
  "mu_sigma": {
    "depression": { "mu": 10.5, "sigma": 6.2 },
    "anxiety": { "mu": 8.2, "sigma": 5.5 },
    "stress": { "mu": 9.0, "sigma": 3.5 },
    "resilience": { "mu": 24.5, "sigma": 6.8 },
    "perfectionism": { "mu": 82.0, "sigma": 18.5 },
    "ocd": { "mu": 12.0, "sigma": 8.0 }
  },
  "t_score_clamp": { "min": 20, "max": 80 },
  "buckets": {
    "depression": [
      { "max_t": 59, "level": "normal" },
      { "max_t": 64, "level": "mild" },
      { "max_t": 69, "level": "moderate" },
      { "min_t": 70, "level": "severe" }
    ]
  }
}
```

## Public APIs / Interfaces / Types
1. 不新增新接口。
2. 仅收紧 `CLINICAL_COMBO_68` 的评分与报告业务约束，确保与 `MBTI`、`BIG5_OCEAN` 同级且独立。

## Test Cases & Scenarios（新增必测）
1. Q31-57 映射必须是 1..5（输入 A 时 raw=1，输入 E 时 raw=5）。
2. Raw_OCD 仅累计 Q58-67，Q68 不影响 raw。
3. inconsistency_flag 仅在 `(Q17>=3 && reversed(Q18)>=3)` 命中。
4. crisis_alert 在 `Q9>=2` 或 `Q68>=3` 任一命中。
5. locked 报告不泄露 paid blocks；crisis 时 offers 为空且 free core 保留。

## C. Minimal Acceptance Commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan migrate --force
curl -sS "http://127.0.0.1:8000/api/v0.3/scales/CLINICAL_COMBO_68/questions?locale=zh-CN"
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
```

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter ClinicalComboReverseScoringTest
php artisan test --filter ClinicalComboScoreSmokeTest
php artisan test --filter ClinicalComboCrisisGateTest
php artisan test --filter ClinicalComboReportPaywallTest
```

## Assumptions & Defaults
1. `scale_code` 保持 `CLINICAL_COMBO_68`。
2. 质量等级仍使用 `A/B/C/D`。
3. 模块三计分体系固定 `1..5`，不可随实现改动。
4. Q68 作为全局风险探针，不参与任一维度 raw 求和。
