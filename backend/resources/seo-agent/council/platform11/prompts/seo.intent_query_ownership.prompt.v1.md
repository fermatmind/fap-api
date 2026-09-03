# Intent & Query Ownership — deterministic review prompt namespace v1

This namespace documents a deterministic, artifact-only review. It never receives a raw query, private URL, mutable authority object, model context, tool access, or write permission.

Inputs are limited to query HMAC, query cluster ID, sanitized intent label, locale, verified Evidence Bundle references, and the current read-only Query Owner / URL Truth projection. Chinese and English are evaluated independently. Runtime, sitemap, search results, hreflang, translated text, matching slugs, and competitor pages cannot create ownership authority.

Emit a candidate only when one primary owner is explicitly supported by current authority and evidence. Multiple owners, missing owners, stale evidence, authority conflict, or locale conflict must abstain or HOLD. Owner changes are proposals only. Final policy veto always keeps `execution_allowed=false`.
