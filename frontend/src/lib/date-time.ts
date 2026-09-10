type DateValue = Date | string;

const OFFSET_PROBE_HOURS = [-48, -24, -12, 0, 12, 24, 48];

function toDate(value: DateValue): Date {
  return value instanceof Date ? value : new Date(value);
}

export function dateKeyInTimeZone(value: DateValue, timeZone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(toDate(value));

  const part = (type: Intl.DateTimeFormatPartTypes): string =>
    parts.find((candidate) => candidate.type === type)?.value ?? '';

  return `${part('year')}-${part('month')}-${part('day')}`;
}

export function formatTimeInTimeZone(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(iso));
}

export function formatTimeWithOffsetInTimeZone(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
    timeZoneName: 'shortOffset',
  }).format(new Date(iso));
}

export function formatOffsetInTimeZone(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone,
    timeZoneName: 'shortOffset',
  }).formatToParts(new Date(iso)).find((part) => part.type === 'timeZoneName')?.value ?? timeZone;
}

export function isAmbiguousLocalTimeInTimeZone(iso: string, timeZone: string): boolean {
  const instant = new Date(iso);
  const instantOffset = offsetMillisecondsInTimeZone(instant, timeZone);
  const localDateTime = localDateTimeKey(instant, timeZone);

  const nearbyOffsets = new Set(OFFSET_PROBE_HOURS.map((hours) =>
    offsetMillisecondsInTimeZone(new Date(instant.getTime() + hours * 60 * 60 * 1000), timeZone),
  ));

  for (const offset of nearbyOffsets) {
    if (offset === instantOffset) continue;

    const alternative = new Date(instant.getTime() + instantOffset - offset);
    if (localDateTimeKey(alternative, timeZone) === localDateTime) {
      return true;
    }
  }

  return false;
}

export function formatDateInTimeZone(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('cs-CZ', {
    timeZone,
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  }).format(new Date(iso));
}

function offsetMillisecondsInTimeZone(value: Date, timeZone: string): number {
  const offsetName = new Intl.DateTimeFormat('en-GB', {
    timeZone,
    timeZoneName: 'longOffset',
  }).formatToParts(value).find((part) => part.type === 'timeZoneName')?.value;

  if (offsetName === 'GMT') return 0;

  const match = offsetName?.match(/^GMT([+-])(\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!match) throw new RangeError(`Unsupported timezone offset: ${offsetName ?? timeZone}`);

  const sign = match[1] === '+' ? 1 : -1;
  return sign * (Number(match[2]) * 3600 + Number(match[3]) * 60 + Number(match[4] ?? 0)) * 1000;
}

function localDateTimeKey(value: Date, timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(value)
    .filter((part) => part.type !== 'literal')
    .map((part) => `${part.type}:${part.value}`)
    .join('|');
}
