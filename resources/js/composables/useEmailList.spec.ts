import { describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { parseEmails, useEmailList } from './useEmailList';

describe('reading a list of email addresses', () => {
  it('splits on commas, semicolons, spaces and line breaks', () => {
    expect(parseEmails('a@x.dev, b@x.dev;c@x.dev\nd@x.dev e@x.dev').valid).toEqual([
      'a@x.dev',
      'b@x.dev',
      'c@x.dev',
      'd@x.dev',
      'e@x.dev',
    ]);
  });

  it('lower-cases and drops repeats', () => {
    expect(parseEmails('A@X.dev a@x.DEV').valid).toEqual(['a@x.dev']);
  });

  it('separates what is not an address', () => {
    expect(parseEmails('good@x.dev, nope, @x.dev, a@b')).toEqual({
      valid: ['good@x.dev'],
      invalid: ['nope', '@x.dev', 'a@b'],
    });
  });

  it('strips the brackets and quotes around a pasted address', () => {
    expect(parseEmails('<a@x.dev>, "b@x.dev"').valid).toEqual(['a@x.dev', 'b@x.dev']);
  });

  it('is empty for empty text', () => {
    expect(parseEmails('  \n ,, ')).toEqual({ valid: [], invalid: [] });
  });

  it('counts valid addresses live and flags more than the limit', () => {
    const text = ref('a@x.dev, b@x.dev');
    const list = useEmailList(text, 2);

    expect(list.count.value).toBe(2);
    expect(list.overLimit.value).toBe(false);

    text.value += ', c@x.dev';
    expect(list.count.value).toBe(3);
    expect(list.overLimit.value).toBe(true);
  });
});
