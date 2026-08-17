import { describe, expect, it, vi } from 'vitest';

import {
  NEUTRAL_ROUTE,
  performOrganizationSwitch,
  type SwitchOrganizationSteps,
} from '@/features/auth/session';

/**
 * The five-step organization switch, asserted BY CALL ORDER.
 *
 * This is the spec that goes red when somebody reorders the sequence documented at
 * `src/app/(admin)/settings/page.tsx:4-8`, and order is the whole point: cancelling AFTER the switch
 * lets a pre-switch read resolve into the new context, and replacing the QueryClient BEFORE the switch
 * resolves gives the old organization's observers a fresh cache to refetch into. Neither reordering
 * produces a visible failure — both produce a correctly rendered list belonging to the wrong tenant,
 * which is the silent shape the whole isolation contract is written against.
 *
 * A component test could not assert this: the steps have no rendered consequence at this layer, and
 * per the vitest-playwright boundary table nothing here is coverage for an isolation claim.
 */

interface Recorder {
  readonly calls: string[];
  readonly steps: SwitchOrganizationSteps;
}

function recorder(
  overrides: Partial<SwitchOrganizationSteps> = {},
  members: readonly string[] = ['org-a', 'org-b'],
): Recorder {
  const calls: string[] = [];
  const steps: SwitchOrganizationSteps = {
    isMember: (id) => members.includes(id),
    navigateToNeutralRoute: () => {
      calls.push('navigate');
    },
    cancelQueries: async () => {
      calls.push('cancelQueries');
    },
    switchOrganization: async () => {
      calls.push('switch');
    },
    resetQueryClient: () => {
      calls.push('reset');
    },
    refreshRouterCache: () => {
      calls.push('refresh');
    },
    ...overrides,
  };
  return { calls, steps };
}

describe('the five steps fire in the documented order', () => {
  it('navigate, cancel, switch, REPLACE the client, refresh the Router Cache', async () => {
    const { calls, steps } = recorder();

    await performOrganizationSwitch(steps, 'org-b');

    expect(calls).toEqual(['navigate', 'cancelQueries', 'switch', 'reset', 'refresh']);
  });

  it('awaits the cancel before the switch, rather than firing both and hoping', async () => {
    const calls: string[] = [];
    const { steps } = recorder({
      cancelQueries: async () => {
        await new Promise((resolve) => setTimeout(resolve, 10));
        calls.push('cancelQueries');
      },
      switchOrganization: async () => {
        calls.push('switch');
      },
      resetQueryClient: () => {
        calls.push('reset');
      },
      navigateToNeutralRoute: () => {
        calls.push('navigate');
      },
      refreshRouterCache: () => {
        calls.push('refresh');
      },
    });

    await performOrganizationSwitch(steps, 'org-b');

    expect(calls).toEqual(['navigate', 'cancelQueries', 'switch', 'reset', 'refresh']);
  });

  it('lands on /settings, the route that renders nothing org-scoped', () => {
    expect(NEUTRAL_ROUTE).toBe('/settings');
  });
});

describe('step 0 is a guard, not a formality', () => {
  it('performs ZERO steps for an id the server never handed us', async () => {
    const { calls, steps } = recorder();

    await performOrganizationSwitch(steps, 'org-not-mine');

    // Not "fails gracefully" — does nothing at all. The body of this request carries
    // `organization_id`, which is an ownership column, and the only reason that is legitimate is that
    // the value came from the server's own membership list and is re-checked here before it is sent.
    expect(calls).toEqual([]);
  });

  it('performs ZERO steps when the membership list is empty', async () => {
    const { calls, steps } = recorder({}, []);

    await performOrganizationSwitch(steps, 'org-a');

    expect(calls).toEqual([]);
  });
});

describe('a failed switch does not touch either cache', () => {
  it('skips REPLACE and refresh when the mutation rejects', async () => {
    const { calls, steps } = recorder({
      switchOrganization: async () => {
        calls.push('switch');
        throw new Error('403');
      },
    });

    await expect(performOrganizationSwitch(steps, 'org-b')).rejects.toThrow('403');

    // Resetting the client on a failure would discard the error the user has to read, and refreshing
    // the Router Cache would suggest something changed when nothing did.
    expect(calls).toEqual(['navigate', 'cancelQueries', 'switch']);
  });

  it('propagates rather than swallowing, so the hook can record the error', async () => {
    const boom = new Error('nope');
    const { steps } = recorder({
      switchOrganization: vi.fn().mockRejectedValue(boom),
    });

    await expect(performOrganizationSwitch(steps, 'org-b')).rejects.toBe(boom);
  });
});
