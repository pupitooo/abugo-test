---
name: test-write-race
description: Create, change, review, or verify deterministic integration tests for database write races such as duplicate records, overlapping reservations, lost updates, and concurrent state transitions. Use when the user asks for regression-test work around concurrent writes; do not use for diagnosis-only requests or ordinary sequential failures.
---

# Test a database write race

Implement or modify tests only when requested; otherwise inspect and report. Change production code only when explicitly requested.

1. State the invariant and requested write paths. Inspect the real database, transaction boundaries, and existing test conventions before choosing the schedule.
2. Before any write, prove that the resolved database or DSN is owned by this test and disposable. Abort if that cannot be established.
3. Use independent connections, and separate processes when shared runtime state could hide the race. Coordinate them with explicit barriers; never use sleeps or scheduler luck as synchronization.
4. Give every blocking read and process wait a deadline. Always check worker stderr and exit status; in unconditional cleanup, terminate live workers, reap all workers, roll back open transactions, and close streams or temporary state.
5. Assert each participant's result and the final persisted invariant. Preserve unexpected failures. Retry only the expected retryable error, after resetting failed transaction or ORM state, and only when repeating the same logical operation is safe.
6. For implementation work, when feasible, prove regression sensitivity against a known-bad revision in an isolated worktree or a test-local substitute. Otherwise report why a fail-first check was not possible.
7. Run the focused test. If shared fixtures, workers, migrations, or bootstrap changed, also run the full affected suite. Report commands, outcomes, and materially related uncovered write paths.

## Repository-specific SQLite booking reference

For booking overlap or status races, inspect:

- `backend/tests/Integration/CreateBookingTest.php`, especially `testConcurrentDatabaseWritesCreateOnlyOneActiveBooking()`
- `backend/tests/Support/concurrent-booking-worker.php`
- `backend/migrations/Version20260910131913.php`
- `backend/src/Infrastructure/Barbershop/Repository/DoctrineBookingRepository.php`

Reuse the existing `READY → GO → LOCKED → COMMIT → RETRY` protocol when applicable. For its contender connection, keep `PDO::ATTR_TIMEOUT = 0` and `PRAGMA busy_timeout = 0`; assert busy code `5`, then SQLSTATE `23000`, SQLite code `19`, `booking_slot_unavailable`, and one active booking.

From the repository root, run:

```bash
make test tests/Integration/CreateBookingTest.php
```
