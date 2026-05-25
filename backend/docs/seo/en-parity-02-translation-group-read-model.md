# EN-PARITY-02 Translation Group Schema And Read Model

## Decision

EN-PARITY-02 adds a backend-owned bilingual parity read model for EN/ZH counterpart discovery. It does not generate English prose, publish content, mutate production CMS, run production migrations, deploy, or submit URLs.

The read model lives at `App\Services\SeoIntel\TranslationParity\TranslationParityMatrixReadModel` and emits a matrix with:

- `entity_type`
- `entity_key`
- `translation_group_id` or stable equivalent
- `locale`
- `slug`
- `canonical_url`
- `publication_state`
- `source_of_truth`
- `counterpart_locale`
- `counterpart_canonical_url`
- `counterpart_status`

## Authority Rules

Backend/CMS/URL Truth remains the authority. fap-web fallback, sitemap, llms, and runtime observation are not accepted as content or counterpart authority.

Counterpart lookup preference:

1. `translation_group_id`
2. `entity_key`
3. Stable backend business keys such as `guide_code`, `topic_code`, `surface_key`, or `scale_code + canonical_type_code`
4. Slug only as a transitional last-resort signal

## Entity Coverage

The read model covers:

- `content_pages`
- `articles`
- `career_guides`
- `research_reports`
- `topics`
- `personality`
- `tests`
- `landing_surfaces / page_blocks`
- `media_assets`

Missing counterparts are explicit matrix findings. They are not hidden behind frontend fallback and are not forced to `200`.

## Content Generation Boundary

The remaining EN-PARITY content tasks must respect the additional control rule:

- EN-PARITY-03 content pages: contract/import package first unless page authority already exists.
- EN-PARITY-04 articles: no mass generation of all missing English articles in one PR.
- EN-PARITY-05 career guides: no mass generation of all English career guide details in one PR.
- Drafts, placeholders, fallback-only pages, noindex/private pages, 404s, soft 404s, and missing-authority pages must not enter sitemap or llms.

## Validation

Focused validation:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=EnParity02 --no-ansi
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan migrate --pretend --no-ansi --force
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd /Users/rainie/Desktop/GitHub/fap-api
python3 -m json.tool backend/docs/seo/generated/en-parity-02-translation-group-read-model.v1.json >/dev/null
python3 -c 'import yaml, json; yaml.safe_load(open("docs/codex/pr-train.yaml")); json.load(open("docs/codex/pr-train-state.json")); print("manifest/state parse ok")'
git diff --check
```

## Next Task

EN-PARITY-03 content pages EN/ZH parity.
