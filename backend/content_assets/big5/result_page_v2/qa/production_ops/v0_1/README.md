# Big Five V2 production ops v0.1

This package records the M8 production operations reporting contract for Big Five result page V2.

Scope:
- Backend Ops evidence only.
- Tracks V2 coverage, fallback rate, invalid rejection reasons, leak scan, PDF/private-link/footer/token smoke, and Ops audit fields.
- Uses existing `report_snapshots` audit fields and Report / PDF Center redaction.
- Does not add frontend copy, change content generation, enable rollout, or make the legacy engine primary.

Decision:
- Ops reporting contract is ready for reviewed production operations.
- Runtime and rollout remain controlled by release snapshot, production import gate, rollout gate, audit fields, and Ops review.
