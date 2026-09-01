# Deprecated public personality packages

`backend/content_assets/personality_public/current/manifest.json` is the only
runtime and publication authority for `org_id=0` public personality pages.

The legacy seed, overlay, approval, authorization, and repair JSON files that
remain in the parent directory are immutable historical evidence. Their paths
and bytes stay unchanged because historical receipts bind them by path and
hash. They are not Current candidates and their write commands are read-only
outside the test environment.

`big_five_v1_seed_facet_detail_only.json` was unreferenced and is archived in
this directory. `mbti_zh_result_authority_release.v1.json` is intentionally
excluded because it remains the private MBTI result authority.
