# The Absence Run — PRD: Phase 4 — Confidence and Handover

## Purpose
Defines the requirements for demonstrated correctness and a documented, explainable handover, per `roadmap.md` Phase 4. Numbering is continuous across all phase PRDs.

## Confidence
**Current: ~96%.** All open questions (16–17) are answered; the coverage bar and assumptions-presentation are settled.

## Requirements
**REQ-24:** Each policy rule and edge case in Phases 1 and 2 is demonstrated by an automated test that verifies the expected decision.
**REQ-25:** The open policy questions and the default assumptions taken in their absence are documented for a reviewer to confirm or correct.

## Engineering Standards (apply to all phases)
This phase's deliverables must also satisfy the cross-cutting engineering standards REQ-33–REQ-40, defined in `prd-phase-1.md` and derived from `TECH_RULES.md`. The test suite is the primary evidence that these standards hold — e.g. services and repositories are exercised through their interfaces with test doubles, and DTO validation is covered.

## Resolved Decisions
All Phase 4 questions are answered; the requirements above reflect these.

- **D16 (→ REQ-24):** The bar is one demonstrative test per rule and edge case, including each seeded employee scenario; no fixed coverage percentage.
- **D17 (→ REQ-25):** Assumptions are presented as decisions taken on documented defaults, flagged for correction.
