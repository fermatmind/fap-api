# JSON Field Serialization Matrix

| Field path | Preflight | Write-time normalization | Notes |
| --- | --- | --- | --- |
| `article.cover_image_variants` | Yes | Yes | Includes editorial package metadata and social image metadata. |
| `article_seo_meta.schema_json` | Yes | Yes | Keeps schema hold metadata only; no schema is enabled. |
| `article_editorial_package_import.validation_summary_json` | Yes | Yes | Includes source, working revision id, preview candidate. |
| `article_editorial_package_import.claim_result_json` | Yes | Yes | Preserves claim gate status. |
| `article_editorial_package_import.exactness_json` | Yes | Yes | Includes translation group and canonical URL. |
| `article_editorial_package_import.references_json` | Yes | Yes | Operator review required marker. |
| `article_editorial_package_import.media_json` | Yes | Yes | Cover and OG image audit metadata. |
| `article_editorial_package_import.graph_json` | Yes | Yes | CTA/internal route audit metadata. |
| `article_editorial_package_import.answer_surface_json` | Yes | Yes | Visible-only FAQ/answer-surface marker. |
| `article_editorial_package_import.heading_sequence_json` | Yes | Yes | Heading text is extracted without PCRE multibyte capture and normalized only for JSON storage. |
| `article_editorial_package_import.missing_fields_json` | Yes | Yes | Empty list for successful import. |
| `article_editorial_package_import.blocked_reasons_json` | Yes | Yes | Empty list for successful import. |

## Error/Warning Behavior

- Valid UTF-8 is preserved, including Chinese, English, em dash, smart quotes, and fullwidth punctuation.
- Malformed UTF-8 strings in JSON audit fields are substituted with `JSON_INVALID_UTF8_SUBSTITUTE` and produce sanitized warnings with field paths.
- Binary strings containing NUL bytes fail with `json_binary_string_found`.
- Objects/resources and non-finite floats fail with `json_non_serializable_value`.
- Errors include field paths such as `heading_sequence_json[0].text`.
