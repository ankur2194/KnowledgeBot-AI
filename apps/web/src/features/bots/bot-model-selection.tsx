'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { useWatch } from 'react-hook-form';

import { ErrorState } from '@/components/states';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useOrgKey } from '@/features/auth/session-context';
import { fetchModels } from '@/features/models/api';
import { connectionStatusLabel, fetchConnections, providerLabel } from '@/features/providers/api';

import { useBotEditor } from './bot-editor-context';
import type { BotSettingsForm } from './bot-model-shared';

/**
 * The "no selection" item's value.
 *
 * A Radix `SelectItem` may not carry an empty string — that value is reserved for clearing the
 * selection and the primitive throws — so the unconfigured state needs a sentinel. It is mapped to
 * `null` in `onValueChange` and never reaches form state, and it cannot collide with a real value:
 * both fields are `ulid`, which is 26 upper-case Crockford base32 characters.
 */
const NOT_CONFIGURED = 'not-configured';

/**
 * WHICH CREDENTIAL ANSWERS, AND WITH WHICH CATALOGUE ROW.
 *
 * ── TWO READS THE SHELL DOES NOT MAKE, AND THEIR KEYS ───────────────────────────────────────────
 * `['org', orgId, 'provider-connections']` and
 * `['org', orgId, 'provider-connections', connectionId, 'models']` — the SAME spellings
 * `providers-screen.tsx` and `models-screen.tsx` build, through the same `useOrgKey()`, so this tab
 * shares their cache entries instead of opening a second namespace for the same rows. Both carry the
 * organization at segment 1 because the organization is in the session cookie and in no URL; on a
 * switch, `useResetQueryClient()` replaces the whole client and this subtree unmounts with it.
 *
 * Neither query sets `retry` — the identifier is an ESLint error outside `lib/query/client.ts` — and
 * both forward `signal`, because `queryClient.cancelQueries()` is a no-op against a `queryFn` that
 * drops it and cancelling in-flight reads is step 2 of both logout and the organization switch.
 *
 * ── THEY ARE MOUNTED ONLY FOR A VIEWER WITH `bots.manage` ──────────────────────────────────────
 * The read-only branch of the panel renders no form and therefore no selector, which is what keeps
 * an analyst — the one role that holds neither `bots.manage` nor `providers.view` — from issuing two
 * requests whose only possible answer is 403.
 *
 * ── THE PAIR IS THE UNIT, NOT THE TWO FIELDS ───────────────────────────────────────────────────
 * A connection with no model is a real state ("vendor chosen, model not yet") and both columns are
 * nullable so it stays expressible; a model with no connection names no credential at all, because a
 * catalogue row reaches one only through its parent.
 *
 * `provider_connection_id` carries `required_with:provider_model_id` — ONE DIRECTION ONLY — on
 * `StoreBotRequest` ALONE. It is correct on create, where the body IS the whole row. It was removed
 * from `UpdateBotRequest`, where it was both wrong and redundant: `sometimes` short-circuits the rule
 * set for an absent key, so it decided only one of the four reachable PATCH shapes and refused a
 * legitimate model-only PATCH against a bot that already stores a connection. On the PATCH the
 * pairing that matters is the RESULTING one, and that is `BotService::assertModelSelection()`'s,
 * judged against the stored row. `botSettingsSchema` mirrors this split: `crossFieldCreate` includes
 * `modelNeedsConnection`, `crossFieldSettings` does not.
 *
 * `BotService::assertModelSelection()` also refuses a model
 * registered under a DIFFERENT connection of the same organization — no constraint on `bots` catches
 * that, and the resulting configuration would name a credential from one account and a model from
 * another. Changing the connection therefore CLEARS the model here rather than leaving a stale pair
 * on screen for the server to reject.
 *
 * ── EVERY ROW IS OFFERED, INCLUDING THE ONES THAT LOOK WRONG ───────────────────────────────────
 * `fetchModels` returns rows whatever their `enabled` state, and `entryExists` — the only predicate
 * the server applies — checks organization, connection and key, and nothing else. So this list
 * neither hides a disabled row nor filters to a chat-capable one: both would make the console
 * STRICTER than the server, which is the drift direction that removes functionality with nothing
 * reported (`rhf-zod-forms` NN3). What the row says about itself is rendered beside it instead.
 */
export function ModelSelectionCard({
  form,
  pending,
}: {
  readonly form: BotSettingsForm;
  readonly pending: boolean;
}) {
  const { orgId } = useBotEditor();
  const orgKeyFor = useOrgKey();

  const selectedConnection = useWatch({ control: form.control, name: 'provider_connection_id' });
  const connectionId = typeof selectedConnection === 'string' ? selectedConnection : null;

  const connections = useQuery({
    queryKey: orgKeyFor('provider-connections'),
    queryFn: ({ signal }) => fetchConnections(orgId, signal),
  });

  const models = useQuery({
    // `connectionId` is null while nothing is chosen, and the entry is never populated because the
    // query is disabled — but it is still built through `orgKeyFor`, so the null case cannot become
    // the one key on this screen that forgot its organization.
    queryKey: orgKeyFor('provider-connections', connectionId, 'models'),
    queryFn: ({ signal }) => {
      if (connectionId === null) {
        throw new Error('the models query ran with no connection selected');
      }
      return fetchModels(orgId, connectionId, signal);
    },
    enabled: connectionId !== null,
  });

  const rows = models.data ?? [];
  const known = connections.data ?? [];
  const storedConnectionMissing =
    connectionId !== null &&
    connections.data !== undefined &&
    !known.some((connection) => connection.id === connectionId);

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">Model</CardTitle>
        <CardDescription>
          The credential this bot&rsquo;s answers are billed to, and the catalogue row registered
          under it.
        </CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {connections.error === null ? null : (
          <ErrorState
            title="Provider connections could not be loaded"
            error={connections.error}
            onRetry={() => void connections.refetch()}
          />
        )}

        <FormField
          control={form.control}
          name="provider_connection_id"
          render={({ field }) => (
            <FormItem>
              <FormLabel htmlFor="bot-provider-connection">Provider connection</FormLabel>
              <Select
                value={connectionId ?? NOT_CONFIGURED}
                onValueChange={(next) => {
                  field.onChange(next === NOT_CONFIGURED ? null : next);
                  // A model belongs to ONE connection. Carrying the old id across is a pair the
                  // server refuses with a message keyed to `provider_model_id` — a 422 the operator
                  // would read as being about the field they did not touch.
                  form.setValue('provider_model_id', null, { shouldDirty: true });
                }}
                disabled={pending || connections.isPending}
              >
                <FormControl>
                  <SelectTrigger
                    id="bot-provider-connection"
                    aria-label="Provider connection"
                    className="w-full"
                  >
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectItem value={NOT_CONFIGURED}>Not configured</SelectItem>
                  {known.map((connection) => (
                    <SelectItem key={connection.id} value={connection.id}>
                      {providerLabel(connection.provider)} · {connection.label} ·{' '}
                      {connectionStatusLabel(connection.status)}
                    </SelectItem>
                  ))}
                  {/* THE STORED ID SURVIVES A LIST THAT NO LONGER CONTAINS IT. A revoked or deleted
                      connection would otherwise render as an empty trigger — the bot silently
                      reading as unconfigured while its row still names a credential. */}
                  {storedConnectionMissing && connectionId !== null ? (
                    <SelectItem value={connectionId} className="font-mono">
                      {connectionId} (not in this organization&rsquo;s list)
                    </SelectItem>
                  ) : null}
                </SelectContent>
              </Select>
              <FormDescription>
                A reference to a stored credential and never key material. Leaving it unconfigured is
                a real state — it is the one every bot starts in — but a bot cannot be published
                without a connection and a model.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        {connectionId === null ? null : (
          <>
            {models.error === null ? null : (
              <ErrorState
                title="This connection’s models could not be loaded"
                error={models.error}
                onRetry={() => void models.refetch()}
              />
            )}

            <FormField
              control={form.control}
              name="provider_model_id"
              render={({ field }) => {
                const modelId = typeof field.value === 'string' ? field.value : null;
                const storedModelMissing =
                  modelId !== null &&
                  models.data !== undefined &&
                  !rows.some((row) => row.id === modelId);

                return (
                  <FormItem>
                    <FormLabel htmlFor="bot-provider-model">Model</FormLabel>
                    <Select
                      value={modelId ?? NOT_CONFIGURED}
                      onValueChange={(next) => {
                        field.onChange(next === NOT_CONFIGURED ? null : next);
                      }}
                      disabled={pending || models.isPending}
                    >
                      <FormControl>
                        <SelectTrigger
                          id="bot-provider-model"
                          aria-label="Model"
                          className="w-full"
                        >
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NOT_CONFIGURED}>Not configured</SelectItem>
                        {rows.map((row) => (
                          <SelectItem key={row.id} value={row.id}>
                            {/* The catalogue's display name AND the model identifier, because the
                                second is the string an operator matches character by character
                                against a vendor dashboard and the first is the one they named. */}
                            {`${row.display_name} · ${row.model}${row.enabled ? '' : ' · not available to bots'}`}
                          </SelectItem>
                        ))}
                        {storedModelMissing && modelId !== null ? (
                          <SelectItem value={modelId} className="font-mono">
                            {modelId} (not registered under this connection)
                          </SelectItem>
                        ) : null}
                      </SelectContent>
                    </Select>
                    <FormDescription>
                      {models.data !== undefined && rows.length === 0 ? (
                        <>
                          This connection has no catalogue rows yet.{' '}
                          <Link
                            href={`/settings/providers/${connectionId}`}
                            className="text-primary underline-offset-4 hover:underline"
                          >
                            Register a model
                          </Link>{' '}
                          first — a bot names a (connection, model) pair and both halves have to
                          exist.
                        </>
                      ) : (
                        'Rows that are not available to bots are listed rather than hidden, because “why is my model missing from this list” has to be answerable from this screen.'
                      )}
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                );
              }}
            />
          </>
        )}

        {/* THE FALLBACK CHAIN IS NOT HERE, AND THIS SAYS SO RATHER THAN LEAVING A HOLE.
            `bot_fallback_models` is a real table naming `provider_models` rows — which credentials
            may be billed for this bot's answers — with composite foreign keys forcing bot and model
            onto the same tenant. It reaches no resource and no endpoint: nothing to read, so nothing
            to render read-only, and a write path would have to be invented. The sentence is about
            the CONSOLE and not about the bot, because "no fallback models are configured" is a claim
            this screen has no way to make. */}
        <p className="text-sm text-muted-foreground">
          The fallback model chain is not editable here. It names which other credentials may be
          billed for this bot&rsquo;s answers, and it has no read or write endpoint yet — so this
          console can neither show you the chain nor change it.
        </p>
      </CardContent>
    </Card>
  );
}
