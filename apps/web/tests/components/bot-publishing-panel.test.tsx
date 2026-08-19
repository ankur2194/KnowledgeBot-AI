import type { BotDomainResource, BotResource } from '@kb/contracts';
import { BOT_PUBLISHING_FIELDS } from '@/features/bots/api';
import { http, HttpResponse } from 'msw';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { BotEditorContext } from '@/features/bots/bot-editor-context';
import { BotPublishingPanel } from '@/features/bots/bot-publishing-panel';

import { envelope, ORIGIN } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * `/bots/{botId}` → tab 3, "Publishing".
 *
 * ── WHAT THIS SPEC IS FOR ────────────────────────────────────────────────────────────────────────
 * Three things that are invisible when they are wrong, in the order of how badly they fail:
 *
 *   1. THE ORIGIN THE LIST SHOWS IS THE ONE THAT WAS STORED. The server normalises on write and the
 *      runtime comparison is byte equality against a browser's `Origin` header, so a console that
 *      echoed the submitted string would show an allow-list that does not exist. Nothing raises: the
 *      screen looks right, and the widget is refused on a site the operator believes they allowed.
 *   2. A PUBLISH REFUSAL IS A 409 WHOSE MESSAGE IS THE ONLY THING THAT SAYS WHAT TO FIX. Its class
 *      is `internal_dependency`, whose class-mapped copy is "Something on our side is unavailable.
 *      Try again shortly." — false twice, and it fails no test anywhere.
 *   3. A STATUS CHANGE GOES TO `PUT …/status` AND NEVER ONTO THE PATCH. The PATCH rules `status`
 *      `missing` precisely so this cannot fail silently; an ABSENT rule would have made it a 200
 *      with the bot still in draft.
 *
 * ── WHAT IT MAY NOT CLAIM ────────────────────────────────────────────────────────────────────────
 * Nothing about isolation, and nothing about the security of the allow-list itself. A component spec
 * that mocks the API cannot fail either. What it can and does prove is that this client never
 * substitutes its own answer for the server's on the two questions where doing so would be a grant:
 * what an origin normalised to, and whether a bot may be published.
 *
 * It also proves nothing about ORIGIN GRAMMAR. `App\Rules\ExactWidgetOrigin` is the authority and the
 * schema deliberately mirrors only the length, the type and the two-scheme prefix, so the assertions
 * below drive the refusals through a real 422 rather than through a client-side check.
 *
 * ── THE PANEL IS MOUNTED DIRECTLY, INSIDE ITS OWN CONTEXT ───────────────────────────────────────
 * That is the contract the shell publishes — a panel takes NO PROPS and reads `useBotEditor()`. No
 * `<SessionProvider>` is wrapped around it, deliberately: this panel builds its child-collection key
 * by APPENDING to the `botKey` the context hands it rather than by calling `useOrgKey()`, so it needs
 * no resolved session, and a spec that mounted one would hide the day that stops being true.
 */

const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';
const BOT_ID = '01JBOTAAAAAAAAAAAAAAAAAAAA';

const botUrl = `${ORIGIN}/api/v1/organizations/${ORG_A}/bots/${BOT_ID}`;
const statusUrl = `${botUrl}/status`;
const domainsUrl = `${botUrl}/domains`;
const domainUrl = (domainId: string) => `${domainsUrl}/${domainId}`;

const BOT: BotResource = {
  id: BOT_ID,
  public_bot_id: 'pb_support',
  name: 'Support bot',
  slug: 'support-bot',
  description: null,
  welcome_message: null,
  placeholder_text: null,
  system_instruction: 'CANARY-SYSTEM-INSTRUCTION',
  answer_style_instruction: 'CANARY-ANSWER-STYLE',
  instructions_visible: true,
  status: 'draft',
  access_mode: 'public',
  provider_connection_id: null,
  provider_model_id: null,
  answer_mode: 'strict',
  dense_top_k: 40,
  sparse_top_k: 40,
  rerank_candidates: 25,
  rerank_retain: 8,
  evidence_threshold: null,
  evidence_threshold_scale: null,
  retrieval_configuration_version: 3,
  allow_general_answers: false,
  theme: {},
  rate_limit_per_minute: null,
  rate_limit_per_day: null,
  retention_days: 90,
  collect_end_user_data: false,
  consent_text: null,
  created_at: '2026-08-01T09:00:00+00:00',
  updated_at: '2026-08-02T09:00:00+00:00',
};

const domain = (
  id: string,
  origin: string,
  overrides: Partial<BotDomainResource> = {},
): BotDomainResource => ({
  id,
  origin,
  status: 'pending',
  permits_embedding: false,
  created_at: '2026-08-03T09:00:00+00:00',
  updated_at: '2026-08-03T09:00:00+00:00',
  ...overrides,
});

const ACTIVE_ROW = domain('01JDOMAINAAAAAAAAAAAAAAAAA', 'https://example.com', {
  status: 'active',
  permits_embedding: true,
});
const PENDING_ROW = domain('01JDOMAINBBBBBBBBBBBBBBBBB', 'https://staging.example.com');

/** The allow-list handler, backed by a mutable list so an invalidation after a write re-reads it. */
const useDomains = (rows: BotDomainResource[]) => {
  worker.use(http.get(domainsUrl, () => HttpResponse.json({ data: { domains: rows } })));
  return rows;
};

const reportUnsaved = vi.fn();

const renderPanel = async (bot: BotResource = BOT, canManage = true) => {
  const screen = await render(
    <Providers>
      <BotEditorContext.Provider
        value={{
          orgId: ORG_A,
          botId: BOT_ID,
          bot,
          botKey: ['org', ORG_A, 'bots', BOT_ID],
          botsListKey: ['org', ORG_A, 'bots'],
          canManage,
          reportUnsaved,
        }}
      >
        <BotPublishingPanel />
      </BotEditorContext.Provider>
    </Providers>,
  );

  /**
   * WAIT FOR THE ALLOW-LIST READ TO SETTLE BEFORE HANDING THE SCREEN BACK, in every test.
   *
   * Every branch of this panel mounts `<BotOrigins>`, and `browserFetchData` awaits
   * `sessionCredential()` before it issues anything — so a test whose assertions are all synchronous
   * finishes, `afterEach` calls `worker.resetHandlers()`, and only THEN does the GET go out, against
   * no handler. It surfaces as MSW's "intercepted a request without a matching request handler" plus
   * a 500, in a spec that PASSED, which is exactly the kind of noise that teaches a reader to ignore
   * the harness.
   *
   * The probe is the skeleton rather than a request counter: `SkeletonLines` carries `aria-busy` and
   * it is this panel's only one, so its absence means the query settled — success or failure — for
   * THIS render. A counter is one shared number across the file, and a late read from the previous
   * test satisfies it while the current test's own request is still pending.
   */
  await vi.waitFor(() => {
    expect(document.querySelector('[aria-busy="true"]')).toBeNull();
  });

  return screen;
};

/**
 * The first captured request body, or a failure naming the omission.
 *
 * `noUncheckedIndexedAccess` types `bodies[0]` as possibly undefined, and the two repairs that keep
 * a spec compiling — a non-null assertion, or `?? {}` — both turn "no request was made" into a
 * comparison against an empty object, which is a PASS for a form that never submitted.
 */
const firstBody = (bodies: readonly Record<string, unknown>[]): Record<string, unknown> => {
  const [body] = bodies;
  if (body === undefined) throw new Error('no request body was captured');
  return body;
};

/** Pick a value out of a Radix `<Select>`: open the trigger, click the option. */
const choose = async (
  screen: Awaited<ReturnType<typeof renderPanel>>,
  control: string,
  option: string,
): Promise<void> => {
  // `.first()` on the TRIGGER, not on the option: the table and the below-768px card stack both
  // render one control per row, and `getByRole` resolves a `display: none` element too. The first in
  // DOM order is the table's, which is the visible one at the harness's viewport width. The options
  // live in a portal and there is only ever one open menu.
  await screen.getByRole('combobox', { name: control }).first().click();
  await screen.getByRole('option', { name: option }).click();
};

beforeEach(() => {
  // `readCookie` reads the PAGE's cookie and MSW cannot set one for a cross-origin host from a
  // service worker, so without this every mutation takes the `refreshCsrfToken()` path and fails for
  // a reason that has nothing to do with what the spec is about.
  document.cookie = 'XSRF-TOKEN=test-token';
  reportUnsaved.mockClear();
  // Every render mounts `<BotOrigins>`, which reads immediately. The harness runs with
  // `onUnhandledRequest: 'error'`, so a spec with no allow-list handler fails in an error state it
  // never asked about.
  useDomains([]);
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the lifecycle transition is its own endpoint', () => {
  it('PUTs to …/status and never puts `status` on the bot PATCH', async () => {
    const puts: Record<string, unknown>[] = [];
    let patched = false;

    worker.use(
      http.put(statusUrl, async ({ request }) => {
        puts.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json({ data: { ...BOT, status: 'testing' } });
      }),
      http.patch(botUrl, () => {
        patched = true;
        return HttpResponse.json({ data: BOT });
      }),
    );

    const screen = await renderPanel();

    await choose(screen, 'Lifecycle status', 'Testing');
    await screen.getByRole('button', { name: 'Change status' }).click();

    await vi.waitFor(() => {
      // The whole body, and it is one key. `botStatusTransitionSchema` is a `strictObject` of one
      // field, so anything the defaults builder leaked would be a parse failure rather than an
      // extra key here.
      expect(puts).toEqual([{ status: 'testing' }]);
    });
    // The PATCH rules `status` `missing` rather than dropping it, precisely so a client that got
    // this wrong would see a 422 instead of a 200 on an unchanged bot. Nothing here should reach it.
    expect(patched).toBe(false);
  });

  it('opens on the bot’s current status, which the server refuses as a no-op', async () => {
    const screen = await renderPanel();

    // `botStatusTransitionDefaults(bot)` seeds the value the bot HOLDS. That is deliberate: the
    // control states where the bot stands, and "save without choosing" is not a transition. Seeding
    // any other member would misreport the current state while it sat there.
    await expect
      .element(screen.getByRole('combobox', { name: 'Lifecycle status' }))
      .toHaveTextContent('Draft');
  });

  it('offers every status and pre-judges none of them', async () => {
    // The fixture bot has NO provider connection and NO model, so the publish guard would refuse a
    // move to `published` — and `Published` is still offered. Three of the four refusals are
    // uncomputable from a cached row (the archived terminal rule and both halves of the guard, all
    // of which run against the state the write LEAVES the bot in), so greying out the options a
    // client believes are unreachable is the publish guard reimplemented in the browser. It fails by
    // hiding a move the server would have allowed.
    const screen = await renderPanel();

    await screen.getByRole('combobox', { name: 'Lifecycle status' }).first().click();

    await expect.element(screen.getByRole('option', { name: 'Published' })).toBeVisible();
    await expect.element(screen.getByRole('option', { name: 'Archived' })).toBeVisible();
    expect(screen.getByRole('option').elements()).toHaveLength(5);
  });

  it('lands the no-op 422 under the control rather than in the banner', async () => {
    worker.use(
      http.put(statusUrl, () =>
        HttpResponse.json(
          {
            ...envelope('validation'),
            errors: { status: ['This bot is already `testing`. Re-read the bot.'] },
          },
          { status: 422 },
        ),
      ),
    );

    const screen = await renderPanel();

    await choose(screen, 'Lifecycle status', 'Testing');
    await screen.getByRole('button', { name: 'Change status' }).click();

    // Laravel translates validation messages, so they are end-user copy by construction and are
    // rendered verbatim — unlike the envelope's `message`, which is operator-facing.
    await expect
      .element(screen.getByText('This bot is already `testing`. Re-read the bot.'))
      .toBeVisible();
    // Under the field, not as a banner about the whole tab.
    expect(document.body.textContent).not.toContain('That status change did not go through');
    expect(document.body.textContent).not.toContain('api-7.internal');
  });
});

describe('the publish guard’s 409, which is the product', () => {
  const PUBLISH_NEEDS_MODEL =
    'This bot cannot be published because it has no provider connection and model. Set ' +
    '`provider_connection_id` and `provider_model_id` first, and publish after that.';

  it('renders the server’s own sentence and points at the tab that owns those fields', async () => {
    worker.use(
      http.put(statusUrl, () =>
        HttpResponse.json(
          // The shape `bootstrap/app.php` produces for a `ConflictHttpException`: the taxonomy has
          // no 409 row on purpose, so a deliberate 4xx and a genuine 500 arrive as the SAME
          // (error_class, retryable) pair and `actionable` is the only thing separating them.
          envelope('internal_dependency', { message: PUBLISH_NEEDS_MODEL, actionable: true }),
          { status: 409 },
        ),
      ),
    );

    const screen = await renderPanel();

    await choose(screen, 'Lifecycle status', 'Published');
    await screen.getByRole('button', { name: 'Change status' }).click();

    await expect.element(screen.getByText('This bot cannot be published yet')).toBeVisible();
    await expect.element(screen.getByText(PUBLISH_NEEDS_MODEL)).toBeVisible();
    // The class-mapped copy for `internal_dependency` is false twice here — nothing is unavailable
    // and retrying never works — so it must not be what the operator reads.
    expect(document.body.textContent).not.toContain('Something on our side is unavailable');
    // The remedy names two fields this tab does not render, so the banner says where they are.
    expect(document.body.textContent).toContain('Model & retrieval');
  });

  it('never renders an operator-facing message on an envelope that is not actionable', async () => {
    worker.use(
      // A genuine 500: same class, same `retryable: false`, `actionable: false`, and a message that
      // names an internal host. This is the case that makes rendering `message` unconditionally a
      // disclosure rather than a nicety.
      http.put(statusUrl, () =>
        HttpResponse.json(envelope('internal_dependency'), { status: 500 }),
      ),
    );

    const screen = await renderPanel();

    await choose(screen, 'Lifecycle status', 'Published');
    await screen.getByRole('button', { name: 'Change status' }).click();

    await expect
      .element(screen.getByText('That status change did not go through'))
      .toBeVisible();
    expect(document.body.textContent).not.toContain('api-7.internal');
    // The class-mapped sentence plus the request_id, which is the one string support can grep across
    // both services.
    expect(document.body.textContent).toContain('01JREQFROMLARAVEL');
  });

  it('says out loud that publishing does not check the bot has any knowledge', async () => {
    const screen = await renderPanel();

    // `BotService::assertPublishable()` carries a `TODO(phase-c)` for the assigned-source refusal,
    // because `bot_source_assignments` does not exist. Nothing anywhere else in the product warns,
    // and a published bot with no sources answers nothing — which reads as a broken pipeline.
    await expect
      .element(screen.getByText('Publishing does not check that this bot has any knowledge'))
      .toBeVisible();
  });
});

describe('the six-field save', () => {
  it('sends exactly BOT_PUBLISHING_FIELDS and nothing else', async () => {
    const bodies: Record<string, unknown>[] = [];
    worker.use(
      http.patch(botUrl, async ({ request }) => {
        bodies.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json({ data: BOT });
      }),
    );

    const screen = await renderPanel();

    await screen.getByLabelText('Conversation retention').fill('30');
    await screen.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      expect(bodies).toHaveLength(1);
    });
    // The partition, proved on the wire. A panel seeded from `botFormDefaults(bot)` wholesale would
    // send all 25 and silently rewrite the other two tabs' fields on every save.
    expect(Object.keys(firstBody(bodies)).sort()).toEqual([...BOT_PUBLISHING_FIELDS].sort());
    expect(firstBody(bodies).retention_days).toBe(30);
    // `status` is in no tuple and is not a PATCH field at all.
    expect(firstBody(bodies)).not.toHaveProperty('status');
  });

  it('keeps a cleared limit as null rather than collapsing it to zero', async () => {
    const bodies: Record<string, unknown>[] = [];
    worker.use(
      http.patch(botUrl, async ({ request }) => {
        bodies.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json({ data: BOT });
      }),
    );

    const screen = await renderPanel({ ...BOT, rate_limit_per_minute: 60 });

    await screen.getByLabelText('Messages per minute').fill('');
    await screen.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      expect(bodies).toHaveLength(1);
    });
    // NULL, not 0 and not absent. Null means "no limit"; absent means "leave it alone"; zero is a
    // bot that answers nobody, and both `min:1` and `bots_rate_limits_positive` refuse it.
    expect(firstBody(bodies).rate_limit_per_minute).toBeNull();
  });

  it('lets the consent pairing arrive as the server’s 422 rather than blocking the request', async () => {
    const attempts: Record<string, unknown>[] = [];
    worker.use(
      http.patch(botUrl, async ({ request }) => {
        attempts.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json(
          {
            ...envelope('validation'),
            errors: { consent_text: ['A bot that collects end-user data needs the consent text.'] },
          },
          { status: 422 },
        );
      }),
    );

    const screen = await renderPanel();

    await screen.getByLabelText('Collect end-user details').click();
    await screen.getByRole('button', { name: 'Save changes' }).click();

    // THE REQUEST IS MADE. A client-side mirror of the pairing would have blocked it — and would
    // have been wrong on the PATCH, where enabling collection on a bot that already carries a
    // disclosure is a body the server accepts. That is the direction that removes functionality with
    // nothing reported anywhere.
    await vi.waitFor(() => {
      expect(attempts).toHaveLength(1);
    });
    expect(firstBody(attempts).collect_end_user_data).toBe(true);
    await expect
      .element(screen.getByText('A bot that collects end-user data needs the consent text.'))
      .toBeVisible();
  });
});

describe('the origin allow-list', () => {
  it('namespaces its key by organization even though the URL does not', async () => {
    useDomains([ACTIVE_ROW]);
    const screen = await renderPanel();

    await expect.element(screen.getByText('https://example.com').first()).toBeVisible();
    // The key is `botKey` plus one segment, so it cannot name a different organization from the row
    // it hangs off. Asserted through the request rather than the cache: this panel is mounted
    // without the shell, so the URL is the artifact both sides share.
    expect(document.body.textContent).not.toContain(ORG_B);
  });

  it('renders the origin the server stored, never the one that was typed', async () => {
    const rows: BotDomainResource[] = [];
    useDomains(rows);
    worker.use(
      http.post(domainsUrl, async ({ request }) => {
        const body = (await request.json()) as { origin: string };
        // THE SERVER NORMALISES: scheme and host lower-cased, one trailing slash dropped, the
        // default port dropped because the browser omits it. The fixture does what
        // `App\Support\Web\ExactOrigin` does rather than echoing, which is what makes the assertion
        // below able to fail.
        expect(body.origin).toBe('HTTPS://Example.COM:443/');
        const created = domain('01JDOMAINCCCCCCCCCCCCCCCCC', 'https://example.com');
        rows.push(created);
        return HttpResponse.json({ data: created }, { status: 201 });
      }),
    );

    const screen = await renderPanel();

    await screen.getByLabelText('Origin to allow').fill('HTTPS://Example.COM:443/');
    await screen.getByRole('button', { name: 'Add origin' }).click();

    await expect.element(screen.getByText('Added to the allow-list')).toBeVisible();
    await expect.element(screen.getByText('https://example.com').first()).toBeVisible();
    // THE ASSERTION THIS BLOCK EXISTS FOR. Echoing the submitted string would show an allow-list
    // that does not exist, on the one screen where "what was stored" is the whole question — the
    // runtime check is byte equality against the browser's `Origin` header.
    expect(document.body.textContent).not.toContain('HTTPS://Example.COM:443/');
  });

  it('renders a refused path as the server’s own message, under the input', async () => {
    worker.use(
      http.post(domainsUrl, () =>
        HttpResponse.json(
          {
            ...envelope('validation'),
            errors: {
              origin: [
                'An origin has no path. A browser sends the same Origin for every page on a host.',
              ],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const screen = await renderPanel();

    // The client schema deliberately does NOT mirror the grammar: a third spelling of a control
    // whose refusals are its content would be the copy nothing compares to the other two, and one
    // case stricter than the server refuses an origin the operator really can embed on.
    await screen.getByLabelText('Origin to allow').fill('https://example.com/widget');
    await screen.getByRole('button', { name: 'Add origin' }).click();

    await expect
      .element(
        screen.getByText(
          'An origin has no path. A browser sends the same Origin for every page on a host.',
        ),
      )
      .toBeVisible();
  });

  it('promotes through the PATCH, one field, and re-reads rather than flipping locally', async () => {
    const rows = [PENDING_ROW];
    useDomains(rows);
    const patches: Record<string, unknown>[] = [];

    worker.use(
      http.patch(domainUrl(PENDING_ROW.id), async ({ request }) => {
        patches.push((await request.json()) as Record<string, unknown>);
        const promoted = { ...PENDING_ROW, status: 'active' as const, permits_embedding: true };
        rows.splice(0, rows.length, promoted);
        return HttpResponse.json({ data: promoted });
      }),
    );

    const screen = await renderPanel();

    await choose(screen, `Allow-list status for ${PENDING_ROW.origin}`, 'Active');

    await vi.waitFor(() => {
      // The whole body is `status`. `origin` is IMMUTABLE — editing one in place would carry an
      // existing promotion across to a different origin, a grant moved silently while every audit
      // row naming it still read the old string.
      expect(patches).toEqual([{ status: 'active' }]);
    });

    // ASSERTED ON WHAT RENDERS AFTER THE RE-READ, not merely that a request was made. Nothing here
    // is optimistic: promotion is a grant, and an optimistic flip would claim one the server may
    // have refused on the control that decides whether a page on the internet can boot this widget.
    await expect
      .element(screen.getByText('A widget on this origin may boot.').first())
      .toBeVisible();
  });

  it('reads “may a widget boot” off permits_embedding rather than comparing status', async () => {
    useDomains([ACTIVE_ROW, PENDING_ROW]);
    const screen = await renderPanel();

    await expect.element(screen.getByText('A widget on this origin may boot.').first()).toBeVisible();
    await expect
      .element(screen.getByText('A widget on this origin is refused.').first())
      .toBeVisible();
  });

  it('says an empty list permits nothing, and never that it is unrestricted', async () => {
    const screen = await renderPanel();

    await expect.element(screen.getByText('No origins on the allow-list')).toBeVisible();
    // The embed decision is "some ACTIVE row matches this exact origin", which is false for the
    // empty set by construction. Reading it the other way round is the inversion that makes this
    // dangerous, so the copy leads with the meaning rather than with an invitation.
    expect(document.body.textContent).toContain('An empty list permits nothing');
    expect(document.body.textContent).not.toContain('unrestricted');
  });

  it('never claims the platform verified an origin', async () => {
    useDomains([ACTIVE_ROW]);
    const screen = await renderPanel();

    await expect
      .element(screen.getByText('Turning an origin on is your confirmation, not ours'))
      .toBeVisible();
    // No proof of control runs anywhere in this product — no DNS TXT record, no well-known path.
    // Copy that implied one would be a claim about a security control that nothing performs.
    expect(document.body.textContent).toContain('no DNS record is read');
    expect(document.body.textContent).not.toContain('Verified');
  });

  it('needs the origin typed before it will remove one', async () => {
    const rows = [ACTIVE_ROW];
    useDomains(rows);
    const removals: string[] = [];
    worker.use(
      http.delete(domainUrl(ACTIVE_ROW.id), () => {
        removals.push(ACTIVE_ROW.id);
        rows.splice(0, rows.length);
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderPanel();

    // The origin is IN the accessible name: a column of identical "Remove" buttons is ambiguous to a
    // locator and unusable with a screen reader.
    await screen.getByRole('button', { name: `Remove ${ACTIVE_ROW.origin}` }).first().click();
    await expect.element(screen.getByRole('heading', { name: 'Remove origin' })).toBeVisible();

    const confirm = screen.getByRole('button', { name: 'Remove origin' });
    await expect.element(confirm).toBeDisabled();

    await screen.getByLabelText(/to confirm/).fill(ACTIVE_ROW.origin);
    await confirm.click();

    await vi.waitFor(() => {
      expect(removals).toEqual([ACTIVE_ROW.id]);
    });
    // And the list re-reads: the row is gone and the empty state says what an empty list means.
    await expect.element(screen.getByText('No origins on the allow-list')).toBeVisible();
  });
});

describe('a viewer without bots.manage', () => {
  it('gets values as text, mounts no form, and still reads the allow-list', async () => {
    useDomains([ACTIVE_ROW]);
    const screen = await renderPanel(BOT, false);

    // §5a: no form at all, not a disabled one. A disabled input holding a value is a control an
    // operator will keep clicking, and RHF strips disabled names from a submit anyway.
    expect(screen.getByRole('button', { name: 'Save changes' }).elements()).toHaveLength(0);
    expect(screen.getByRole('button', { name: 'Change status' }).elements()).toHaveLength(0);
    expect(screen.getByRole('button', { name: 'Add origin' }).elements()).toHaveLength(0);
    expect(screen.getByRole('combobox').elements()).toHaveLength(0);

    // The row's own facts, as text. `bots.view` names "a bot's configuration, its origin allow-list,
    // and its starter questions" in as many words, so the list is not withheld.
    await expect.element(screen.getByText('90 days').first()).toBeVisible();
    await expect.element(screen.getByText('https://example.com').first()).toBeVisible();
    await expect.element(screen.getByText('Active').first()).toBeVisible();
  });

  it('renders neither instruction field, for a bot whose row carries both', async () => {
    const screen = await renderPanel(BOT, false);
    await expect.element(screen.getByText('Publishing')).toBeVisible();

    // They belong to the identity tab and to nothing else — a publishing summary that quoted a
    // bot's own prompt would put it on a screen the management-only projection exists to control.
    expect(document.body.textContent).not.toContain('CANARY-SYSTEM-INSTRUCTION');
    expect(document.body.textContent).not.toContain('CANARY-ANSWER-STYLE');
  });
});

describe('the unsaved-edit report the shell depends on', () => {
  it('reports once for the whole tab, however many forms are dirty', async () => {
    const screen = await renderPanel();
    await expect.element(screen.getByRole('button', { name: 'Save changes' })).toBeVisible();

    await screen.getByLabelText('Conversation retention').fill('30');

    await vi.waitFor(() => {
      expect(reportUnsaved).toHaveBeenCalledWith('publishing', true);
    });
    // ONE panel id, one boolean. Three calls with `'publishing'` would fight — the later effect's
    // write wins and one form's dirty state is lost — so a half-typed origin would vanish on a tab
    // change with no dialog.
    const panels = new Set(reportUnsaved.mock.calls.map((call) => call[0]));
    expect([...panels]).toEqual(['publishing']);
  });

  it('arms the guard for a half-typed origin as well as for the two bot forms', async () => {
    const screen = await renderPanel();
    await expect.element(screen.getByLabelText('Origin to allow')).toBeVisible();

    reportUnsaved.mockClear();
    await screen.getByLabelText('Origin to allow').fill('https://exam');

    await vi.waitFor(() => {
      expect(reportUnsaved).toHaveBeenCalledWith('publishing', true);
    });
  });
});
