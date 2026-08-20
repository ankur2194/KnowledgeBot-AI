import type { OrgUploadLimits } from '@kb/contracts/forms';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type * as NextNavigation from 'next/navigation';
import { act, useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import { UploadScreen } from '@/features/sources/upload-screen';

import { ORIGIN, envelope, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';
import {
  resetNavigation,
  useMockPathname,
  useMockRouter,
  useMockSearchParams,
} from '../support/mock-navigation';

/**
 * `/sources/upload` — the screen that turns the organization's own ceilings into a form.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ────────────────────────────────────────────────────────────────
 * It proves the limits query is org-namespaced, that the four states render, that a per-file 422
 * lands on the row it is about, that a batch-level message reaches the control it describes, and
 * that a completed batch invalidates the source list. It proves NOTHING about isolation: a component
 * test that mocks the API cannot fail an isolation test, and per the `vitest-playwright` boundary
 * table it must never be cited as isolation coverage.
 *
 * It also proves nothing about BYTES MOVING. `xhr.upload.progress` fires zero times under MSW's
 * service worker (asserted as a limitation in `upload-transport.test.tsx`), so the bar's motion is
 * `upload-dropzone.test.tsx`'s claim, against a stub uploader whose progress it drives by hand, and
 * real chunks over a real socket are unproven by this batch on any layer.
 *
 * ── `next/navigation` IS MOCKED BECAUSE THE SCREEN RENDERS `<Link>` ─────────────────────────────
 * `next/link` reads the router context, which throws outside a mounted App Router. The observable
 * store in `tests/support/mock-navigation.ts` is what the rest of the suite already uses.
 *
 * ── NO CSS IS IMPORTED, ON PURPOSE ──────────────────────────────────────────────────────────────
 * Only `design-system-css.test.tsx` imports `globals.css`. Layout and visibility at a given width are
 * CSS's decision and a manual/Playwright check; what is asserted here is structure, names and text.
 *
 * ── EVERY `render` IS AWAITED ───────────────────────────────────────────────────────────────────
 * `vitest-browser-react`'s `render` returns a promise it has wrapped in React's `act()`. An
 * un-awaited one PASSES and then times out every test after it in this file against an empty
 * `<body>`, which reads exactly like a crash in the component and is not one.
 */
vi.mock('next/navigation', async (importOriginal) => ({
  ...(await importOriginal<typeof NextNavigation>()),
  useRouter: () => useMockRouter(),
  usePathname: () => useMockPathname(),
  useSearchParams: () => useMockSearchParams(),
}));

/** `sessionFixture()`'s current organization, where this user is an OWNER. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the same fixture, where this user is an ANALYST — the one role
 *  that holds neither `sources.view` nor `sources.manage`. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

const limitsUrl = (orgId: string) =>
  `${ORIGIN}/api/v1/organizations/${orgId}/sources/upload-limits`;
const sourcesUrl = (orgId: string) => `${ORIGIN}/api/v1/organizations/${orgId}/sources`;

/**
 * ONE ORGANIZATION'S CEILINGS, as the endpoint returns them.
 *
 * The literals live HERE, in a fixture, and never in `src/`: §8.10 makes the cap and the allow-list
 * per-organization, so a byte or MIME constant in the app would enforce a different limit from the
 * organization it is rendering for. `max_bytes` is small enough that a test can exceed it with a
 * `Uint8Array` rather than with a real megabyte.
 */
const LIMITS: OrgUploadLimits = {
  max_bytes: 4096,
  allowed_mime: ['application/pdf', 'text/csv'],
  max_batch: 3,
};

const limitsHandler = (orgId = ORG_A, limits: OrgUploadLimits = LIMITS) =>
  http.get(limitsUrl(orgId), () => HttpResponse.json({ data: limits }));

/**
 * Filenames chosen so no accessible name is a SUBSTRING of another: role and text matching are both
 * case-insensitive substring matches, so `report.pdf` and `report.pdf.bak` make every row query
 * ambiguous and the failure reads as a strict-mode violation rather than as a naming problem.
 */
const ALPHA = (bytes = 32) =>
  new File([new Uint8Array(bytes)], 'alpha-handbook.pdf', { type: 'application/pdf' });
const BRAVO = (bytes = 64) =>
  new File([new Uint8Array(bytes)], 'bravo-ledger.csv', { type: 'text/csv' });

/** Captures the tree's QueryClient so the KEYS themselves can be asserted, not just request URLs —
 *  the organization is in none of this screen's URLs, so the key is the artifact worth reading. The
 *  write happens in an EFFECT: reassigning a module-scope variable from a render body is an error. */
let captured: QueryClient | null = null;
function CaptureClient() {
  const queryClient = useQueryClient();
  useEffect(() => {
    captured = queryClient;
  }, [queryClient]);
  return null;
}

const renderScreen = () =>
  render(
    <Providers>
      <SessionProvider>
        <CaptureClient />
        <UploadScreen />
      </SessionProvider>
    </Providers>,
  );

const cacheKeys = (): unknown[][] =>
  (captured?.getQueryCache().getAll() ?? []).map((query) => [...query.queryKey]);

const fileInput = (): HTMLInputElement => {
  const input = document.querySelector<HTMLInputElement>('input[type="file"]');
  if (input === null) throw new Error('the dropzone rendered no file input');
  return input;
};

/**
 * Choose files through the REAL input, by setting `files` from a `DataTransfer` and dispatching the
 * native `change` React listens for. `locator.upload()` would go through Playwright's
 * `setInputFiles`, which needs a path or a buffer descriptor and cannot hand the component the exact
 * `File` objects the assertions identify rows by.
 */
async function choose(files: readonly File[]): Promise<void> {
  const transfer = new DataTransfer();
  for (const file of files) transfer.items.add(file);
  const input = fileInput();
  input.files = transfer.files;
  await act(async () => {
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
}

/** One row's element, addressed by `data-phase` rather than by its filename — the row's Remove
 *  button is named "Remove alpha-handbook.pdf", so a text query for the filename resolves to two. */
const rowFor = (name: string): HTMLElement => {
  const row = [...document.querySelectorAll<HTMLElement>('li[data-phase]')].find((candidate) =>
    candidate.textContent?.includes(name),
  );
  if (row === undefined) throw new Error(`no row for ${name}`);
  return row;
};

/** By ACCESSIBLE NAME, which for a row action is its `aria-label` — the visible label is a short
 *  verb, because a filename in the visible label starved the name column at 375px. */
const clickButton = async (name: string): Promise<void> => {
  const button = [...document.querySelectorAll('button')].find(
    (candidate) => (candidate.getAttribute('aria-label') ?? candidate.textContent?.trim()) === name,
  );
  if (button === undefined) throw new Error(`no button named ${name}`);
  await act(async () => {
    button.click();
  });
};

beforeEach(() => {
  // `readCookie` reads the PAGE's cookie and MSW cannot set one for a cross-origin host from a
  // service worker, so without this every spec takes the `refreshCsrfToken()` path and fails for a
  // reason that has nothing to do with what it is about.
  document.cookie = 'XSRF-TOKEN=test-token';
  captured = null;
  resetNavigation();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the ceilings are the organization’s, and the cache says so', () => {
  it('reads them under an org-namespaced key that is NOT a prefix of the source list’s', async () => {
    worker.use(limitsHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual(['org', ORG_A, 'source-upload-limits']);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in.
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
    // THE PREFIX HAZARD, asserted rather than described: `['org', id, 'sources', …]` would make the
    // list screen's row mutations invalidate the ceilings, and this screen's post-batch
    // invalidation refetch its own limits.
    expect(keys).not.toContainEqual(['org', ORG_A, 'sources', 'upload-limits']);
  });

  it('builds `accept` and both ceiling sentences from the DTO and from no constant', async () => {
    worker.use(
      limitsHandler(ORG_A, { max_bytes: 10_485_760, allowed_mime: ['image/png'], max_batch: 7 }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    expect(fileInput().accept).toBe('image/png');
    // 10 MiB and a batch of 7 — neither of which appears anywhere in `src/`.
    await expect
      .element(screen.getByText(/Up to 7 files at a time, and up to 10MB each\./))
      .toBeVisible();
  });

  it('does not tell the user the picker’s filter is what will be accepted', async () => {
    worker.use(limitsHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    // THE KNOWN GAP, rendered as a caveat rather than closed with a guess. `allowed_mime` is one of
    // TWO terms the server admits a file on; the other is an extension allow-list it does not
    // publish, and the sets are not in bijection (`.md` and `.csv` both sniff as `text/plain`).
    await expect.element(screen.getByText(/not as a promise/)).toBeVisible();
    await expect.element(screen.getByText(/by its contents and by its name/)).toBeVisible();
  });
});

describe('the four states of the ceilings query', () => {
  it('draws a card-shaped skeleton and no dropzone while they are in flight', async () => {
    worker.use(http.get(limitsUrl(ORG_A), () => new Promise<never>(() => {})));

    const screen = await renderScreen();

    await vi.waitFor(() => {
      expect(document.querySelectorAll('[aria-busy="true"]').length).toBeGreaterThan(0);
    });
    // No dropzone with a guessed cap: a form rendered before the ceilings arrive is enforcing SOME
    // organization's limits, and it will not be this one.
    expect(document.querySelectorAll('input[type="file"]')).toHaveLength(0);
    // A skeleton is never announced: the container carries aria-busy, the blocks are aria-hidden.
    expect(document.querySelectorAll('[data-slot="skeleton"]:not([aria-hidden])')).toHaveLength(0);
    expect(screen.getByRole('button', { name: /^Upload/ }).elements()).toHaveLength(0);
  });

  it('renders the class-mapped sentence and the request id, never the envelope’s message', async () => {
    worker.use(
      http.get(limitsUrl(ORG_A), () =>
        HttpResponse.json(envelope('internal_dependency', { retryable: true }), { status: 503 }),
      ),
    );

    const screen = await renderScreen();

    await expect
      .element(screen.getByText('The upload limits for this organization could not be loaded'))
      .toBeVisible();
    await expect
      .element(screen.getByText('Something on our side is unavailable. Try again shortly.'))
      .toBeVisible();
    // The one identifier a user IS shown.
    await expect.element(screen.getByText('01JREQFROMLARAVEL')).toBeVisible();
    // The envelope's `message` is operator-facing and names an internal host in this fixture on
    // purpose, so a screen that rendered it fails here.
    expect(document.body.textContent).not.toContain('api-7.internal');
    // Retryable per the envelope, so exactly one retry affordance — a button the user presses.
    await expect.element(screen.getByRole('button', { name: 'Try again' })).toBeVisible();
  });

  it('offers NO retry when no envelope parsed, because unknown is permanently non-retryable', async () => {
    worker.use(
      http.get(limitsUrl(ORG_A), () => new HttpResponse('<html>502</html>', { status: 502 })),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText(/the reason was not reported/)).toBeVisible();
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });

  it('hides the whole surface from a viewer whose role cannot hold the permission', async () => {
    // The analyst membership in the shared fixture. No handler for ORG_B's limits is registered on
    // purpose: `onUnhandledRequest: 'error'` would answer 500 and the spec would fail loudly if the
    // screen asked — which is the assertion that the request is never made.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({ data: sessionFixture({ current_organization_id: ORG_B }) }),
      ),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText("You don't have access to this")).toBeInTheDocument();
    await expect.element(screen.getByText(/knowledge manager role/)).toBeVisible();
    await expect.element(screen.getByText(/Brightwater Legal/)).toBeVisible();
    // HIDDEN, not disabled: a disabled control with no explanation is a puzzle, and one with an
    // explanation is a permissions disclosure.
    expect(document.querySelectorAll('input[type="file"]')).toHaveLength(0);
    expect(document.querySelectorAll('button:disabled')).toHaveLength(0);
    // NO QUERY IS EVEN REGISTERED for this tenant: the forbidden decision is a mount condition,
    // not an `enabled: false` flag that leaves a permanently-pending key in the cache under an
    // organization this session may not read.
    expect(cacheKeys()).toEqual([['session']]);
  });
});

describe('client validation is an affordance, and it is per file', () => {
  it('marks the one file over this organization’s ceiling and leaves its sibling alone', async () => {
    worker.use(limitsHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    // 32 bytes is fine; 5000 is over the fixture's 4096-byte cap. The message is the SCHEMA's, and
    // the schema is a factory over the DTO — nothing in `src/` knows either number.
    await choose([ALPHA(32), BRAVO(5000)]);

    await vi.waitFor(() => {
      expect(rowFor('bravo-ledger.csv').dataset['invalid']).toBe('true');
    });
    // POSITIVE CONTROL: the sibling is untouched. Without it, "the row is marked" also passes on a
    // component that marked the whole batch.
    expect(rowFor('alpha-handbook.pdf').dataset['invalid']).toBe('false');
  });

  it('refuses the submit rather than sending a batch it knows is over the cap', async () => {
    const posts: string[] = [];
    worker.use(
      limitsHandler(),
      http.post(sourcesUrl(ORG_A), () => {
        posts.push('sent');
        return HttpResponse.json({ data: { id: '01JSOURCE' } }, { status: 201 });
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    await choose([BRAVO(5000)]);
    await clickButton('Upload 1 file');

    // `handleSubmit` is the gate: it re-runs the resolver and never calls the handler. The button is
    // NOT disabled while invalid — a greyed-out control with an error beside it is a puzzle.
    await vi.waitFor(() => {
      expect(rowFor('bravo-ledger.csv').dataset['invalid']).toBe('true');
    });
    expect(posts).toEqual([]);

    // AND THE REFUSAL IS VISIBLE ABOVE THE LIST. Without this line the press changes nothing on
    // screen — the row was already marked when the file was chosen — which is the "clicking Upload
    // does nothing, repeatedly" loop with the form in the right and the user in the dark.
    await expect.element(screen.getByText(/1 file can’t be uploaded as it is\./)).toBeVisible();
    // It COUNTS rather than repeating the per-file messages, which are on their files.
    expect(document.body.textContent).not.toContain('larger than this organization allows.Each');
  });

  it('carries the schema’s written copy, not Zod’s developer default', async () => {
    worker.use(limitsHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    await choose([BRAVO(5000)]);

    // The message a customer reads. Zod's unset default here is `Too big: expected file to have
    // <=4096 bytes` — not sentence case, and a raw byte count is internal vocabulary. The number
    // lives in the card's own sentence instead, in the reader's units.
    await expect
      .element(screen.getByText('This file is larger than this organization allows.'))
      .toBeVisible();
    expect(document.body.textContent).not.toContain('Too big');
    expect(document.body.textContent).not.toContain('4096');
  });

  it('binds a batch-level 422 to the file input, and a per-file one never', async () => {
    worker.use(
      limitsHandler(),
      http.post(sourcesUrl(ORG_A), () =>
        HttpResponse.json(
          // Keyed on the bare attribute: a rule about the batch rather than about a file. It is a
          // real path this form renders, so it does NOT become an orphan.
          {
            ...envelope('validation'),
            errors: { files: ['Your plan allows 2 files per upload.'] },
          },
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    await choose([ALPHA()]);
    await clickButton('Upload 1 file');

    // `.first()` because it resolves to TWO elements, and the second one is worth writing down: the
    // ROW also lists every field message on its own error, including one keyed on the bare `files`
    // attribute, so a batch-keyed 422 renders once in the banner and once per failed row. That is
    // real duplication and it is left alone deliberately — the wire's batch is ALWAYS one file long
    // (`FILE_PART` is `files[0]`), so a server rule about the batch cannot fire against a request
    // this app sends, and the case exists here only because the fixture constructs it. Narrowing the
    // row to `files.<digit>` keys would also take the batch message away from a screen with no form,
    // which `upload-dropzone.test.tsx` mounts on purpose.
    await expect
      .element(screen.getByText('Your plan allows 2 files per upload.').first())
      .toBeVisible();

    await vi.waitFor(() => {
      expect(fileInput().getAttribute('aria-invalid')).toBe('true');
    });
    // The message about the CONTROL is bound to the control. An error that is only red is not an
    // error state.
    const described = fileInput().getAttribute('aria-describedby')?.split(' ') ?? [];
    const bound = described.map((id) => document.getElementById(id)?.textContent ?? '').join(' | ');
    expect(bound).toContain('Your plan allows 2 files per upload.');
    // ...and the cap sentence is still described first, so the control is explained before it is
    // criticised.
    expect(bound).toContain('Drag files here');
  });

  it('binds a BATCH-level message to the file input rather than to a row', async () => {
    worker.use(limitsHandler(ORG_A, { ...LIMITS, max_batch: 1 }));

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    // The dropzone trims to the remaining capacity, so the cap is reached rather than exceeded —
    // and the input then reports the batch is full instead of accepting a second file in silence.
    await choose([ALPHA(), BRAVO()]);

    await vi.waitFor(() => {
      expect(document.querySelectorAll('li[data-phase]')).toHaveLength(1);
    });
    await expect.element(screen.getByText(/1 file was left out/)).toBeVisible();
    expect(fileInput().disabled).toBe(true);
    // No batch error, because nothing invalid was accepted — the trim happened first. The binding
    // itself is exercised by the 422 case below, where the server supplies the batch-level key.
    expect(fileInput().getAttribute('aria-invalid')).toBe(null);
  });
});

describe('a server 422 lands on the file it is about', () => {
  it('writes `files.0` from the wire onto the row that actually failed, once per file', async () => {
    let call = 0;
    worker.use(
      limitsHandler(),
      http.post(sourcesUrl(ORG_A), () => {
        call += 1;
        // The SECOND request fails. Every request's batch is one file long, so the wire always says
        // `files.0` — the reindex is what puts this on row 1 rather than on row 0.
        return call === 2
          ? HttpResponse.json(
              {
                ...envelope('validation'),
                errors: { 'files.0': ['A PDF that is really a ZIP is not accepted.'] },
              },
              { status: 422 },
            )
          : HttpResponse.json({ data: { id: '01JSOURCE' } }, { status: 201 });
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    await choose([ALPHA(), BRAVO()]);
    await clickButton('Upload 2 files');

    await vi.waitFor(() => {
      expect(document.querySelectorAll('[data-phase="failed"]')).toHaveLength(1);
    });

    const failed = document.querySelector<HTMLElement>('[data-phase="failed"]');
    expect(failed?.textContent).toContain('bravo-ledger.csv');
    expect(failed?.textContent).toContain('A PDF that is really a ZIP is not accepted.');

    // PARTIAL SUCCESS IS THE DEFAULT READING: one refusal is not one red banner over eight
    // successes, and it is not a green one either.
    expect(rowFor('alpha-handbook.pdf').dataset['phase']).toBe('done');
    expect(rowFor('alpha-handbook.pdf').textContent).not.toContain('really a ZIP');

    // The receipt is an INLINE alert, not a toast — there is no toast library and that is deliberate.
    await expect.element(screen.getByText(/1 file was uploaded/)).toBeVisible();
    // Announced politely, because the outcome needs no decision.
    expect(document.querySelector('[role="alert"][aria-live="polite"]')).not.toBe(null);

    // The envelope's `message` reaches no rendered string.
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('routes a key this form does not render to the batch banner instead of a phantom control', async () => {
    worker.use(
      limitsHandler(),
      http.post(sourcesUrl(ORG_A), () =>
        HttpResponse.json(
          {
            ...envelope('validation'),
            // `origin_url` is one of the eleven paths `StoreSourceRequest` validates across its
            // three `type` arms. The file picker renders none of them, which is exactly why
            // UPLOAD_KNOWN_PATHS is the schema's shape and not the manifest's: derived from the
            // manifest, this message would be written onto a control that does not exist and
            // displayed nowhere.
            errors: { origin_url: ['That address is not reachable.'] },
          },
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    await choose([ALPHA()]);
    await clickButton('Upload 1 file');

    await vi.waitFor(() => {
      expect(document.querySelectorAll('[data-phase="failed"]')).toHaveLength(1);
    });
    // Visible in the batch banner, which is what an orphan key is FOR.
    await expect.element(screen.getByText('That address is not reachable.').first()).toBeVisible();
  });
});

describe('the batch finishing is what the source list hears about', () => {
  it('invalidates the org-namespaced source list prefix, and writes nothing into it', async () => {
    worker.use(
      limitsHandler(),
      http.post(sourcesUrl(ORG_A), () =>
        HttpResponse.json({ data: { id: '01JSOURCEAAAAAAAAAAAAAAAAA' } }, { status: 201 }),
      ),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByLabelText('Add files to this organization')).toBeVisible();

    const invalidated: unknown[][] = [];
    const client = captured;
    if (client === null) throw new Error('the QueryClient was never captured');
    vi.spyOn(client, 'invalidateQueries').mockImplementation((filters) => {
      invalidated.push([...((filters?.queryKey ?? []) as unknown[])]);
      return Promise.resolve();
    });

    await choose([ALPHA()]);
    await clickButton('Upload 1 file');

    await vi.waitFor(() => {
      expect(invalidated).toContainEqual(['org', ORG_A, 'sources']);
    });

    // NOTHING IS WRITTEN INTO THE CACHE: a 201 body is a `draft`/`queued` snapshot, and the row a
    // list shows has to be the server's answer with the state the pipeline has actually reached.
    expect(cacheKeys()).not.toContainEqual(['org', ORG_A, 'sources']);
  });
});
