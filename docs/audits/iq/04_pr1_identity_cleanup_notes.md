# PR1 Identity Cleanup Notes

## Scope

- canonicalized IQ public metadata toward `IQ_INTELLIGENCE_QUOTIENT`
- kept `IQ_RAVEN` as accepted legacy alias input
- preserved legacy 30-item demo content as `legacy_demo`
- did not modify `questions.json`
- did not modify `scoring_spec.json`
- did not modify SVG payloads
- did not implement scoring, report builder, or commerce unlock

## Implemented in PR1

- IQ registry seed now points the public IQ row at `IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO`
- canonical v2 pack metadata no longer emits `iq-raven-demo` as the active public slug/path
- legacy `IQ-RAVEN-CN-v0.3.0-DEMO` landing metadata is explicitly marked `legacy_demo` and `noindex`
- added IQ identity metadata contract coverage for:
  - seeded public IQ registry defaults
  - canonical v2 and legacy alias questions endpoints
  - canonical/legacy landing metadata roles

## Deliberately deferred

- scoring remains unimplemented in PR1
- answer keys remain absent for the legacy 30 demo
- report builder remains generic in PR1
- commerce unlock remains deferred via `IQ-SIDECAR-COMMERCE-DEFERRED-001`
