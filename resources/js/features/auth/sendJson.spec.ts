import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, sendJson } from './sendJson';

function respond(status: number, body: unknown): void {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({ ok: status < 400, status, json: () => Promise.resolve(body) }),
  );
}

describe('sendJson', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('returns the body of a successful response and sends the CSRF token', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D';
    respond(200, { ok: true });

    await expect(sendJson('POST', '/x', { a: 1 })).resolves.toEqual({ ok: true });

    const [, init] = vi.mocked(fetch).mock.calls[0] ?? [];
    expect((init?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');
    expect(init?.body).toBe('{"a":1}');
  });

  it('turns a validation failure into field messages', async () => {
    respond(422, {
      error: {
        message: 'The given data was invalid.',
        details: { fields: [{ path: 'code', message: 'Nope' }] },
      },
    });

    const error = await sendJson('POST', '/x').catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(422);
    expect((error as ApiError).first('code')).toBe('Nope');
  });

  it('gives a general message when the body is not the envelope', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 500,
        json: () => Promise.reject(new Error('html')),
      }),
    );

    const error = await sendJson('POST', '/x').catch((e: unknown) => e);

    expect((error as ApiError).message).toBe('Something went wrong. Try again.');
    expect((error as ApiError).fields).toEqual({});
  });
});
