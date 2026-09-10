import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { dateKeyInTimeZone, formatTimeWithOffsetInTimeZone } from './date-time';

const PRAGUE = 'Europe/Prague';

describe('business timezone formatting', () => {
  it('derives the business-local date instead of the UTC date', () => {
    assert.equal(dateKeyInTimeZone('2026-09-13T22:30:00+00:00', PRAGUE), '2026-09-14');
  });

  it('applies the business offset in winter and summer', () => {
    assert.equal(
      formatTimeWithOffsetInTimeZone('2026-01-12T08:00:00+00:00', PRAGUE),
      '09:00 GMT+1',
    );
    assert.equal(
      formatTimeWithOffsetInTimeZone('2026-07-13T07:00:00+00:00', PRAGUE),
      '09:00 GMT+2',
    );
  });

  it('distinguishes both occurrences of a repeated time during the autumn DST transition', () => {
    assert.equal(
      formatTimeWithOffsetInTimeZone('2026-10-25T00:30:00+00:00', PRAGUE),
      '02:30 GMT+2',
    );
    assert.equal(
      formatTimeWithOffsetInTimeZone('2026-10-25T01:30:00+00:00', PRAGUE),
      '02:30 GMT+1',
    );
  });
});
