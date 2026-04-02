# MBTI Desktop Clone `profile_identity` Republish Runbook

适用场景：
- 线上 `GET /api/v0.5/personality/{type}/desktop-clone` 的 `content.hero` 只有 `summary`
- `content.hero.profile_identity` 在线上缺失
- 前端顶部身份模块已经接到 `profileIdentity`，但页面仍回退成旧 headline 样式

本 Runbook 只覆盖一种故障：
- canonical baseline 已有 `profile_identity`
- 本地 import 后 owner 有 `profile_identity`
- 本地 API 有 `profile_identity`
- 只有 live API 缺失 `profile_identity`

这类问题的根因不是应用代码，而是 production published clone content 未重新导入或未重新发布。

---

## 0. 判定门

先按固定顺序确认，不要先改代码：

1. baseline
2. import
3. owner
4. local API
5. live API

只有当「本地 import 后，本地 owner 或本地 API 仍然缺失 `content.hero.profile_identity`」时，才进入代码修复。

如果结果是：
- baseline 有
- import 后 owner 有
- local API 有
- live API 没有

则本次处理必须走 production republish，不发代码 hotfix。

---

## 1. 故障特征

典型 live 响应：

```json
{
  "full_code": "ENFJ-T",
  "content": {
    "hero": {
      "summary": "..."
    }
  },
  "_meta": {
    "authority_source": "personality_profile_variant_clone_contents",
    "route_mode": "full_code_exact",
    "public_route_type": "32-type"
  }
}
```

异常点：
- `content.hero.profile_identity = null`
- `_meta.authority_source` 仍然指向 `personality_profile_variant_clone_contents`
- `route_mode = full_code_exact`
- `public_route_type = 32-type`

这意味着：
- route 正常
- public API 走的是正确 owner
- 但该 owner 的 published runtime data 仍是旧版本

---

## 2. Baseline 真值核对

先确认 committed desktop clone baseline 已有目标字段：

```bash
cd /Users/rainie/Desktop/GitHub/fap-api
python3 - <<'PY'
import json
path='content_baselines/personality_clone/mbti_desktop_clone.zh-CN.json'
with open(path,'r',encoding='utf-8') as f:
    data=json.load(f)
for code in ['ENFJ-T', 'INTP-T']:
    row=next((r for r in data.get('variants', []) if r.get('full_code') == code), None)
    print(code, json.dumps((((row or {}).get('content_json') or {}).get('hero') or {}).get('profile_identity'), ensure_ascii=False))
PY
```

预期：
- `ENFJ-T` 能看到 `code / name / nickname / rarity / keywords`
- `INTP-T` 能看到 `code / name / nickname / rarity / keywords`

---

## 3. 本地 import / owner / API 自检

### 3.1 route

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list | rg 'desktop-clone|personality/.*/desktop-clone' -n -S
```

### 3.2 migration

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan migrate
```

### 3.3 import

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan personality:import-local-baseline --locale=zh-CN --status=published --upsert
php artisan personality:import-desktop-clone-baseline --locale=zh-CN --status=published --upsert
```

### 3.4 owner

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan tinker --execute="\$enfj = App\\Models\\PersonalityProfileVariantCloneContent::query()->where('status', App\\Models\\PersonalityProfileVariantCloneContent::STATUS_PUBLISHED)->whereHas('variant', fn(\$q) => \$q->where('runtime_type_code', 'ENFJ-T')->whereHas('profile', fn(\$qp) => \$qp->withoutGlobalScopes()->where('locale', 'zh-CN')->where('org_id', 0)->where('scale_code', App\\Models\\PersonalityProfile::SCALE_CODE_MBTI)))->first(); dump(data_get(optional(\$enfj)->content_json, 'hero.profile_identity'));"
```

### 3.5 local API

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan serve --host=127.0.0.1 --port=8011
```

```bash
curl -s 'http://127.0.0.1:8011/api/v0.5/personality/enfj-t/desktop-clone?locale=zh-CN&org_id=0&scale_code=MBTI' | jq '{full_code:.full_code, hero:.content.hero, meta:._meta}'
curl -s 'http://127.0.0.1:8011/api/v0.5/personality/intp-t/desktop-clone?locale=zh-CN&org_id=0&scale_code=MBTI' | jq '{full_code:.full_code, hero:.content.hero, meta:._meta}'
```

判定条件：
- 如果 owner 和 local API 都有 `profile_identity`，不要改代码，直接走 production republish
- 如果 owner 或 local API 仍缺字段，再回到 import / serializer 层修代码

---

## 4. Production Republish

### 4.1 生产 SSH 入口

当前实际验证过的生产执行节点：

```bash
ssh 122.152.221.126
```

该节点连接生产库：

```bash
grep -E '^(APP_ENV|APP_URL|DB_CONNECTION|DB_HOST|DB_DATABASE)=' /var/www/fap-api/current/backend/.env
```

参考输出：

```text
APP_ENV=production
APP_URL=https://ops.fermatmind.com
DB_CONNECTION=mysql
DB_HOST=10.20.1.13
DB_DATABASE=fap_prod
```

注意：
- `api.fermatmind.com` 当前 DNS 可能不等于 deploy workflow 记录的机器 IP
- 不要先假设接流量节点和执行节点是同一台
- 以 live curl 是否恢复为最终判断

### 4.2 执行 republish/import

```bash
cd /var/www/fap-api/current/backend
php artisan personality:import-local-baseline --locale=zh-CN --status=published --upsert
php artisan personality:import-desktop-clone-baseline --locale=zh-CN --status=published --upsert
php artisan optimize:clear
```

预期：
- `profiles_found=16`
- `variants_found=32`
- `rows_found=32`
- `import complete`

### 4.3 live API 验证

```bash
curl -s 'https://api.fermatmind.com/api/v0.5/personality/enfj-t/desktop-clone?locale=zh-CN&org_id=0&scale_code=MBTI' | jq '{full_code:.full_code, hero:.content.hero, meta:._meta}'
```

```bash
curl -s 'https://api.fermatmind.com/api/v0.5/personality/intp-t/desktop-clone?locale=zh-CN&org_id=0&scale_code=MBTI' | jq '{full_code:.full_code, hero:.content.hero, meta:._meta}'
```

恢复标准：
- `content.hero.summary` 仍存在
- `content.hero.profile_identity.code` 存在
- `content.hero.profile_identity.name` 存在
- `content.hero.profile_identity.nickname` 存在
- `content.hero.profile_identity.rarity` 存在
- `content.hero.profile_identity.keywords` 为 6 个字符串

---

## 5. 浏览器级验收

当 live API 恢复后，前端已上线的 hero / rail `profileIdentity` 接线会自动生效，不需要再发 `fap-web`。

浏览器验收标准：
- hero 左侧显示：
  - `code`
  - `name · nickname`
  - `稀有度：...`
- rail 顶部身份卡显示：
  - `code`
  - `name · nickname`
  - `稀有度：...`

如果页面仍显示旧样式，优先检查：
1. 页面是否是“报告生成中”壳页，而不是真正 rich report
2. 当前浏览器是否具备 owner token / anon owner context
3. 是否只命中了旧缓存页面

---

## 6. Non-goals

这类事件处理不应该做：
- 改 `fap-web`
- 在 serializer 里临时拼 `profile_identity`
- 从旧 headline / displayName / summary 反推身份字段
- 新增第二 owner
- 修改 payment / entitlement / traits / chapter render

---

## 7. 事件结论模板

处理完成后建议记录：

```text
Incident: MBTI desktop clone hero profile_identity missing on live API
Root cause: production published clone content stale; runtime data not republished
Code changes: none
Production actions:
- personality:import-local-baseline --locale=zh-CN --status=published --upsert
- personality:import-desktop-clone-baseline --locale=zh-CN --status=published --upsert
- php artisan optimize:clear
Validation:
- live API ENFJ-T restored
- live API INTP-T restored
- frontend hero / rail visible page auto-recovered
```
