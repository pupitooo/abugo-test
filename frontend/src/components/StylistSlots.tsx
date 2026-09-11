'use client';

import { gql, useQuery } from '@apollo/client';
import { useState } from 'react';
import {
  formatTimeInTimeZone,
  formatTimeWithOffsetInTimeZone,
  formatTimeZoneLabel,
} from '@/lib/date-time';
import type { BookingSubmissionLock, Slot } from '@/lib/booking-submission';
import BookingForm from './BookingForm';

const GET_STYLIST_SLOTS = gql`
  query GetStylistSlots($businessId: ID!, $serviceId: ID!, $date: String!) {
    business(id: $businessId) {
      services {
        edges {
          node {
            id
            stylists {
              edges {
                node {
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
              }
            }
          }
        }
      }
    }
  }
`;

interface GetStylistSlotsData {
  business: {
    services: {
      edges: {
        node: {
          id: string;
          stylists: {
            edges: {
              node: {
                id: string;
                availableSlots: { edges: { node: Slot }[] };
              };
            }[];
          };
        };
      }[];
    };
  } | null;
}

interface Props {
  businessId: string;
  stylistId: string;
  stylistName: string;
  serviceId: string;
  serviceName: string;
  timeZone: string;
  date: string;
  onDateChange: (date: string) => void;
  interactionDisabled: boolean;
  onSubmittingChange: (submitting: boolean) => void;
  onUnsafeSubmission: () => void;
  submissionLock: BookingSubmissionLock;
}

function getSlots(
  data: GetStylistSlotsData | undefined,
  serviceId: string,
  stylistId: string,
): Slot[] {
  const service = data?.business?.services.edges.find((edge) => edge.node.id === serviceId)?.node;
  const stylist = service?.stylists.edges.find((edge) => edge.node.id === stylistId)?.node;

  return stylist?.availableSlots.edges.map((edge) => edge.node) ?? [];
}

export default function StylistSlots({
  businessId,
  stylistId,
  stylistName,
  serviceId,
  serviceName,
  timeZone,
  date,
  onDateChange,
  interactionDisabled,
  onSubmittingChange,
  onUnsafeSubmission,
  submissionLock,
}: Props) {
  const [selectedSlot, setSelectedSlot] = useState<string | null>(null);
  const [slotOverride, setSlotOverride] = useState<Slot[] | null>(null);
  const [availabilityUnknown, setAvailabilityUnknown] = useState(false);

  const { data, loading, error, refetch } = useQuery<GetStylistSlotsData>(GET_STYLIST_SLOTS, {
    variables: { businessId, serviceId, date },
    onCompleted: () => {
      setSlotOverride(null);
      setAvailabilityUnknown(false);
    },
  });

  const slots = slotOverride ?? getSlots(data, serviceId, stylistId);
  const timeLabelCounts = slots.reduce<Map<string, number>>((counts, slot) => {
    const label = formatTimeInTimeZone(slot.startTime, timeZone);
    counts.set(label, (counts.get(label) ?? 0) + 1);
    return counts;
  }, new Map());

  function slotTimeLabel(iso: string): string {
    const label = formatTimeInTimeZone(iso, timeZone);

    return (timeLabelCounts.get(label) ?? 0) > 1
      ? formatTimeWithOffsetInTimeZone(iso, timeZone)
      : label;
  }

  async function reconcileSlots(): Promise<void> {
    try {
      const result = await refetch();
      setSlotOverride(getSlots(result.data, serviceId, stylistId));
      setAvailabilityUnknown(false);
    } catch (refetchError) {
      setSlotOverride([]);
      setAvailabilityUnknown(true);
      throw refetchError;
    }
  }

  function handleUnsafeSubmission(): void {
    setSlotOverride([]);
    setAvailabilityUnknown(true);
    onUnsafeSubmission();
  }

  return (
    <div className="mt-3 border-t border-stone-700 pt-3">
      <div className="flex items-center gap-3 mb-3">
        <label className="text-xs tracking-widest uppercase text-stone-500">Date</label>
        <input
          type="date"
          value={date}
          onChange={(e) => onDateChange(e.target.value)}
          disabled={interactionDisabled}
          className="bg-charcoal-900 border border-stone-700 disabled:opacity-60 text-stone-200 text-sm px-3 py-1.5 rounded-sm focus:outline-none focus:border-gold-500 [color-scheme:dark]"
        />
      </div>

      <p className="mb-3 flex items-center gap-1.5 text-xs text-stone-500">
        <svg
          aria-hidden="true"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.75"
          className="h-3.5 w-3.5 shrink-0"
        >
          <circle cx="12" cy="12" r="8.5" />
          <path d="M12 7.5V12l3 2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
        <span>All times are in the branch&apos;s local time</span>
        <span aria-hidden="true">·</span>
        <span className="text-stone-400">{formatTimeZoneLabel(timeZone)}</span>
      </p>

      {loading && (
        <div className="flex items-center gap-2 text-stone-500 text-xs">
          <div className="w-3 h-3 border border-gold-500 border-t-transparent rounded-full animate-spin" />
          Loading slots…
        </div>
      )}

      {!loading && (error || availabilityUnknown) && (
        <p role="alert" className="text-red-400 text-xs">
          Availability could not be refreshed. Reload the page before trying again.
        </p>
      )}

      {!loading && !error && !availabilityUnknown && slots.length === 0 && (
        <p className="text-stone-500 text-xs italic">No available slots on this date.</p>
      )}

      {!loading && !error && !availabilityUnknown && slots.length > 0 && (
        <div className="space-y-2">
          <div className="flex flex-wrap gap-2">
            {slots.map((slot) => (
              <button
                key={slot.startTime}
                onClick={() => setSelectedSlot((prev) => (prev === slot.startTime ? null : slot.startTime))}
                disabled={interactionDisabled}
                className={`px-3 py-1.5 border text-sm rounded-sm disabled:opacity-60 transition-colors ${
                  selectedSlot === slot.startTime
                    ? 'border-gold-500 text-gold-400 bg-charcoal-900'
                    : 'border-stone-700 text-stone-300 bg-charcoal-900 hover:border-gold-500 hover:text-gold-400'
                }`}
              >
                {slotTimeLabel(slot.startTime)}
              </button>
            ))}
          </div>
        </div>
      )}

      {selectedSlot && (
        <BookingForm
          key={selectedSlot}
          stylistId={stylistId}
          serviceId={serviceId}
          startTime={selectedSlot}
          startTimeLabel={slotTimeLabel(selectedSlot)}
          date={date}
          stylistName={stylistName}
          serviceName={serviceName}
          slotAvailable={
            !loading
            && !error
            && !availabilityUnknown
            && !interactionDisabled
            && slots.some((slot) => slot.startTime === selectedSlot)
          }
          onCancel={() => setSelectedSlot(null)}
          onSuccess={(freshSlots) => setSlotOverride(freshSlots)}
          onReconcile={reconcileSlots}
          onSubmittingChange={onSubmittingChange}
          onUnsafeToRetry={handleUnsafeSubmission}
          submissionLock={submissionLock}
        />
      )}
    </div>
  );
}
