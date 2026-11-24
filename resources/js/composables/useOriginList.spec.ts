import { describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { normalizeOrigin, originProblem, useOriginList } from './useOriginList';

describe('normalizeOrigin', () => {
  it.each([
    ['https://App.Example.com/', 'https://app.example.com'],
    ['https://app.example.com:443', 'https://app.example.com'],
    ['http://localhost:3000', 'http://localhost:3000'],
    ['http://localhost:80', 'http://localhost'],
  ])('normalizes %s', (input, expected) => {
    expect(normalizeOrigin(input)).toBe(expected);
  });

  it.each([
    'app.example.com',
    'https://app.example.com/path',
    'https://app.example.com?x=1',
    'https://*.example.com',
    'https://',
    'https://example.com:99999',
    'ftp://example.com',
  ])('rejects %s', (input) => {
    expect(normalizeOrigin(input)).toBeNull();
  });
});

describe('originProblem', () => {
  it('names each problem', () => {
    expect(originProblem('http://example.com', [])).toBe(
      'Use https. Plain http is only for localhost.',
    );
    expect(originProblem('http://127.0.0.1:8080', [])).toBeNull();
    expect(originProblem('https://a.example.com', ['https://a.example.com'])).toBe(
      'Already in the list',
    );
    expect(originProblem('nope', [])).toContain('scheme://host');
    expect(
      originProblem(
        'https://x.example.com',
        Array.from({ length: 20 }, (_, i) => `https://h${i}.example.com`),
      ),
    ).toBe('Use at most 20 origins.');
  });
});

describe('useOriginList', () => {
  it('adds a normalized origin, keeps the error for a bad one and removes by value', () => {
    const origins = ref<string[]>([]);
    const list = useOriginList(origins);

    list.draft.value = 'HTTPS://App.example.com/';
    list.add();
    expect(origins.value).toEqual(['https://app.example.com']);
    expect(list.draft.value).toBe('');

    list.draft.value = 'https://app.example.com';
    list.add();
    expect(list.error.value).toBe('Already in the list');
    expect(origins.value).toHaveLength(1);

    list.remove('https://app.example.com');
    expect(origins.value).toEqual([]);
  });
});
