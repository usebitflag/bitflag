// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { contrast, luminance, mix, parseHex, toHex } from './color.mjs';
import { baseVariables, checkContrast, generate, resolveTheme } from './generate-theme.mjs';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const inputs = () => ({
  nocturneCss: read('design/nocturne/styles.css'),
  tokens: JSON.parse(read('design/bitflag/tokens.json')),
  components: JSON.parse(read('design/bitflag/components.json')),
});

describe('colour maths', () => {
  it('round-trips hex colours', () => {
    expect(toHex(parseHex('#6d4fe0'))).toBe('#6d4fe0');
  });

  it('rejects anything but a 6-digit hex', () => {
    expect(() => parseHex('#fff')).toThrow();
  });

  it('computes the WCAG contrast of black on white as 21:1', () => {
    expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 5);
    expect(luminance('#ffffff')).toBeCloseTo(1, 5);
  });

  it('mixes in sRGB like CSS color-mix', () => {
    expect(mix('#000000', '#ffffff', 50)).toBe('#808080');
    expect(mix('#6d4fe0', '#ffffff', 100)).toBe('#6d4fe0');
    expect(mix('#6d4fe0', '#ffffff', 0)).toBe('#ffffff');
  });
});

describe('theme generation', () => {
  it('passes every required contrast pair in both themes', () => {
    const { failures } = generate(inputs());
    expect(failures).toEqual([]);
  });

  it('resolves the same roles for light and dark', () => {
    const { tokens } = inputs();
    const light = Object.keys(resolveTheme(tokens.themes.light)).sort();
    const dark = Object.keys(resolveTheme(tokens.themes.dark)).sort();
    expect(dark).toEqual(light);
  });

  it('reports a failing pair with the measured ratio', () => {
    const { tokens } = inputs();
    tokens.themes.light.colors.muted = '#c0c0c8';
    const { failures } = generate({ ...inputs(), tokens });
    expect(failures.some((line) => line.includes('muted') && line.includes('needs 4.5:1'))).toBe(
      true,
    );
  });

  it('reports a pair that refers to an unknown role', () => {
    const failures = checkContrast('light', { text: '#000000' }, [
      { fg: 'text', bg: 'nope', min: 3 },
    ]);
    expect(failures[0]).toContain('unknown role');
  });

  it('clears the default palette and maps roles to theme variables', () => {
    const { css } = generate(inputs());
    expect(css).toContain('--color-*: initial;');
    expect(css).toContain('--color-surface: var(--bf-surface);');
    expect(css).toContain(":root[data-theme='dark']");
  });

  it('is deterministic', () => {
    expect(generate(inputs()).css).toBe(generate(inputs()).css);
  });

  it('reads base variables from the Nocturne file', () => {
    const radius = baseVariables(inputs().nocturneCss, 'radius');
    expect(Object.keys(radius)).toEqual(expect.arrayContaining(['sm', 'md', 'lg']));
  });
});
