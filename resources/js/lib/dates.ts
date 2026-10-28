/** "Aug 14", or "Feb 14, 2027" when the date is not in the current year. */
export function shortDate(seconds: number, now: Date = new Date()): string {
  const date = new Date(seconds * 1000);
  return date.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    ...(date.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }),
  });
}

/** "12 s ago", "2 min ago", "2 h ago", "21 d ago": how long ago something happened, to one unit. */
export function ago(seconds: number, now: Date = new Date()): string {
  const elapsed = Math.max(0, Math.floor(now.getTime() / 1000) - seconds);
  if (elapsed < 60) return `${elapsed} s ago`;
  if (elapsed < 3600) return `${Math.floor(elapsed / 60)} min ago`;
  if (elapsed < 86400) return `${Math.floor(elapsed / 3600)} h ago`;
  return `${Math.floor(elapsed / 86400)} d ago`;
}
