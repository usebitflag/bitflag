/** Above this many environments the picker shows a search field. */
export const SEARCH_THRESHOLD = 5;

/** Replaces the environment segment of /{organization}/{project}/{environment}/… and keeps the rest. */
export function urlForEnvironment(path: string, key: string): string {
  const segments = path.split('/').filter(Boolean);
  if (segments.length < 3) return path;
  segments[2] = key;
  return `/${segments.join('/')}`;
}
