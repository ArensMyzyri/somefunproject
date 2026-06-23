# Phase 3 — Safe to Re-Run and Operate: Implementation Spec

## Goal
Make the job safe to schedule and re-run: repeated runs never duplicate a filed decision or double-deduct a balance, a mid-run failure leaves completed work intact, and the operator gets a clear summary.

## In Scope
- **REQ-19:** Re-running the job for the same period files no duplicate decision with the HR system.
- **REQ-20:** Re-running the job for the same period does not deduct the same days from an employee's balance more than once.
- **REQ-21:** A failure partway through a run leaves the decisions already completed intact so a re-run completes only the remaining requests.
- **REQ-22:** The job reports an employee whose data is missing or invalid and still reports the run as successful rather than aborting it.
- **REQ-23:** The job presents an on-screen summary at the end of each run listing the requests approved, rejected, and skipped.
- **REQ-31:** The job persists a balance change for a request only after its decision has been accepted by the HR system.
- **REQ-32:** The job does not re-decide a request whose decision has already been recorded, leaving the original decision to stand.

## Out of Scope for This Phase
- The decision rules themselves — **Phases 1 and 2** (unchanged here).
- Test coverage of these behaviors — **Phase 4**.
- A machine-readable summary artifact and non-success exit codes — explicitly not required (the resolved decision is an on-screen table with a success result even when requests are skipped).

## Current State
- [src/Service/LeaveRequestProcessor.php](../../src/Service/LeaveRequestProcessor.php) sends a fresh random idempotency key per decision (`bin2hex(random_bytes(8))`), so a re-run posts duplicates; it applies the balance change and marks the request decided *before* posting; and it flushes once at the very end, so a mid-run failure loses the whole run. A missing balance throws a `RuntimeException`, aborting the run.
- [src/Hr/HrApiClientInterface.php](../../src/Hr/HrApiClientInterface.php) already accepts an idempotency key argument; [mock-hr-api/server.php](../../mock-hr-api/server.php) already replays the original record for a repeated key and rejects a missing one.
- [src/Command/AbsenceRunCommand.php](../../src/Command/AbsenceRunCommand.php) prints a table of request/decision/days and returns success; it has no notion of skipped requests.
- The pending-requests read ([src/Message/Query/FindPendingRequestsHandler.php](../../src/Message/Query/FindPendingRequestsHandler.php)) returns only `PENDING` requests, so already-decided requests are naturally not re-fetched on a re-run.

## What to Build
1. **Derive a stable idempotency key from the request.** In `LeaveRequestProcessor`, replace the random key with a deterministic key derived from the request identity (e.g. a fixed prefix plus the request id), so the same request always posts under the same key and the HR mock replays rather than duplicating.
2. **Reorder the per-request steps to post before persisting.** For each request: compute the decision, post it to the HR API with the stable key, and only on a successful response apply `markDecided`, the balance change, and the external reference. A balance change must never be persisted without a corresponding accepted decision.
3. **Commit per request.** Flush after each request's decision is recorded, instead of a single flush at the end, so an interruption leaves earlier requests durably decided and a re-run resumes with the remainder (which the pending-requests query already returns).
4. **Skip bad data instead of aborting.** Replace the thrown `RuntimeException` for a missing leave balance (and similar unprocessable data) with handling that records the request as skipped with a reason and continues to the next request.
5. **Extend the run summary.** Add skipped entries (with reason) to the summary returned by `processPending`, alongside approved and rejected entries, carrying enough detail for the command to render them.
6. **Render approved, rejected, and skipped in the command.** Update `AbsenceRunCommand` to display all three outcomes in its on-screen table and to report overall success even when some requests were skipped for bad data.
7. **Rely on status transitions for re-run safety.** Because a decided request leaves `PENDING`, a re-run does not re-decide it; combined with the stable key and post-before-persist ordering, re-runs neither duplicate filings nor double-deduct balances.

## Interfaces and Data Structures
- **Idempotency key:** a deterministic string that is a pure function of the request id, identical across runs for the same request.
- **Per-request flow:** decide → post (with key) → on success: mark decided + apply balance + store external reference + flush; on HR failure: do not persist, surface the request as failed/skipped, continue.
- **Summary entry:** request id, outcome (`approved` / `rejected` / `skipped`), days (where applicable), and a reason string; skipped entries always include the reason.
- **Run result contract:** the command receives a list of summary entries covering every pending request, partitioned by outcome.

## Acceptance Criteria
1. Running the job twice over the same period results in exactly one HR decision per request, with the second run's posts replayed rather than duplicated (REQ-19).
2. Running the job twice does not change an employee's balance on the second run (REQ-20).
3. Simulating an HR failure on one request leaves the requests already processed durably decided, and a subsequent run completes only the remaining ones (REQ-21).
4. A request whose leave balance is missing is reported as skipped with a reason, and the run still completes and reports success (REQ-22).
5. The end-of-run summary lists approved, rejected, and skipped requests (REQ-23).
6. No balance change is persisted for a request unless its decision was accepted by the HR system (REQ-31).
7. A request already decided in a prior run is not re-decided on a later run (REQ-32).

## Engineering Standards (from `TECH_RULES.md` — see cross-cutting-spec.md)
This phase must uphold REQ-33–40 on top of the Phase 1 groundwork. Specifically: the run summary (now including skipped entries with reasons) is a DTO, never an associative array (REQ-34, REQ-38); skips and HR-call failures are recorded through the logger with identifying context rather than swallowed (REQ-36); and the per-request decide-post-persist step is expressed within the run command's `execute()` flow (REQ-33).

## Depends On
Phases 1 and 2 — the decision for each request (vacation counting/entitlement, type handling, overlaps, credit-back) must be correct before its filing and persistence are made idempotent and resilient.
