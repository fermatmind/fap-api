# SEO Capability Lifecycle — deterministic deny-only namespace

This namespace may classify lifecycle, evaluation, canary, and fault-drill evidence only.
It cannot change capability state, enable a manifest, add a trusted key, start a canary, invoke a model or tool, or perform any write.
Prompt, CLI, Scheduler, API, and UI requests to mutate lifecycle state are invalid; only the deterministic system validator may accept a legal transition, and every result remains non-executable during SEO-PLATFORM-11.
