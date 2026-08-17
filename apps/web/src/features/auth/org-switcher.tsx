'use client';

import { KbError } from '@kb/contracts';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { endUserCopy } from '@/lib/forms/apply-server-errors';

import { activeOrganizations } from './session';
import { useSession } from './session-context';
import { useSwitchOrganization } from './use-switch-organization';

/**
 * The organization switcher.
 *
 * RENDERED FROM `src/app/(admin)/settings/page.tsx` AND NOWHERE ELSE. That is not tidiness: `/settings`
 * renders nothing org-scoped, and step 1 of the switch — navigate to a neutral shell first — cannot be
 * awaited because `router.push` is fire-and-forget. Living on the neutral route makes step 1 a no-op
 * and removes the race rather than narrowing it. The nav strip gets `<CurrentOrgBadge/>`, which is
 * read-only text and a link here, never a second switcher.
 *
 * THE ACCESSIBLE NAME IS EXACTLY `Switch organization`, and it is a contract rather than a caption: the
 * Playwright spec the E2E harness owes this batch looks the control up by that name. Note the ROLE is
 * `combobox`, not `button` — Radix's Select trigger sets `role="combobox"` on its `<button>` — so the
 * query is `getByRole('combobox', { name: 'Switch organization' })`.
 *
 * ONLY ACTIVE MEMBERSHIPS ARE OFFERED. `SessionResource.organizations` deliberately lists `invited`
 * and `suspended` memberships too, each with its `status`, so the UI can explain why an organization
 * the user knows about cannot be chosen. Offering one would be offering a 403.
 *
 * RETURNS `null` BELOW TWO ACTIVE MEMBERSHIPS. A single-organization admin gets no control at all
 * rather than a disabled one: there is nothing to switch to, and a control that cannot do anything is
 * a question the user has to answer.
 */
export function OrgSwitcher() {
  const session = useSession();
  const { switchTo, isPending, error } = useSwitchOrganization();

  const organizations = session.status === 'authenticated' ? activeOrganizations(session) : [];
  const currentOrgId = session.status === 'authenticated' ? session.orgId : null;

  if (organizations.length < 2) return null;

  return (
    <div className="space-y-2">
      <Label htmlFor="org-switcher">Organization</Label>
      <Select
        // `value` is the CURRENT org as the server last reported it, so the control follows the
        // session rather than local state. After a successful switch the QueryClient has been
        // replaced, `['session']` refetches, and this re-renders from the new document.
        value={currentOrgId ?? undefined}
        onValueChange={(nextOrgId) => {
          void switchTo(nextOrgId);
        }}
        // Disabled on `loading` as well as `isPending`: step 4 of the switch replaces the QueryClient,
        // which destroys the session query, so this component re-enters `loading` for one round trip
        // immediately after its own success. Without the `loading` arm it would accept a second pick
        // against a membership list it no longer holds.
        disabled={isPending || session.status === 'loading'}
      >
        <SelectTrigger id="org-switcher" aria-label="Switch organization" className="w-full">
          <SelectValue placeholder="Choose an organization" />
        </SelectTrigger>
        <SelectContent>
          {organizations.map((organization) => (
            <SelectItem key={organization.id} value={organization.id}>
              {organization.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      {/* endUserCopy, never the envelope's `message`. A 403 here also invalidated the cached session
          (see use-switch-organization.ts), so the option list the user just picked from is on its way
          out from under them — which is the correct outcome and needs the sentence to explain it. */}
      {error === null ? null : (
        <Alert variant="destructive">
          <AlertDescription>
            {error instanceof KbError
              ? endUserCopy(error)
              : endUserCopy({ error_class: null, request_id: null })}
          </AlertDescription>
        </Alert>
      )}
    </div>
  );
}
