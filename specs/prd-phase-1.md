# The Absence Run — PRD: Phase 1 — Correct by the Policy

## Purpose
Defines the requirements for the job to count leave and entitlement accurately, per `roadmap.md` Phase 1. Numbering is continuous across all phase PRDs.

## Confidence
**Current: ~94%.** All open questions (1–6) are answered; requirements below reflect those decisions. Residual uncertainty is only in scenarios not present in the sample period.

## Requirements
**REQ-1:** The operator can run the job for a chosen run date and have it process every leave request currently awaiting a decision.
**REQ-2:** The job counts only Monday-to-Friday days within a request's date range as leave days.
**REQ-3:** The job excludes public holidays that fall on a working day, based on the employee's federal state, from the days a request consumes.
**REQ-4:** The job counts a flagged half-day at the start or end of a request as half a leave day only when that day is itself a working day.
**REQ-5:** The job calculates a full-time, full-year employee's entitlement as their contractual annual leave.
**REQ-6:** The job reduces entitlement in proportion to the full calendar months employed for an employee who joins or leaves part-way through the leave year.
**REQ-7:** The job scales an employee's entitlement — but not the days a request consumes — by their contracted working days per week.
**REQ-8:** The job rounds a reduced entitlement up to the nearest half-day once, after applying all reductions.
**REQ-29:** The job does not raise an employee's earned entitlement to the statutory minimum when pro-rata or part-time scaling reduces it below 20 days.
**REQ-9:** The job includes carried-over days in available leave only when they have not lapsed as of the run date.
**REQ-10:** The job approves a request whose consumed days fit within the employee's remaining available leave and records the days against their balance.
**REQ-11:** The job rejects in full any request whose consumed days exceed the employee's remaining available leave, without partially approving it.
**REQ-12:** The job draws approved days from still-valid carried-over days before drawing from the current year's entitlement.

## Cross-Cutting Requirements (apply to all phases)
**REQ-26:** The job decides only requests awaiting a decision and leaves already-decided requests unchanged.
**REQ-27:** The job records every decision to the HR system before reporting the run as complete.
**REQ-28:** The job determines an employee's available leave against the leave year of the request being decided.

## Engineering Standards (apply to all phases)
These derive from `TECH_RULES.md` and are verifiable by inspection of the codebase; they apply to every phase's deliverables.

**REQ-33:** Each application operation is invoked as an explicit command (state-changing) or query (read-only) through a consistent abstraction (`$command->execute()` / `$query->ask()`).
**REQ-34:** Data crossing a layer boundary is carried by a dedicated DTO for that boundary.
**REQ-35:** Every service is referenced through an interface rather than a concrete class.
**REQ-36:** Errors are logged with enough context to diagnose them, and no exception is silently swallowed.
**REQ-37:** Write-side database access goes through a repository referenced via a repository interface, while read-side queries are served by query handlers that may query the EntityManager directly.
**REQ-38:** Structured data is represented as DTOs or value objects, never ad-hoc associative arrays.
**REQ-39:** Incoming data is validated at the entry point (controller or command) before it flows deeper into the system.
**REQ-40:** DTO validation is expressed as Symfony Validator constraints declared on the DTO.

## Resolved Decisions
All Phase 1 questions are answered; the requirements above reflect these.

- **D1 (→ REQ-2, REQ-7):** A part-time request consumes the full Monday–Friday working-day count; only entitlement is scaled.
- **D2 (→ REQ-8):** Round up to the nearest half-day once, on the final figure after all reductions.
- **D3 (→ REQ-29):** Scaling may reduce earned entitlement below 20 days; no statutory floor is applied.
- **D4 (→ REQ-9):** The run date governs whether carried-over days have lapsed, per §6.
- **D5 (→ REQ-4):** A half-day flag deducts 0.5 only when the boundary day is itself a working day.
- **D6 (→ REQ-6):** Only whole calendar months worked in their entirety count toward pro-rata.
