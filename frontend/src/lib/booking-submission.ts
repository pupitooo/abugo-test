export interface Slot {
  startTime: string;
  endTime: string;
}

export interface BookingSubmissionError {
  field: string | null;
  message: string;
}

export interface BookingSubmissionAttemptResult {
  data?: {
    createBooking?: {
      stylist: {
        availableSlots: {
          edges: { node: Slot }[];
        };
      } | null;
      errors: BookingSubmissionError[];
    } | null;
  } | null;
  errors?: readonly unknown[];
}

export interface BookingSubmissionLock {
  current: boolean;
}

export type BookingReconciliation = 'not-needed' | 'succeeded' | 'failed' | 'timed-out';

export type BookingSubmissionOutcome =
  | { kind: 'ignored' }
  | { kind: 'success'; slots: Slot[] }
  | {
    kind: 'domain-error';
    messages: string[];
    retry: 'allowed' | 'blocked';
    reconciliation: BookingReconciliation;
  }
  | {
    kind: 'uncertain';
    retry: 'allowed' | 'blocked';
    reason: 'failure' | 'timeout';
    reconciliation: BookingReconciliation;
  };

export type BookingSubmissionAttempt = () => Promise<BookingSubmissionAttemptResult>;

export interface BookingSubmissionTimeout {
  promise: Promise<void>;
  cancel: () => void;
}

export type BookingSubmissionTimeoutFactory = (
  milliseconds: number,
  phase: 'attempts' | 'reconciliation',
) => BookingSubmissionTimeout;

export interface BookingSubmissionOptions {
  attemptTimeoutMs?: number;
  reconciliationTimeoutMs?: number;
  createTimeout?: BookingSubmissionTimeoutFactory;
}

const DEFAULT_ATTEMPT_TIMEOUT_MS = 15_000;
const DEFAULT_RECONCILIATION_TIMEOUT_MS = 10_000;

type AttemptOutcome =
  | { kind: 'success'; slots: Slot[] }
  | { kind: 'domain-error'; errors: BookingSubmissionError[] }
  | { kind: 'failure' };

type TimedOperation<T> =
  | { kind: 'completed'; value: T }
  | { kind: 'failed' }
  | { kind: 'timed-out' };

function defaultCreateTimeout(milliseconds: number): BookingSubmissionTimeout {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const promise = new Promise<void>((resolve) => {
    timer = setTimeout(resolve, milliseconds);
  });

  return {
    promise,
    cancel: () => {
      if (timer !== undefined) {
        clearTimeout(timer);
        timer = undefined;
      }
    },
  };
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function isSlot(value: unknown): value is Slot {
  return isObject(value)
    && typeof value.startTime === 'string'
    && typeof value.endTime === 'string';
}

function isSubmissionError(value: unknown): value is BookingSubmissionError {
  return isObject(value)
    && (value.field === null || typeof value.field === 'string')
    && typeof value.message === 'string';
}

function classifyAttempt(value: unknown): AttemptOutcome {
  if (!isObject(value)) {
    return { kind: 'failure' };
  }

  if (
    value.errors !== undefined
    && (!Array.isArray(value.errors) || value.errors.length > 0)
  ) {
    return { kind: 'failure' };
  }

  const data = value.data;
  if (!isObject(data) || !isObject(data.createBooking)) {
    return { kind: 'failure' };
  }

  const payload = data.createBooking;
  if (!Array.isArray(payload.errors) || !payload.errors.every(isSubmissionError)) {
    return { kind: 'failure' };
  }

  if (payload.errors.length > 0) {
    return payload.stylist === null
      ? { kind: 'domain-error', errors: payload.errors }
      : { kind: 'failure' };
  }

  if (!isObject(payload.stylist) || !isObject(payload.stylist.availableSlots)) {
    return { kind: 'failure' };
  }

  const edges = payload.stylist.availableSlots.edges;
  if (
    !Array.isArray(edges)
    || !edges.every((edge) => isObject(edge) && isSlot(edge.node))
  ) {
    return { kind: 'failure' };
  }

  return { kind: 'success', slots: edges.map((edge) => edge.node as Slot) };
}

function observeAttempt(startAttempt: BookingSubmissionAttempt): Promise<AttemptOutcome> {
  return Promise.resolve()
    .then(startAttempt)
    .then(classifyAttempt)
    .catch(() => ({ kind: 'failure' }));
}

async function settleBeforeTimeout<T>(
  operation: Promise<T>,
  milliseconds: number,
  phase: 'attempts' | 'reconciliation',
  createTimeout: BookingSubmissionTimeoutFactory,
): Promise<TimedOperation<T>> {
  let timeout: BookingSubmissionTimeout;

  try {
    timeout = createTimeout(milliseconds, phase);
  } catch {
    return { kind: 'failed' };
  }

  try {
    return await Promise.race([
      operation.then<TimedOperation<T>, TimedOperation<T>>(
        (value) => ({ kind: 'completed', value }),
        () => ({ kind: 'failed' }),
      ),
      timeout.promise.then<TimedOperation<T>, TimedOperation<T>>(
        () => ({ kind: 'timed-out' }),
        () => ({ kind: 'failed' }),
      ),
    ]);
  } finally {
    try {
      timeout.cancel();
    } catch {
      // A cleanup failure must not turn a handled submission into an unhandled error.
    }
  }
}

async function reconcileWithTimeout(
  reconcile: () => Promise<unknown>,
  timeoutMs: number,
  createTimeout: BookingSubmissionTimeoutFactory,
): Promise<Exclude<BookingReconciliation, 'not-needed'>> {
  let reconciliation: Promise<'succeeded' | 'failed'>;

  try {
    reconciliation = Promise.resolve(reconcile()).then(
      () => 'succeeded',
      () => 'failed',
    );
  } catch {
    return 'failed';
  }

  const result = await settleBeforeTimeout(
    reconciliation,
    timeoutMs,
    'reconciliation',
    createTimeout,
  );

  if (result.kind === 'timed-out') {
    return 'timed-out';
  }

  return result.kind === 'completed' ? result.value : 'failed';
}

function uncertainAfterReconciliation(
  reconciliation: Exclude<BookingReconciliation, 'not-needed'>,
): BookingSubmissionOutcome {
  return {
    kind: 'uncertain',
    retry: reconciliation === 'succeeded' ? 'allowed' : 'blocked',
    reason: reconciliation === 'timed-out' ? 'timeout' : 'failure',
    reconciliation,
  };
}

export async function coordinateBookingSubmission(
  lock: BookingSubmissionLock,
  startAttempts: readonly BookingSubmissionAttempt[],
  reconcile: () => Promise<unknown>,
  options: BookingSubmissionOptions = {},
): Promise<BookingSubmissionOutcome> {
  if (lock.current) {
    return { kind: 'ignored' };
  }

  lock.current = true;

  try {
    if (startAttempts.length === 0) {
      return {
        kind: 'uncertain',
        retry: 'blocked',
        reason: 'failure',
        reconciliation: 'not-needed',
      };
    }

    // Every rejection handler is attached before any thunk starts. A synchronous throw
    // therefore cannot leave a sibling request with an unhandled rejection.
    const settledOutcomes: AttemptOutcome[] = [];
    const attempts = startAttempts.map((startAttempt) => (
      observeAttempt(startAttempt).then((outcome) => {
        settledOutcomes.push(outcome);
        return outcome;
      })
    ));
    const attemptResult = await settleBeforeTimeout(
      Promise.all(attempts),
      options.attemptTimeoutMs ?? DEFAULT_ATTEMPT_TIMEOUT_MS,
      'attempts',
      options.createTimeout ?? defaultCreateTimeout,
    );

    if (attemptResult.kind === 'timed-out') {
      const success = settledOutcomes.find((outcome) => outcome.kind === 'success');
      if (success?.kind === 'success') {
        return { kind: 'success', slots: success.slots };
      }

      return {
        kind: 'uncertain',
        retry: 'blocked',
        reason: 'timeout',
        reconciliation: 'not-needed',
      };
    }

    if (attemptResult.kind === 'failed') {
      return {
        kind: 'uncertain',
        retry: 'blocked',
        reason: 'failure',
        reconciliation: 'not-needed',
      };
    }

    const outcomes = attemptResult.value;
    const success = outcomes.find((outcome) => outcome.kind === 'success');
    if (success?.kind === 'success') {
      return { kind: 'success', slots: success.slots };
    }

    if (outcomes.every((outcome) => outcome.kind === 'domain-error')) {
      const errors = outcomes.flatMap((outcome) => (
        outcome.kind === 'domain-error' ? outcome.errors : []
      ));
      const messages = [...new Set(errors.map((error) => error.message))];

      if (!errors.some((error) => error.field === 'startTime')) {
        return {
          kind: 'domain-error',
          messages,
          retry: 'allowed',
          reconciliation: 'not-needed',
        };
      }

      const reconciliation = await reconcileWithTimeout(
        reconcile,
        options.reconciliationTimeoutMs ?? DEFAULT_RECONCILIATION_TIMEOUT_MS,
        options.createTimeout ?? defaultCreateTimeout,
      );

      return {
        kind: 'domain-error',
        messages,
        retry: reconciliation === 'succeeded' ? 'allowed' : 'blocked',
        reconciliation,
      };
    }

    const reconciliation = await reconcileWithTimeout(
      reconcile,
      options.reconciliationTimeoutMs ?? DEFAULT_RECONCILIATION_TIMEOUT_MS,
      options.createTimeout ?? defaultCreateTimeout,
    );

    return uncertainAfterReconciliation(reconciliation);
  } finally {
    lock.current = false;
  }
}
