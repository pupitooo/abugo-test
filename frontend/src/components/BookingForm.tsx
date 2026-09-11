'use client';

import { gql, useMutation } from '@apollo/client';
import { useState } from 'react';
import {
  coordinateBookingSubmission,
  type BookingSubmissionLock,
  type Slot,
} from '@/lib/booking-submission';

const CREATE_BOOKING = gql`
  mutation CreateBooking($input: CreateBookingInput!, $serviceId: ID!, $date: String!) {
    createBooking(input: $input) {
      stylist {
        id
        availableSlots(serviceId: $serviceId, date: $date) {
          edges {
            node {
              startTime
              endTime
            }
          }
        }
      }
      errors {
        field
        message
      }
    }
  }
`;

interface CreateBookingData {
  createBooking: {
    stylist: {
      id: string;
      availableSlots: { edges: { node: Slot }[] };
    } | null;
    errors: { field: string | null; message: string }[];
  };
}

interface Props {
  stylistId: string;
  serviceId: string;
  startTime: string;
  startTimeLabel: string;
  date: string;
  stylistName: string;
  serviceName: string;
  slotAvailable: boolean;
  onCancel: () => void;
  onSuccess: (slots: Slot[]) => void;
  onReconcile: () => Promise<void>;
  onSubmittingChange: (submitting: boolean) => void;
  onUnsafeToRetry: () => void;
  submissionLock: BookingSubmissionLock;
}

export default function BookingForm({
  stylistId,
  serviceId,
  startTime,
  startTimeLabel,
  date,
  stylistName,
  serviceName,
  slotAvailable,
  onCancel,
  onSuccess,
  onReconcile,
  onSubmittingChange,
  onUnsafeToRetry,
  submissionLock,
}: Props) {
  const [customerName, setCustomerName] = useState('');
  const [customerContact, setCustomerContact] = useState('');
  const [contactError, setContactError] = useState('');
  const [serverErrors, setServerErrors] = useState<string[]>([]);
  const [succeeded, setSucceeded] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [retryBlocked, setRetryBlocked] = useState(false);
  const [createBooking] = useMutation<CreateBookingData>(CREATE_BOOKING, {
    errorPolicy: 'none',
  });

  function blockRetry(): void {
    setRetryBlocked(true);
    try {
      onUnsafeToRetry();
    } catch {
      // The form must remain safely blocked even if a parent notification fails.
    }
  }

  function validateContact(value: string): string {
    const email = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const phone = /^\+?[\d\s\-().]{7,}$/;
    if (!email.test(value) && !phone.test(value)) {
      return 'Enter a valid email address or phone number.';
    }
    return '';
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (submissionLock.current || retryBlocked || !slotAvailable) return;

    const err = validateContact(customerContact);
    setContactError(err);
    if (err) return;
    setServerErrors([]);

    const variables = {
      variables: {
        input: { stylistId, serviceId, startTime, customerName, customerContact },
        serviceId,
        date,
      },
    };

    setSubmitting(true);
    let bookingConfirmed = false;
    try {
      onSubmittingChange(true);
      const outcome = await coordinateBookingSubmission(
        submissionLock,
        // Simulate double-click: fire two identical requests concurrently.
        [() => createBooking(variables), () => createBooking(variables)],
        onReconcile,
      );

      if (outcome.kind === 'success') {
        bookingConfirmed = true;
        setSucceeded(true);
        onSuccess(outcome.slots);
      } else if (outcome.kind === 'domain-error') {
        if (outcome.retry === 'blocked') {
          blockRetry();
          setServerErrors([
            ...outcome.messages,
            "Availability couldn't be refreshed. Reload the page before trying again.",
          ]);
        } else {
          setServerErrors(outcome.messages);
        }
      } else if (outcome.kind === 'uncertain') {
        if (outcome.retry === 'blocked') {
          blockRetry();
        }

        const attemptTimedOut = outcome.reason === 'timeout'
          && outcome.reconciliation === 'not-needed';
        const reconciliationNotRun = outcome.reconciliation === 'not-needed';
        const reconciliationTimedOut = outcome.reconciliation === 'timed-out';
        const reconciliationFailed = outcome.reconciliation === 'failed';

        let message = "We couldn't confirm the booking. Availability has been refreshed; please review it before trying again.";
        if (attemptTimedOut) {
          message = "The booking is taking longer than expected, so we couldn't confirm it. Reload the page before trying again.";
        } else if (reconciliationNotRun) {
          message = "We couldn't safely process the booking result. Reload the page before trying again.";
        } else if (reconciliationTimedOut) {
          message = "We couldn't confirm the booking because refreshing availability took too long. Reload the page before trying again.";
        } else if (reconciliationFailed) {
          message = "We couldn't confirm the booking or refresh availability. Reload the page before trying again.";
        }
        setServerErrors([message]);
      }
    } catch {
      blockRetry();
      if (!bookingConfirmed) {
        setServerErrors([
          "We couldn't safely process the booking result. Reload the page before trying again.",
        ]);
      }
    } finally {
      setSubmitting(false);
      try {
        onSubmittingChange(false);
      } catch {
        // A parent notification must not escape from the submit handler.
      }
    }
  }

  if (succeeded) {
    return (
      <div className="mt-3 p-4 border border-stone-700 bg-charcoal-900 rounded-sm flex items-center justify-between gap-4">
        <p className="text-gold-400 text-sm tracking-wide">Booking requested.</p>
        <button
          onClick={onCancel}
          className="px-4 py-1.5 border border-stone-700 hover:border-stone-500 text-stone-400 text-sm rounded-sm transition-colors shrink-0"
        >
          OK
        </button>
      </div>
    );
  }

  return (
    <form
      onSubmit={handleSubmit}
      aria-busy={submitting}
      className="mt-3 p-4 border border-stone-700 bg-charcoal-900 rounded-sm space-y-4"
    >
      <div className="text-xs text-stone-500 space-y-0.5">
        <p>
          <span className="text-stone-400">{serviceName}</span> with{' '}
          <span className="text-stone-400">{stylistName}</span>
        </p>
        <p>
          <span className="text-gold-500">{startTimeLabel}</span>
        </p>
      </div>

      <div className="space-y-3">
        <div>
          <label className="block text-xs tracking-widest uppercase text-stone-500 mb-1">
            Your name
          </label>
          <input
            type="text"
            required
            disabled={submitting || retryBlocked}
            value={customerName}
            onChange={(e) => setCustomerName(e.target.value)}
            className="w-full bg-charcoal-950 border border-stone-700 text-stone-200 text-sm px-3 py-2 rounded-sm focus:outline-none focus:border-gold-500"
          />
        </div>

        <div>
          <label className="block text-xs tracking-widest uppercase text-stone-500 mb-1">
            Phone / email
          </label>
          <input
            type="text"
            required
            disabled={submitting || retryBlocked}
            value={customerContact}
            onChange={(e) => { setCustomerContact(e.target.value); setContactError(''); }}
            className={`w-full bg-charcoal-950 border text-stone-200 text-sm px-3 py-2 rounded-sm focus:outline-none ${
              contactError ? 'border-red-500 focus:border-red-500' : 'border-stone-700 focus:border-gold-500'
            }`}
          />
          {contactError && <p className="text-red-400 text-xs mt-1">{contactError}</p>}
        </div>
      </div>

      {serverErrors.length > 0 && (
        <ul role="alert" className="space-y-1">
          {serverErrors.map((msg) => (
            <li key={msg} className="text-red-400 text-xs">{msg}</li>
          ))}
        </ul>
      )}

      <div className="flex gap-3 pt-1">
        <button
          type="submit"
          disabled={submitting || retryBlocked || !slotAvailable}
          className="flex-1 py-2 bg-gold-600 hover:bg-gold-500 disabled:opacity-50 text-charcoal-950 text-sm font-medium tracking-wide rounded-sm transition-colors"
        >
          {submitting ? 'Booking…' : 'Book'}
        </button>
        <button
          type="button"
          onClick={onCancel}
          disabled={submitting || retryBlocked}
          className="px-4 py-2 border border-stone-700 hover:border-stone-500 text-stone-400 text-sm rounded-sm transition-colors"
        >
          Cancel
        </button>
      </div>
    </form>
  );
}
