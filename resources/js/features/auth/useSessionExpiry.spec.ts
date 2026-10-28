import { describe, expect, it } from 'vitest';
import { readSessionExpiry } from './useSessionExpiry';

describe('reading an ended session', () => {
  it('takes the message from a 409 SESSION_EXPIRED envelope', () => {
    const found = readSessionExpiry(409, {
      error: {
        code: 'SESSION_EXPIRED',
        message: 'Your session ended after 12 hours of inactivity.',
      },
    });

    expect(found).toEqual({ message: 'Your session ended after 12 hours of inactivity.' });
  });

  it('reads the envelope when it arrives as text', () => {
    const body = JSON.stringify({ error: { code: 'SESSION_EXPIRED', message: 'Ended.' } });

    expect(readSessionExpiry(409, body)).toEqual({ message: 'Ended.' });
  });

  it('treats a 419 as an ended session', () => {
    expect(readSessionExpiry(419, '<html>Page expired</html>')).toEqual({
      message: 'Your session ended.',
    });
  });

  it('ignores every other error', () => {
    expect(readSessionExpiry(409, { error: { code: 'VERSION_CONFLICT' } })).toBeNull();
    expect(readSessionExpiry(500, { error: { code: 'SESSION_EXPIRED' } })).toBeNull();
    expect(readSessionExpiry(404, 'not found')).toBeNull();
    expect(readSessionExpiry(409, null)).toBeNull();
  });
});
