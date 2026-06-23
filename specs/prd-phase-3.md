# The Absence Run — PRD: Phase 3 — Safe to Re-Run and Operate

## Purpose
Defines the requirements for safe re-runs, partial-failure handling, and operator visibility, per `roadmap.md` Phase 3. Numbering is continuous across all phase PRDs.

## Confidence
**Current: ~95%.** All open questions (12–15) are answered; requirements below reflect the per-request commit model and re-run behavior chosen.

## Requirements
**REQ-19:** Re-running the job for the same period files no duplicate decision with the HR system.
**REQ-20:** Re-running the job for the same period does not deduct the same days from an employee's balance more than once.
**REQ-21:** A failure partway through a run leaves the decisions already completed intact so a re-run completes only the remaining requests.
**REQ-22:** The job reports an employee whose data is missing or invalid and still reports the run as successful rather than aborting it.
**REQ-23:** The job presents an on-screen summary at the end of each run listing the requests approved, rejected, and skipped.
**REQ-31:** The job persists a balance change for a request only after its decision has been accepted by the HR system.
**REQ-32:** The job does not re-decide a request whose decision has already been recorded, leaving the original decision to stand.

## Engineering Standards (apply to all phases)
This phase's deliverables must also satisfy the cross-cutting engineering standards REQ-33–REQ-40, defined in `prd-phase-1.md` and derived from `TECH_RULES.md` (commands/queries, DTOs across layers, service and repository interfaces, logging, edge validation, and Validator constraints). The run summary in particular is exchanged as a DTO rather than a raw array (REQ-34, REQ-38).

## Resolved Decisions
All Phase 3 questions are answered; the requirements above reflect these.

- **D12 (→ REQ-21):** Per-request commit — completed work survives a failure and a re-run finishes the remainder.
- **D13 (→ REQ-32):** A request already recorded is not re-decided; the original decision stands.
- **D14 (→ REQ-22, REQ-23):** An on-screen summary table suffices; skipped requests are listed but the run still reports success.
- **D15 (→ REQ-31):** A request is completed only once HR accepts its decision; no balance change persists without a filed decision.
