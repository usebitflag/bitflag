import { afterEach, describe, expect, it } from 'vitest';
import { resetCommandPaletteForTests, useCommandPalette } from './useCommandPalette';

const command = (id: string, label: string, group = 'Navigation') => ({
  id,
  label,
  group,
  run: () => {},
});

describe('command palette', () => {
  afterEach(resetCommandPaletteForTests);

  it('opens with an empty query and closes', () => {
    const palette = useCommandPalette();
    palette.query.value = 'old';
    palette.open();
    expect(palette.isOpen.value).toBe(true);
    expect(palette.query.value).toBe('');
    palette.toggle();
    expect(palette.isOpen.value).toBe(false);
  });

  it('collects results from every provider, passing the normalized query', () => {
    const palette = useCommandPalette();
    palette.registerProvider('go', (query) => [command('go-flags', `Go to Flags ${query}`)]);
    palette.registerProvider('flags', () => [command('flag-1', 'checkout-v2', 'Flags')]);
    palette.query.value = '  FLA ';
    expect(palette.results.value.map((result) => result.id)).toEqual(['go-flags', 'flag-1']);
    expect(palette.results.value[0]?.label).toBe('Go to Flags fla');
  });

  it('forgets a provider when it unregisters', () => {
    const palette = useCommandPalette();
    const unregister = palette.registerProvider('go', () => [command('a', 'A')]);
    unregister();
    expect(palette.results.value).toEqual([]);
  });
});
