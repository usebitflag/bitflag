import { computed, ref } from 'vue';

export type ThemePreference = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

export const THEME_STORAGE_KEY = 'bitflag-theme';

/** Unknown or missing stored values fall back to light. */
export function parsePreference(value: unknown): ThemePreference {
  return value === 'dark' || value === 'system' || value === 'light' ? value : 'light';
}

export function resolveTheme(
  preference: ThemePreference,
  systemPrefersDark: boolean,
): ResolvedTheme {
  if (preference === 'system') return systemPrefersDark ? 'dark' : 'light';
  return preference;
}

function readStored(): ThemePreference {
  try {
    return parsePreference(window.localStorage.getItem(THEME_STORAGE_KEY));
  } catch {
    return 'light';
  }
}

function writeStored(preference: ThemePreference): void {
  try {
    window.localStorage.setItem(THEME_STORAGE_KEY, preference);
  } catch {
    // Storage can be blocked; the choice then lasts for this page view only.
  }
}

const query = () => window.matchMedia('(prefers-color-scheme: dark)');

const preference = ref<ThemePreference>('light');
let initialized = false;

function apply(): ResolvedTheme {
  const resolved = resolveTheme(preference.value, query().matches);
  document.documentElement.setAttribute('data-theme', resolved);
  return resolved;
}

/** Reads the stored preference, applies it, and follows the system setting while it is selected. */
export function initTheme(): void {
  if (initialized) return;
  initialized = true;
  preference.value = readStored();
  apply();
  query().addEventListener('change', () => {
    if (preference.value === 'system') apply();
  });
}

/** For tests: forget module state so the next initTheme starts clean. */
export function resetThemeForTests(): void {
  initialized = false;
  preference.value = 'light';
}

export function useTheme() {
  initTheme();

  const resolved = computed<ResolvedTheme>(() => resolveTheme(preference.value, query().matches));

  function setPreference(next: ThemePreference): void {
    preference.value = next;
    writeStored(next);
    apply();
  }

  return { preference, resolved, setPreference };
}
