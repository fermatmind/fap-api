# Big Five CMS Import Dry-run 07

Source package: `generated/big-five-content-polish/cms-import-draft.polished.json`
Dry-run report: `generated/big-five-cms-import-dryrun/dryrun_report.json`

## Verdict

- Status: pass
- CMS write performed: no
- Production import performed: no
- Publish/index/sitemap/llms release performed: no

## Package Summary

- Rows: 42 / expected 42
- Locale counts: {'en-US': 6, 'zh-CN': 36}
- Content type counts: {'combination_page': 6, 'cross_reading_page': 5, 'hub_page': 2, 'result_review_page': 4, 'trait_page': 10, 'trait_range_page': 15}
- Short Big Five route residue: 0
- FAQ structured source: faq
- FAQ body section rows: 42
- FAQ dedupe policy: faq_field_is_the_only_structured_faq_source; faq-like body_sections are excluded from render_preview_sections to prevent duplicate FAQ rendering.

## Contract Checks

- ok: pass
- dry_run_only: pass
- writes_committed_false: pass
- cms_write_attempted_false: pass
- publish_attempted_false: pass
- index_attempted_false: pass
- sitemap_llms_release_attempted_false: pass
- row_count_42: pass
- expected_row_count_42: pass
- row_count_matches_expected: pass
- old_short_big_five_route_residue_count_0: pass
- faq_field_structured_source: pass
- faq_body_section_rows_42: pass
- errors_empty: pass

## Row Checks

- all_rows_noindex: pass
- all_rows_not_public: pass
- all_rows_not_index_eligible: pass
- all_rows_not_sitemap_eligible: pass
- all_rows_not_llms_eligible: pass
- all_rows_render_preview_excludes_faq_section: pass
- all_rows_faq_count_at_least_5: pass

## Boundary

- This report is no-write dry-run evidence only.
- It does not publish CMS content and does not enable sitemap, llms, JSON-LD runtime, indexability, search submission, staging deploy, or production deploy.
