import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  THEME_STORAGE_KEY,
  initTheme,
  parsePreference,
  resetThemeForTests,
  resolveTheme,
  useTheme,
} from './useTheme';

function mockSystem(prefersDark: boolean) {
  const listeners = new Set<() => void>();
  const media = {
    get matches() {
      return prefersDark;
    },
    addEventListener: (_type: string, listener: () => void) => listeners.add(listener),
    removeEventListener: (_type: string, listener: () => void) => listeners.delete(listener),
  };
  vi.stubGlobal('matchMedia', () => media);
  return {
    change(next: boolean) {
      prefersDark = next;
      listeners.forEach((listener) => listener());
    },
  };
}

const theme = () => document.documentElement.getAttribute('data-theme');

describe('theme preference', () => {
  beforeEach(() => {
    window.localStorage.clear();
    document.documentElement.removeAttribute('data-theme');
    resetThemeForTests();
  });

  afterEach(() => vi.unstubAllGlobals());

  it('parses stored values and falls back to light', () => {
    expect(parsePreference('dark')).toBe('dark');
    expect(parsePreference('system')).toBe('system');
    expect(parsePreference('light')).toBe('light');
    expect(parsePreference('sepia')).toBe('light');
    expect(parsePreference(null)).toBe('light');
  });

  it('resolves system to the operating system setting', () => {
    expect(resolveTheme('system', true)).toBe('dark');
    expect(resolveTheme('system', false)).toBe('light');
    expect(resolveTheme('light', true)).toBe('light');
    expect(resolveTheme('dark', false)).toBe('dark');
  });

  it('defaults to light when nothing is stored', () => {
    mockSystem(true);
    initTheme();
    expect(theme()).toBe('light');
  });

  it('keeps a stored explicit choice', () => {
    mockSystem(false);
    window.localStorage.setItem(THEME_STORAGE_KEY, 'dark');
    initTheme();
    expect(theme()).toBe('dark');
  });

  it('stores and applies a new choice', () => {
    mockSystem(false);
    const { setPreference, preference } = useTheme();
    setPreference('dark');
    expect(preference.value).toBe('dark');
    expect(theme()).toBe('dark');
    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');
  });

  it('follows the system setting only while system is selected', () => {
    const system = mockSystem(false);
    const { setPreference } = useTheme();

    setPreference('system');
    expect(theme()).toBe('light');
    system.change(true);
    expect(theme()).toBe('dark');

    setPreference('light');
    system.change(false);
    system.change(true);
    expect(theme()).toBe('light');
  });

  it('still applies the choice when storage is blocked', () => {
    mockSystem(false);
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const { setPreference } = useTheme();
    expect(() => setPreference('dark')).not.toThrow();
    expect(theme()).toBe('dark');
  });
});
