/**
 * Props the server shares with every Inertia page (App\Http\Middleware\HandleInertiaRequests).
 * Identifiers, names and booleans only.
 */

export type ProjectRole = 'owner' | 'publisher' | 'developer' | 'viewer';
export type OrgRole = 'owner' | 'member';
export type ToastTone = 'neutral' | 'accent';

export interface SharedUser {
  id: string;
  name: string;
  email: string;
  initials: string;
}

export interface SharedEnvironment {
  id: string;
  key: string;
  name: string;
  color: string;
  /** Null until the environment has a published revision. */
  revision: number | null;
}

export interface SharedScope {
  organization: { id: string; slug: string; name: string } | null;
  project: { id: string; key: string; name: string } | null;
  environment: SharedEnvironment | null;
  projectRole: ProjectRole | null;
  orgRole: OrgRole | null;
}

export interface NavigationItem {
  key: string;
  label: string;
  icon: string;
  href: string;
  count: number | null;
  active: boolean;
}

export interface SharedProps {
  auth: { user: SharedUser | null };
  scope: SharedScope;
  environments: SharedEnvironment[];
  navigation: NavigationItem[];
  /** Capability name to allowed. The server always rechecks. */
  can: Partial<Record<string, boolean>>;
  flash: { toast: { message: string; tone: ToastTone } | null };
  requestId: string | null;
}
