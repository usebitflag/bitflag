import { usePage } from '@inertiajs/vue3';
import type { CapabilityName } from '../types/capabilities';
import type { SharedProps } from '../types/shared';

/**
 * Whether the signed-in person holds a capability in the current scope. This only shapes the interface: the server
 * rechecks every action.
 */
export function useCan() {
  const page = usePage<SharedProps>();
  return (capability: CapabilityName): boolean => page.props.can[capability] === true;
}
