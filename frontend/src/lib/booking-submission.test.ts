import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  coordinateBookingSubmission,
  type BookingSubmissionAttempt,
  type BookingSubmissionAttemptResult,
  type BookingSubmissionLock,
  type BookingSubmissionOptions,
  type BookingSubmissionTimeoutFactory,
  type Slot,
} from './booking-submission';

const SLOTS: Slot[] = [
  {
    startTime: '2026-09-14T07:30:00+00:00',
    endTime: '2026-09-14T08:00:00+00:00',
  },
];

function successfulAttempt(slots: Slot[] = SLOTS): BookingSubmissionAttemptResult {
  return {
    errors: undefined,
    data: {
      createBooking: {
        stylist: { availableSlots: { edges: slots.map((node) => ({ node })) } },
        errors: [],
      },
    },
  };
}

function domainErrorAttempt(
  ...errors: { field: string | null; message: string }[]
): BookingSubmissionAttemptResult {
  return {
    data: {
      createBooking: {
        stylist: null,
        errors,
      },
    },
  };
}

function deferred<T>() {
  let resolve!: (value: T | PromiseLike<T>) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });

  return { promise, resolve, reject };
}

interface ManualTimeout {
  phase: 'attempts' | 'reconciliation';
  cancelled: boolean;
  expire: () => void;
}

function manualTimeouts(): {
  options: BookingSubmissionOptions;
  created: ManualTimeout[];
} {
  const created: ManualTimeout[] = [];
  const createTimeout: BookingSubmissionTimeoutFactory = (_milliseconds, phase) => {
    const expiration = deferred<void>();
    const timeout: ManualTimeout = {
      phase,
      cancelled: false,
      expire: () => expiration.resolve(undefined),
    };
    created.push(timeout);

    return {
      promise: expiration.promise,
      cancel: () => { timeout.cancelled = true; },
    };
  };

  return { options: { createTimeout }, created };
}

function attempts(...results: BookingSubmissionAttemptResult[]): BookingSubmissionAttempt[] {
  return results.map((result) => () => Promise.resolve(result));
}

function domainError(field: string | null, message: string) {
  return { field, message };
}

async function nextEventLoopTurn(): Promise<void> {
  await new Promise<void>((resolve) => { setImmediate(resolve); });
}

describe('coordinateBookingSubmission', () => {
  it('prioritizes a confirmed success over a domain conflict in either order', async () => {
    const conflict = domainErrorAttempt(
      domainError('startTime', 'This slot is no longer available.'),
    );

    for (const orderedAttempts of [
      attempts(successfulAttempt(), conflict),
      attempts(conflict, successfulAttempt()),
    ]) {
      const timeouts = manualTimeouts();
      const lock: BookingSubmissionLock = { current: false };
      let reconcileCalls = 0;

      const outcome = await coordinateBookingSubmission(
        lock,
        orderedAttempts,
        async () => { reconcileCalls += 1; },
        timeouts.options,
      );

      assert.deepEqual(outcome, { kind: 'success', slots: SLOTS });
      assert.equal(reconcileCalls, 0);
      assert.equal(lock.current, false);
      assert.equal(timeouts.created.length, 1);
      assert.equal(timeouts.created[0]?.phase, 'attempts');
      assert.equal(timeouts.created[0]?.cancelled, true);
    }
  });

  it('ignores a rapid repeated submission without starting more requests', async () => {
    const firstAttempt = deferred<BookingSubmissionAttemptResult>();
    const secondAttempt = deferred<BookingSubmissionAttemptResult>();
    const lock: BookingSubmissionLock = { current: false };
    let startedRequests = 0;

    const submission = coordinateBookingSubmission(
      lock,
      [
        () => { startedRequests += 1; return firstAttempt.promise; },
        () => { startedRequests += 1; return secondAttempt.promise; },
      ],
      async () => undefined,
    );
    const repeatedSubmission = await coordinateBookingSubmission(
      lock,
      [() => { startedRequests += 1; return Promise.resolve(successfulAttempt()); }],
      async () => undefined,
    );

    assert.deepEqual(repeatedSubmission, { kind: 'ignored' });
    assert.equal(startedRequests, 2);
    assert.equal(lock.current, true);

    firstAttempt.resolve(successfulAttempt());
    secondAttempt.resolve(domainErrorAttempt(
      domainError('startTime', 'This slot is no longer available.'),
    ));

    assert.deepEqual(await submission, { kind: 'success', slots: SLOTS });
    assert.equal(startedRequests, 2);
    assert.equal(lock.current, false);
  });

  it('returns a confirmed success at timeout when a sibling never settles', async () => {
    const timeouts = manualTimeouts();
    const neverSettles = new Promise<BookingSubmissionAttemptResult>(() => undefined);
    const lock: BookingSubmissionLock = { current: false };

    const submission = coordinateBookingSubmission(
      lock,
      [() => Promise.resolve(successfulAttempt()), () => neverSettles],
      async () => undefined,
      timeouts.options,
    );
    await nextEventLoopTurn();

    assert.equal(lock.current, true);
    assert.equal(timeouts.created[0]?.phase, 'attempts');
    assert.equal(timeouts.created[0]?.cancelled, false);

    timeouts.created[0]?.expire();

    assert.deepEqual(await submission, { kind: 'success', slots: SLOTS });
    assert.equal(lock.current, false);
    assert.equal(timeouts.created[0]?.cancelled, true);
  });

  it('waits for a pending attempt before reconciling a rejection', async () => {
    const pendingAttempt = deferred<BookingSubmissionAttemptResult>();
    const reconciliation = deferred<void>();
    const lock: BookingSubmissionLock = { current: false };
    let reconcileCalls = 0;

    const submission = coordinateBookingSubmission(
      lock,
      [
        () => Promise.reject(new Error('network failure')),
        () => pendingAttempt.promise,
      ],
      () => {
        reconcileCalls += 1;
        return reconciliation.promise;
      },
    );

    await nextEventLoopTurn();
    assert.equal(reconcileCalls, 0);
    assert.equal(lock.current, true);

    pendingAttempt.resolve(domainErrorAttempt(
      domainError('customerName', 'Customer name is invalid.'),
    ));
    await nextEventLoopTurn();

    assert.equal(reconcileCalls, 1);
    assert.equal(lock.current, true);

    reconciliation.resolve(undefined);
    assert.deepEqual(await submission, {
      kind: 'uncertain',
      retry: 'allowed',
      reason: 'failure',
      reconciliation: 'succeeded',
    });
    assert.equal(lock.current, false);
  });

  it('handles synchronous starter throws without leaving sibling rejections unhandled', async () => {
    const lock: BookingSubmissionLock = { current: false };
    let reconcileCalls = 0;

    const outcome = await coordinateBookingSubmission(
      lock,
      [
        () => Promise.reject(new Error('first rejection')),
        () => { throw new Error('synchronous start failure'); },
        () => Promise.reject(new Error('last rejection')),
      ],
      async () => { reconcileCalls += 1; },
    );
    await nextEventLoopTurn();

    assert.deepEqual(outcome, {
      kind: 'uncertain',
      retry: 'allowed',
      reason: 'failure',
      reconciliation: 'succeeded',
    });
    assert.equal(reconcileCalls, 1);
    assert.equal(lock.current, false);
  });

  it('reconciles exactly once when both attempts reject', async () => {
    const lock: BookingSubmissionLock = { current: false };
    let reconcileCalls = 0;

    const outcome = await coordinateBookingSubmission(
      lock,
      [
        () => Promise.reject(new Error('first failure')),
        () => Promise.reject(new Error('second failure')),
      ],
      async () => { reconcileCalls += 1; },
    );

    assert.deepEqual(outcome, {
      kind: 'uncertain',
      retry: 'allowed',
      reason: 'failure',
      reconciliation: 'succeeded',
    });
    assert.equal(reconcileCalls, 1);
    assert.equal(lock.current, false);
  });

  it('treats top-level GraphQL errors and malformed payloads as uncertain', async () => {
    const lock: BookingSubmissionLock = { current: false };
    let reconcileCalls = 0;
    const topLevelError: BookingSubmissionAttemptResult = {
      data: { createBooking: successfulAttempt().data?.createBooking },
      errors: [{ message: 'Internal server error' }],
    };
    const malformed = { data: { createBooking: { stylist: null, errors: [] } } };

    const outcome = await coordinateBookingSubmission(
      lock,
      [() => Promise.resolve(topLevelError), () => Promise.resolve(malformed)],
      async () => { reconcileCalls += 1; },
    );

    assert.deepEqual(outcome, {
      kind: 'uncertain',
      retry: 'allowed',
      reason: 'failure',
      reconciliation: 'succeeded',
    });
    assert.equal(reconcileCalls, 1);
  });

  it('deduplicates domain errors and reconciles slot availability', async () => {
    const lock: BookingSubmissionLock = { current: false };
    let reconcileCalls = 0;

    const outcome = await coordinateBookingSubmission(
      lock,
      attempts(
        domainErrorAttempt(
          domainError('startTime', 'This slot is no longer available.'),
          domainError(null, 'Booking is temporarily unavailable.'),
        ),
        domainErrorAttempt(
          domainError('startTime', 'This slot is no longer available.'),
        ),
      ),
      async () => { reconcileCalls += 1; },
    );

    assert.deepEqual(outcome, {
      kind: 'domain-error',
      messages: [
        'This slot is no longer available.',
        'Booking is temporarily unavailable.',
      ],
      retry: 'allowed',
      reconciliation: 'succeeded',
    });
    assert.equal(reconcileCalls, 1);
    assert.equal(lock.current, false);
  });

  it('does not reconcile ordinary validation domain errors', async () => {
    const lock: BookingSubmissionLock = { current: false };
    let reconcileCalls = 0;

    const outcome = await coordinateBookingSubmission(
      lock,
      attempts(
        domainErrorAttempt(domainError('customerName', 'Customer name must not be blank.')),
        domainErrorAttempt(domainError('customerName', 'Customer name must not be blank.')),
      ),
      async () => { reconcileCalls += 1; },
    );

    assert.deepEqual(outcome, {
      kind: 'domain-error',
      messages: ['Customer name must not be blank.'],
      retry: 'allowed',
      reconciliation: 'not-needed',
    });
    assert.equal(reconcileCalls, 0);
  });

  it('allows a later manual retry after a successful reconciliation', async () => {
    const lock: BookingSubmissionLock = { current: false };
    let startedSubmissions = 0;

    const uncertain = await coordinateBookingSubmission(
      lock,
      [
        () => { startedSubmissions += 1; return Promise.reject(new Error('failure')); },
      ],
      async () => undefined,
    );

    assert.deepEqual(uncertain, {
      kind: 'uncertain',
      retry: 'allowed',
      reason: 'failure',
      reconciliation: 'succeeded',
    });

    const retried = await coordinateBookingSubmission(
      lock,
      [
        () => { startedSubmissions += 1; return Promise.resolve(successfulAttempt()); },
      ],
      async () => undefined,
    );

    assert.deepEqual(retried, { kind: 'success', slots: SLOTS });
    assert.equal(startedSubmissions, 2);
  });

  it('blocks retry when reconciliation fails', async () => {
    const lock: BookingSubmissionLock = { current: false };

    const outcome = await coordinateBookingSubmission(
      lock,
      [() => Promise.reject(new Error('request failed'))],
      async () => { throw new Error('refetch failed'); },
    );

    assert.deepEqual(outcome, {
      kind: 'uncertain',
      retry: 'blocked',
      reason: 'failure',
      reconciliation: 'failed',
    });
    assert.equal(lock.current, false);
  });

  it('blocks retry when a slot-error reconciliation fails', async () => {
    const lock: BookingSubmissionLock = { current: false };

    const outcome = await coordinateBookingSubmission(
      lock,
      attempts(domainErrorAttempt(
        domainError('startTime', 'This slot is no longer available.'),
      )),
      async () => { throw new Error('refetch failed'); },
    );

    assert.deepEqual(outcome, {
      kind: 'domain-error',
      messages: ['This slot is no longer available.'],
      retry: 'blocked',
      reconciliation: 'failed',
    });
  });

  it('times out pending attempts without racing them against reconciliation', async () => {
    const timeouts = manualTimeouts();
    const lock: BookingSubmissionLock = { current: false };
    const firstAttempt = deferred<BookingSubmissionAttemptResult>();
    const secondAttempt = deferred<BookingSubmissionAttemptResult>();
    let reconcileCalls = 0;

    const submission = coordinateBookingSubmission(
      lock,
      [() => firstAttempt.promise, () => secondAttempt.promise],
      async () => { reconcileCalls += 1; },
      timeouts.options,
    );
    assert.equal(timeouts.created[0]?.phase, 'attempts');

    timeouts.created[0]?.expire();

    assert.deepEqual(await submission, {
      kind: 'uncertain',
      retry: 'blocked',
      reason: 'timeout',
      reconciliation: 'not-needed',
    });
    assert.equal(reconcileCalls, 0);
    assert.equal(lock.current, false);
    assert.equal(timeouts.created[0]?.cancelled, true);

    // Late failures remain observed after the coordinator has safely timed out.
    firstAttempt.reject(new Error('late first failure'));
    secondAttempt.reject(new Error('late second failure'));
    await nextEventLoopTurn();
  });

  it('times out reconciliation and blocks retry', async () => {
    const timeouts = manualTimeouts();
    const lock: BookingSubmissionLock = { current: false };
    const reconciliation = deferred<void>();

    const submission = coordinateBookingSubmission(
      lock,
      [() => Promise.reject(new Error('request failed'))],
      () => reconciliation.promise,
      timeouts.options,
    );
    await nextEventLoopTurn();

    const reconciliationTimeout = timeouts.created.find(
      (timeout) => timeout.phase === 'reconciliation',
    );
    assert.ok(reconciliationTimeout);
    reconciliationTimeout.expire();

    assert.deepEqual(await submission, {
      kind: 'uncertain',
      retry: 'blocked',
      reason: 'timeout',
      reconciliation: 'timed-out',
    });
    assert.equal(lock.current, false);
    assert.equal(timeouts.created[0]?.cancelled, true);
    assert.equal(reconciliationTimeout.cancelled, true);

    reconciliation.reject(new Error('late reconciliation failure'));
    await nextEventLoopTurn();
  });

  it('preserves domain errors when slot reconciliation times out', async () => {
    const timeouts = manualTimeouts();
    const lock: BookingSubmissionLock = { current: false };
    const reconciliation = deferred<void>();

    const submission = coordinateBookingSubmission(
      lock,
      attempts(domainErrorAttempt(
        domainError('startTime', 'This slot is no longer available.'),
      )),
      () => reconciliation.promise,
      timeouts.options,
    );
    await nextEventLoopTurn();

    const reconciliationTimeout = timeouts.created.find(
      (timeout) => timeout.phase === 'reconciliation',
    );
    assert.ok(reconciliationTimeout);
    reconciliationTimeout.expire();

    assert.deepEqual(await submission, {
      kind: 'domain-error',
      messages: ['This slot is no longer available.'],
      retry: 'blocked',
      reconciliation: 'timed-out',
    });
    assert.equal(reconciliationTimeout.cancelled, true);

    reconciliation.resolve(undefined);
  });

  it('blocks retry for an empty attempt set without calling reconciliation', async () => {
    const lock: BookingSubmissionLock = { current: false };
    let reconcileCalls = 0;

    const outcome = await coordinateBookingSubmission(
      lock,
      [],
      async () => { reconcileCalls += 1; },
    );

    assert.deepEqual(outcome, {
      kind: 'uncertain',
      retry: 'blocked',
      reason: 'failure',
      reconciliation: 'not-needed',
    });
    assert.equal(reconcileCalls, 0);
    assert.equal(lock.current, false);
  });
});
