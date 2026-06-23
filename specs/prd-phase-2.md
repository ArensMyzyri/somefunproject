# The Absence Run — PRD: Phase 2 — The Full Rulebook

## Purpose
Defines the requirements for handling every absence type and policy edge case, per `roadmap.md` Phase 2. Numbering is continuous across all phase PRDs.

## Confidence
**Current: ~92%.** All open questions (7–11) are answered; requirements below reflect those decisions on how sick, special, overlapping, and cancelled requests are handled.

## Requirements
**REQ-13:** The job approves a special-leave request, reporting the counted working days, without reducing the employee's vacation balance.
**REQ-14:** The job records an unpaid-leave request without reducing the employee's vacation balance.
**REQ-15:** The job records a sick-leave request as approved with zero vacation days and without reducing the employee's vacation balance.
**REQ-16:** The job credits the working days a certified sick request overlaps with an already-approved vacation back to the balance of the year in which that vacation falls.
**REQ-17:** The job rejects a request whose dates share at least one day with a leave period already approved, including one approved earlier in the same run.
**REQ-18:** The job treats a cancelled request as consuming no leave.
**REQ-30:** The job does not enforce any allotment cap on special leave.

## Engineering Standards (apply to all phases)
This phase's deliverables must also satisfy the cross-cutting engineering standards REQ-33–REQ-40, defined in `prd-phase-1.md` and derived from `TECH_RULES.md` (commands/queries, DTOs across layers, service and repository interfaces, logging, edge validation, and Validator constraints).

## Resolved Decisions
All Phase 2 questions are answered; the requirements above reflect these.

- **D7 (→ REQ-15):** Sick requests are recorded as approved with zero vacation days; credit-back applies only when certified and overlapping an approved vacation.
- **D8 (→ REQ-13, REQ-30):** Special leave is approved reporting the counted working days; no allotment cap is enforced.
- **D9 (→ REQ-17):** Rejection applies to overlap (sharing ≥1 day) with an already-approved period, including one approved earlier in the same run; ranges that merely touch do not count.
- **D10 (→ REQ-18, cross-cutting):** The recorded used-days figure is trusted as the opening balance; the job adds to and credits from it and does not re-derive history.
- **D11 (→ REQ-16):** Credited-back days return to the balance of the year in which the overlapping approved vacation falls.
