## Summary（改了什么）
- [ ] 内容补库（新增 cards/reads）
- [ ] 文案修订（仅改 title/desc/body 等，不改 id/结构）
- [ ] 规则调整（priority/weight/quota/fill_order 等）
- [ ] Overrides 热修（必须含 expires_at）

简述本次改动（1-3 句话）：
- 

---

## Scope（影响范围）
**Pack**
- REGION:
- LOCALE:
- PACK_ID:
- PACK_DIR:

**Buckets / Sections**
- [ ] highlights（strengths / blindspots / actions）
- [ ] reads（by_type / by_role / by_strategy / by_top_axis / fallback）
- 具体影响：
  - 

---

## Inventory / Metrics（增量统计）
> 粘贴本机脚本输出，便于 reviewer 快速核对。

### Reads counts
（示例）
- reads.total_unique: `___ -> ___`
- reads.fallback: `___ -> ___`
- by_role: `NT=__ NF=__ SJ=__ SP=__`
- by_strategy: `EA=__ ET=__ IA=__ IT=__`
- by_top_axis: `axis:EI:E=__ ...`
- by_type: `min=__ max=__`（或确认全为 2）

### Highlights coverage
（示例）
- templates coverage: `AT.A=true ...`
- highlights count (sample run): `__`（如脚本输出有）

---

## Validation（必须全过）
> 贴命令 + 关键输出（PASS 行即可）。

- [ ] `bash backend/scripts/mvp_check.sh "$PACK_DIR"` ✅
- [ ] `bash backend/scripts/ci_verify_mbti.sh` ✅
- [ ] `php artisan fap:self-check` ✅（若单独跑了）

粘贴关键 PASS 输出：
- 

---

## Dedupe / Contract safety（安全约束自检）
- [ ] id 未改名（只新增或下线；不重命名已上线 id）
- [ ] canonical_id / canonical_url / url 未引入明显重复（soft dedupe 仍可工作）
- [ ] tags 前缀符合规范（type/role/strategy/axis/topic/stage/channel）
- [ ] 不引入 silent fallback（若 fallback 增加/触发，需解释原因）

---

## Overrides（仅当本 PR 含 overrides 时填写）
- reason：
- scope：
- priority：
- expires_at（必填）：
- rollback plan（revert commit / 删除 override 文件）：

---

## Reviewer checklist（给 reviewer 的检查点）
- [ ] 只改 JSON（无 backend 逻辑改动）/ 或已在 Summary 说明原因
- [ ] CI 全绿
- [ ] 统计增量合理（库存线/缺口表不倒退）
- [ ] 文案无敏感/绝对化诊断/人身攻击

---

## Notes（可选）
- 风险点：
- 后续补库计划：