export type EnvironmentKeyType = 'server' | 'browser' | 'management';

export interface KeyKind {
  type: EnvironmentKeyType;
  title: string;
  help: string;
  variable: string;
  scopes: { value: string; label: string; help: string }[];
  defaults: string[];
}

/** The three kinds of key made on an environment's Keys tab, in the order the wizard offers them. */
export const KEY_KINDS: KeyKind[] = [
  {
    type: 'server',
    title: 'Server SDK',
    help: 'For your backend. Reads the full snapshot, so keep it secret.',
    variable: 'BITFLAG_SERVER_KEY',
    scopes: [
      {
        value: 'snapshot:read',
        label: 'Read snapshots',
        help: 'Download the environment snapshot.',
      },
      {
        value: 'evaluate',
        label: 'Evaluate remotely',
        help: 'Ask BitFlag to evaluate flags for a context.',
      },
      { value: 'events:write', label: 'Send events', help: 'Report evaluation summaries.' },
    ],
    defaults: ['snapshot:read'],
  },
  {
    type: 'browser',
    title: 'Browser SDK',
    help: 'For web pages. Sees only flags marked client-visible. Safe to ship in a bundle, limited to the allowed origins.',
    variable: 'BITFLAG_CLIENT_KEY',
    scopes: [
      { value: 'snapshot:read', label: 'Read snapshots', help: 'Download the public snapshot.' },
      { value: 'events:write', label: 'Send events', help: 'Report evaluation summaries.' },
    ],
    defaults: ['snapshot:read'],
  },
  {
    type: 'management',
    title: 'CLI, CI or API',
    help: 'For the CLI and automation. Works in this environment only and belongs to the project.',
    variable: 'BITFLAG_API_KEY',
    scopes: [
      { value: 'flags:read', label: 'Read flags', help: 'Read project and flag data.' },
      {
        value: 'sensitive:read',
        label: 'Read sensitive data',
        help: 'See override keys and include lists.',
      },
      { value: 'flags:write', label: 'Edit drafts', help: 'Change flags and segments in drafts.' },
      {
        value: 'publish',
        label: 'Publish',
        help: 'Submit and publish changes. Protected environments still need a review.',
      },
    ],
    defaults: ['flags:read'],
  },
];

export const EXPIRY_OPTIONS = [
  { value: '', label: 'Never' },
  { value: '30', label: '30 days' },
  { value: '90', label: '90 days' },
  { value: '365', label: '1 year' },
];

export const OVERLAP_OPTIONS = [
  { value: '3600', label: '1 hour' },
  { value: '86400', label: '24 hours' },
  { value: '604800', label: '7 days' },
];
