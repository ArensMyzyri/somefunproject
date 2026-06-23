# Phase 4 — Confidence and Handover: Implementation Spec

## Goal
Pin down the system's behavior with an automated test per rule and edge case, and leave the policy decisions and assumptions documented, so the decisions can be defended and changed without fear.

## In Scope
- **REQ-24:** Each policy rule and edge case in Phases 1 and 2 is demonstrated by an automated test that verifies the expected decision.
- **REQ-25:** The open policy questions and the default assumptions taken in their absence are documented for a reviewer to confirm or correct.

## Out of Scope for This Phase
- Any change to decision behavior — all rules are implemented in Phases 1–3; this phase only demonstrates and documents them.
- A fixed code-coverage percentage — the agreed bar is one demonstrative test per rule and edge case, not a numeric threshold.

## Current State
- [tests/AbsenceRunTestCase.php](../../tests/AbsenceRunTestCase.php) boots the kernel, builds a fresh SQLite schema per test, and wires the processor against an in-memory fake HR client.
- [tests/FakeHrApiClient.php](../../tests/FakeHrApiClient.php) records calls and returns a synthetic id; it does not yet model idempotent replay or induced failure.
- [tests/Service/LeaveRequestProcessorTest.php](../../tests/Service/LeaveRequestProcessorTest.php) has three happy-path vacation tests; there are no tests for counting, entitlement, types, credit-back, overlap, cancellation, or re-run safety.
- [QUESTIONS.md](../../QUESTIONS.md), [SPEC.md](../../SPEC.md), and the phase PRDs already record the resolved decisions and assumptions.

## What to Build
1. **Add focused tests for the working-day counter** in `tests/Service/` covering weekend exclusion, holiday exclusion per state, both half-day positions, and a half-day landing on a non-working boundary (REQ-2, REQ-3, REQ-4).
2. **Add tests for the entitlement calculator** covering full-time/full-year, a mid-year joiner, a part-timer, the combined joiner-and-part-time rounding case, and an entitlement scaled below 20 with no floor (REQ-5–REQ-8, REQ-29).
3. **Add carryover tests** proving valid carryover is included and counted first, and lapsed carryover is excluded at the run date (REQ-9, REQ-12).
4. **Add balance-decision tests** for approve-within-balance and reject-when-over-by-any-amount including a half-day margin (REQ-10, REQ-11).
5. **Add type-handling tests** for special (approved, counted days, no balance change), unpaid (no balance change), and sick (approved, zero days), plus the certified sick credit-back to the correct year (REQ-13–REQ-16, REQ-30).
6. **Add overlap and cancellation tests** for rejection against an already-approved period and one approved earlier in the same run, non-overlap of touching ranges, and a cancelled request blocking nothing (REQ-17, REQ-18).
7. **Add a seeded-scenario test** that loads the sample period's employees and asserts the full expected decision set (Anna, Bjarne, Carla, Dilan, Eva, Felix) matching the validated outcomes.
8. **Extend the fake HR client (or add a test double)** to model idempotent replay for repeated keys and to induce a failure on a chosen request, enabling the Phase 3 re-run and partial-failure tests (REQ-19–REQ-22, REQ-31, REQ-32).
9. **Confirm the assumptions documentation is current.** Ensure `QUESTIONS.md`/`SPEC.md` reflect the resolved decisions as decisions taken on documented defaults, flagged for correction (REQ-25).

## Interfaces and Data Structures
- **Test scenario shape:** an employee (with employment dates, working days per week, state, contractual days), a leave balance (year, carryover, expiry, used), one or more requests (type, dates, half-day flags, certificate), a run date, and the expected per-request outcome and resulting balance.
- **Configurable fake HR client:** records posted payloads and keys; replays the same id for a repeated key; can be told to throw for a specified request to simulate a mid-run failure.
- **Expected-outcome assertions:** per request, the decision status, the reported days, and the post-run balance.

## Acceptance Criteria
1. Every Phase 1 and Phase 2 rule and edge case has at least one automated test asserting the expected decision, and the suite passes (REQ-24).
2. The three pre-existing happy-path tests continue to pass unchanged (REQ-24).
3. The seeded-period scenario test asserts the complete expected decision set for all six sample employees (REQ-24).
4. Re-run safety and partial-failure behavior from Phase 3 are demonstrated by tests using the configurable fake HR client (REQ-24).
5. The resolved policy decisions and assumptions are documented and discoverable for a reviewer (REQ-25).

## Engineering Standards (from `TECH_RULES.md` — see cross-cutting-spec.md)
The test suite is the primary evidence that REQ-33–40 hold. Tests exercise services and repositories through their interfaces using test doubles (REQ-35, REQ-37), assert on DTO results rather than arrays (REQ-34, REQ-38), and cover DTO input validation — both a valid run input and a rejected invalid one — against the Validator constraints (REQ-39, REQ-40). Where practical, a test confirms that a forced failure is logged rather than silently swallowed (REQ-36).

## Depends On
Phases 1, 2, and 3 — all behavior under test must be implemented before it can be demonstrated; the configurable fake HR client is required for the Phase 3 behaviors.
