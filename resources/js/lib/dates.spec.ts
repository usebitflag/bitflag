import { describe, expect, it } from 'vitest';
import { ago, shortDate } from './dates';

const now = new Date('2026-10-28T12:00:00Z');
const at = (iso: string) => Date.parse(iso) / 1000;

describe('shortDate', () => {
  it('leaves out the year for the current year and shows it otherwise', () => {
    expect(shortDate(at('2026-08-14T12:00:00Z'), now)).toBe('Aug 14');
    expect(shortDate(at('2027-02-14T12:00:00Z'), now)).toBe('Feb 14, 2027');
  });
});

describe('ago', () => {
  it.each([
    [12, '12 s ago'],
    [120, '2 min ago'],
    [7200, '2 h ago'],
    [21 * 86400, '21 d ago'],
  ])('says %i seconds as %s', (seconds, text) => {
    expect(ago(Math.floor(now.getTime() / 1000) - seconds, now)).toBe(text);
  });
});
