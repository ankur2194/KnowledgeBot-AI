import { KbError } from '@kb/contracts';
import type { OrgUploadLimits } from '@kb/contracts/forms';
import { act } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { UploadDropzone } from '@/features/sources/upload-dropzone';
import { UploadFileList } from '@/features/sources/upload-file-list';
import { useFileUploads, type FileUploader } from '@/features/sources/use-file-uploads';
import type { UploadProgress } from '@/lib/api/upload';

/**
 * The dropzone, the per-file rows, and the state machine between them.
 *
 * ── THE TRANSPORT IS A STUB HERE, ON PURPOSE, AND THAT IS NOT A SHORTCUT ────────────────────────
 * `useFileUploads` takes its uploader as a PARAMETER, and this spec supplies one whose progress,
 * resolution and rejection it drives by hand. The reason is measured rather than stylistic: MSW's
 * service worker answers an intercepted XHR inside the page, so `xhr.upload.progress` fires ZERO
 * times under this harness (asserted as a limitation in upload-transport.test.tsx). A component
 * spec that went through the real transport could therefore never see a bar move — the one thing it
 * exists to check.
 *
 * What that costs is stated rather than hidden: this file proves RENDERING and the state machine.
 * It proves nothing about the wire, nothing about tenancy (a component test that mocks the API
 * cannot fail an isolation test, per the vitest-playwright boundary table), and nothing about real
 * bytes producing real events — that last one is Playwright's against a real server, and is
 * unproven by this batch.
 *
 * ── EVERY `render` IS AWAITED ───────────────────────────────────────────────────────────────────
 * `vitest-browser-react` wraps render/rerender/unmount in React's `act()` and returns the promise.
 * Two overlapping corrupts the act queue for the REST OF THE FILE: the offender passes and every
 * test after it times out at 15s against an empty `<body>`. The external state pushes below go
 * through `await act(...)` for the same reason — sequentially, never inside a render.
 */

/**
 * ONE ORGANIZATION'S LIMITS, as the bootstrap config returns them. The literals live HERE, in a
 * fixture, and never in `src/`: §8.10 makes the cap and the allow-list per-organization, so a byte
 * or MIME constant in the app would make the form enforce a different limit from the organization
 * it is rendering for. This object IS the DTO — it is the thing the app is forbidden from inventing.
 */
const LIMITS: OrgUploadLimits = {
  max_bytes: 4096,
  allowed_mime: ['application/pdf', 'text/csv'],
  max_batch: 3,
};

/**
 * Filenames chosen so no accessible name is a SUBSTRING of another: role/name matching is a
 * case-insensitive substring in both vitest-browser and Playwright, so `report.pdf` and
 * `report.pdf.bak` would make every row query ambiguous and the failure would read as a strict-mode
 * violation rather than as a naming problem.
 */
const ALPHA = () => new File([new Uint8Array(32)], 'alpha-handbook.pdf', { type: 'application/pdf' });
const BRAVO = () => new File([new Uint8Array(64)], 'bravo-ledger.csv', { type: 'text/csv' });
const CHARLIE = () =>
  new File([new Uint8Array(96)], 'charlie-minutes.pdf', { type: 'application/pdf' });

/** One in-flight upload, with the three levers a real one has. */
interface Pending {
  readonly file: File;
  readonly emit: (progress: UploadProgress) => void;
  readonly succeed: () => void;
  readonly fail: (error: unknown) => void;
}

function stubUploader(): { readonly uploader: FileUploader; readonly pending: Pending[] } {
  const pending: Pending[] = [];

  const uploader: FileUploader = (file, { signal, on_progress }) =>
    new Promise<void>((resolve, reject) => {
      // The stub rejects with the signal's AbortError exactly as `lib/api/upload.ts` does, so the
      // hook's cancellation branch is exercised faithfully rather than through a bespoke shape.
      signal.addEventListener(
        'abort',
        () => {
          reject(
            signal.reason instanceof Error
              ? signal.reason
              : new DOMException('Upload cancelled.', 'AbortError'),
          );
        },
        { once: true },
      );

      pending.push({ file, emit: on_progress, succeed: resolve, fail: reject });
    });

  return { uploader, pending };
}

function Harness({
  uploader,
  onFileRejected,
}: {
  readonly uploader: FileUploader;
  readonly onFileRejected?: (
    index: number,
    errors: Readonly<Record<string, readonly string[]>>,
  ) => void;
}) {
  const uploads = useFileUploads(
    onFileRejected === undefined ? { upload: uploader } : { upload: uploader, onFileRejected },
  );

  return (
    <div>
      <UploadDropzone
        limits={LIMITS}
        selectedCount={uploads.items.length}
        onFilesChosen={uploads.add}
      />
      <button type="button" onClick={uploads.start}>
        Upload the chosen files
      </button>
      <UploadFileList uploads={uploads} />
    </div>
  );
}

/** The one file input, addressed the way the DOM addresses it rather than by a test id. */
const fileInput = (): HTMLInputElement => {
  const input = document.querySelector<HTMLInputElement>('input[type="file"]');
  if (input === null) throw new Error('the dropzone rendered no file input');
  return input;
};

/**
 * ONE ROW'S TEXT, ADDRESSED BY ITS `data-phase` RATHER THAN BY ITS FILENAME.
 *
 * `getByText('alpha-handbook.pdf')` resolves to TWO elements and fails on strict mode: text and
 * role/name matching are both case-insensitive SUBSTRING matches, and the row's action button is
 * named "Remove alpha-handbook.pdf". That is the same naming hazard the filenames at the top of
 * this file are chosen to avoid, arriving from the other direction — so row-scoped assertions read
 * the list item, and name queries pass `{ exact: true }`.
 */
const rowFor = (name: string): HTMLElement => {
  const row = [...document.querySelectorAll<HTMLElement>('li[data-phase]')].find((candidate) =>
    candidate.textContent?.includes(name),
  );
  if (row === undefined) throw new Error(`no row for ${name}`);
  return row;
};

/**
 * Choose files through the REAL input, by setting `files` from a `DataTransfer` and dispatching the
 * native `change` React listens for. `locator.upload()` would go through Playwright's
 * `setInputFiles`, which needs a path or a buffer descriptor and cannot hand the component the
 * exact `File` objects the assertions below identify rows by.
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

/** Drop files on the zone, which is the OTHER entry point and the one with no input involved. */
async function drop(files: readonly File[]): Promise<void> {
  const transfer = new DataTransfer();
  for (const file of files) transfer.items.add(file);
  const zone = fileInput().parentElement;
  if (zone === null) throw new Error('the file input has no zone around it');

  await act(async () => {
    zone.dispatchEvent(new DragEvent('dragover', { bubbles: true, dataTransfer: transfer }));
    zone.dispatchEvent(new DragEvent('drop', { bubbles: true, dataTransfer: transfer }));
  });
}

/**
 * Click a button by its ACCESSIBLE NAME, which is not always its text.
 *
 * The row's actions carry the filename in `aria-label` and show a short verb — "Remove", not
 * "Remove alpha-handbook.pdf" — because the visible form starved the filename column at 375px
 * (`upload-file-row.tsx` carries the measurement). The accessible name is still unique per row,
 * which is the property every assertion in this file depends on, so this helper reads THAT rather
 * than `textContent`: `aria-label` when present, the trimmed text otherwise.
 */
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
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the dropzone is a control, not a decorated div', () => {
  it('exposes a real file input with a visible bound label, and Tab reaches it', async () => {
    const { uploader } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    // A VISIBLE `<label>` bound with htmlFor, not a placeholder and not an aria-label nobody can
    // see. `getByLabelText` resolving at all is the assertion.
    await expect
      .element(screen.getByLabelText('Add files to this organization'))
      .toBeInTheDocument();

    const input = fileInput();
    expect(input.multiple).toBe(true);
    // Focusable, which a `div[role="button"]` with an onClick is not — and which axe would not have
    // told us about either way.
    input.focus();
    expect(document.activeElement).toBe(input);
    // `opacity-0`, never `display:none` or `visibility:hidden`: a hidden control is not focusable,
    // and the global `:focus-visible` outline paints around this element's box, which IS the zone's.
    expect(getComputedStyle(input).display).not.toBe('none');
    expect(getComputedStyle(input).visibility).not.toBe('hidden');
  });

  it('builds `accept` from the organization’s list and nothing else', async () => {
    const { uploader } = stubUploader();
    await render(<Harness uploader={uploader} />);

    // The picker filter is the ORGANIZATION's, per §8.10. It is not the control — `File.type` is
    // forgeable and `accept` is not enforced on a drop in every engine — and the server's sniffing
    // is.
    expect(fileInput().accept).toBe('application/pdf,text/csv');
  });

  it('renders the FIRST-RUN empty state, and never a filtered one', async () => {
    const { uploader } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await expect.element(screen.getByText('No files chosen yet')).toBeVisible();
    // The two empties are different states with different actions. "Clear filters" on a list the
    // user assembled by hand is the conflation states.md splits them to prevent.
    expect(document.body.textContent).not.toContain('Clear filters');
    expect(document.body.textContent).not.toContain('No matches');
  });
});

describe('both entry points produce rows', () => {
  it('adds a row per chosen file, with its name and size', async () => {
    const { uploader } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await choose([ALPHA(), BRAVO()]);

    await expect.element(screen.getByText('alpha-handbook.pdf', { exact: true })).toBeVisible();
    await expect.element(screen.getByText('bravo-ledger.csv', { exact: true })).toBeVisible();
    // 32 bytes and 64 bytes, formatted by the reader's locale.
    expect(document.body.textContent).toMatch(/32\s?B/);
    expect(document.body.textContent).toMatch(/64\s?B/);
    // The empty state is gone rather than sitting under the rows.
    expect(document.body.textContent).not.toContain('No files chosen yet');
  });

  it('converts `dataTransfer.files` with Array.from rather than passing a FileList on', async () => {
    const { uploader } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await drop([CHARLIE()]);

    // `uploadSchema`'s field is `z.array(z.file())`. A `FileList` reaching form state would fail the
    // parse, and `z.instanceof(FileList)` cannot be the schema's answer because `FileList` is a DOM
    // type absent from Node.
    await expect.element(screen.getByText('charlie-minutes.pdf', { exact: true })).toBeVisible();
  });

  it('APPENDS on a second choice instead of replacing the first', async () => {
    const { uploader } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await choose([ALPHA()]);
    await choose([BRAVO()]);

    // A `change` event carries only what THAT dialog selected; a picker that discards prior rows is
    // the most-reported bug in every upload UI.
    await expect.element(screen.getByText('alpha-handbook.pdf', { exact: true })).toBeVisible();
    await expect.element(screen.getByText('bravo-ledger.csv', { exact: true })).toBeVisible();
  });

  it('caps the BATCH from the DTO and says what it left out', async () => {
    const { uploader } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    const extra = (n: number) =>
      new File([new Uint8Array(8)], `spare-${n}.csv`, { type: 'text/csv' });
    await choose([ALPHA(), BRAVO(), CHARLIE(), extra(4), extra(5)]);

    await expect.element(screen.getByText('alpha-handbook.pdf', { exact: true })).toBeVisible();
    // max_batch is 3, so two were left out — and SAID SO. A picker that accepts five and uploads
    // three in silence is the complaint nobody can reproduce, because the dialog is gone.
    expect(document.body.textContent).not.toContain('spare-4.csv');
    await expect
      .element(screen.getByText('2 files were left out: this batch holds at most 3.'))
      .toBeVisible();
    // ...and the zone reports the batch is full rather than pretending more can be added.
    expect(fileInput().disabled).toBe(true);
  });
});

describe('progress reaches the UI, per file', () => {
  it('names each bar so a batch of two is not two unnamed progress bars', async () => {
    const { uploader, pending } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await choose([ALPHA(), BRAVO()]);
    await clickButton('Upload the chosen files');
    await vi.waitFor(() => {
      expect(pending).toHaveLength(2);
    });

    await act(async () => {
      pending[0]?.emit({ loaded: 8, total: 32, percent: 25 });
      pending[1]?.emit({ loaded: 48, total: 64, percent: 75 });
    });

    // Radix supplies `role="progressbar"` and `aria-valuenow` AND NOTHING ELSE — the name per
    // instance is ours, and without it the one that is stuck is unidentifiable.
    const alpha = screen.getByRole('progressbar', {
      name: 'Upload progress for alpha-handbook.pdf',
    });
    const bravo = screen.getByRole('progressbar', { name: 'Upload progress for bravo-ledger.csv' });

    await expect.element(alpha).toHaveAttribute('aria-valuenow', '25');
    await expect.element(bravo).toHaveAttribute('aria-valuenow', '75');
    // The percentage in TEXT is the non-colour channel: the fill is the only visual signal.
    expect(document.body.textContent).toContain('25%');
    expect(document.body.textContent).toContain('75%');
  });

  it('renders an INDETERMINATE indicator rather than a bar parked at 0%', async () => {
    const { uploader, pending } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await choose([ALPHA()]);
    await clickButton('Upload the chosen files');
    await vi.waitFor(() => {
      expect(pending).toHaveLength(1);
    });

    // `lengthComputable: false` — a real state, and one `<Progress value={null}>` would render as
    // 0% because of its shadcn-inherited `value || 0`.
    await act(async () => {
      pending[0]?.emit({ loaded: 900, total: null, percent: null });
    });

    await expect
      .element(
        screen.getByText(
          'Uploading alpha-handbook.pdf. The total size was not reported, so there is no percentage to show.',
        ),
      )
      .toBeVisible();
    // No determinate bar at all, rather than one claiming to measure something.
    expect(document.querySelectorAll('[role="progressbar"]')).toHaveLength(0);
    // The indicator that IS rendered survives reduced motion by design: `kb-skeleton` drops the
    // sweep and keeps the tinted block, so the signal is never deleted.
    expect(document.querySelectorAll('.kb-skeleton').length).toBeGreaterThan(0);
  });

  it('stops claiming to measure at 100% and says the server has it', async () => {
    const { uploader, pending } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await choose([ALPHA()]);
    await clickButton('Upload the chosen files');
    await vi.waitFor(() => {
      expect(pending).toHaveLength(1);
    });

    await act(async () => {
      pending[0]?.emit({ loaded: 32, total: 32, percent: 100 });
    });

    // The bytes are gone; the response has not arrived. A determinate bar sitting at 100% for that
    // window is what P13 calls worse than a spinner.
    await expect.element(screen.getByText('Finishing')).toBeVisible();
    await expect
      .element(screen.getByText('Sent. Waiting for the server to accept alpha-handbook.pdf.'))
      .toBeVisible();
  });
});

describe('cancel actually cancels', () => {
  it('aborts one row’s signal and leaves its siblings running', async () => {
    const { uploader, pending } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await choose([ALPHA(), BRAVO()]);
    await clickButton('Upload the chosen files');
    await vi.waitFor(() => {
      expect(pending).toHaveLength(2);
    });
    await act(async () => {
      pending[0]?.emit({ loaded: 8, total: 32, percent: 25 });
      pending[1]?.emit({ loaded: 8, total: 64, percent: 12 });
    });

    // Not icon-only, and the name carries the filename — five rows named "Cancel" resolve to five
    // controls and a keyboard user hears the same word five times.
    await clickButton('Cancel alpha-handbook.pdf');

    await vi.waitFor(() => {
      expect(document.body.textContent).toContain('Cancelled');
    });

    // POSITIVE CONTROL: the sibling is still going. Without it, "the row says Cancelled" also
    // passes on a component that cancelled the whole batch.
    await expect.element(screen.getByText('12%')).toBeVisible();
    // `toBeInTheDocument`, NOT `toBeVisible`, and the reason is the harness rather than the
    // component: no stylesheet is loaded in a component spec, so `h-1.5 w-full` applies nothing and
    // an empty div has a zero-height box — which is exactly what "not visible" means here. The
    // bar's GEOMETRY belongs to `components/ui/progress.tsx`; its presence and its ANNOUNCED VALUE
    // are what this file claims.
    await expect
      .element(screen.getByRole('progressbar', { name: 'Upload progress for bravo-ledger.csv' }))
      .toBeInTheDocument();

    // A cancellation is an OUTCOME, not a failure: no error block, no retry affordance. Addressed
    // by ACCESSIBLE NAME rather than by text: the row's actions carry the filename in `aria-label`
    // and show a short verb, so `textContent` no longer holds the per-row string.
    expect(
      screen.getByRole('button', { name: 'Try again with alpha-handbook.pdf' }).elements(),
    ).toHaveLength(0);
    expect(document.querySelectorAll('[data-phase="cancelled"]')).toHaveLength(1);
  });
});

describe('a per-file failure lands on that file and not on the batch', () => {
  it('renders a 422’s own messages on its row, with the siblings untouched', async () => {
    const { uploader, pending } = stubUploader();
    const rejected = vi.fn();
    // AWAITED, like every render in this file: `vitest-browser-react` wraps it in React's `act()`
    // and two overlapping corrupts the act queue for the rest of the FILE. The handle is unused
    // here because every assertion below is row-scoped and reads the DOM directly.
    await render(<Harness uploader={uploader} onFileRejected={rejected} />);

    await choose([ALPHA(), BRAVO()]);
    await clickButton('Upload the chosen files');
    await vi.waitFor(() => {
      expect(pending).toHaveLength(2);
    });

    // A validation KbError as `lib/api/upload.ts` builds it: `errors` keyed `files.0`, because THIS
    // request's batch was one file long.
    const validation = new KbError(
      'validation',
      false,
      null,
      '01JREQVALIDATION',
      'operator detail from api-7.internal',
      { 'files.0': ['A PDF that is really a ZIP is not accepted.'] },
    );

    await act(async () => {
      pending[1]?.fail(validation);
    });

    await vi.waitFor(() => {
      expect(document.body.textContent).toContain(
        'A PDF that is really a ZIP is not accepted.',
      );
    });

    // ONE failed row, and it is bravo. The message is inside bravo's `<li>` rather than anywhere on
    // the page, which is what "not the batch" means.
    const failed = [...document.querySelectorAll('[data-phase="failed"]')];
    expect(failed).toHaveLength(1);
    expect(failed[0]?.textContent).toContain('bravo-ledger.csv');
    expect(failed[0]?.textContent).toContain('A PDF that is really a ZIP is not accepted.');

    // The sibling did NOT fail: a partial is neither one red banner nor one green one.
    expect(rowFor('alpha-handbook.pdf').dataset['phase']).not.toBe('failed');
    expect(rowFor('alpha-handbook.pdf').textContent).not.toContain(
      'A PDF that is really a ZIP is not accepted.',
    );

    // THE ENVELOPE'S `message` REACHES NO RENDERED STRING. It is operator-facing and can carry an
    // internal hostname; the fixture's does, deliberately, so a screen that rendered it fails here.
    expect(document.body.textContent).not.toContain('api-7.internal');
    // The one identifier a user IS shown.
    expect(document.body.textContent).toContain('01JREQVALIDATION');

    // The form seam: the RAW map plus the row's index, for the caller to compose with
    // `reindexFileErrors` and `applyServerErrors` (proved in tests/unit/upload-form.test.ts).
    expect(rejected).toHaveBeenCalledWith(1, {
      'files.0': ['A PDF that is really a ZIP is not accepted.'],
    });
  });

  it('offers a retry only when the envelope says the failure is retryable', async () => {
    const { uploader, pending } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await choose([ALPHA(), BRAVO()]);
    await clickButton('Upload the chosen files');
    await vi.waitFor(() => {
      expect(pending).toHaveLength(2);
    });

    await act(async () => {
      // `storage` is retryable; the envelope's flag is the authority and is never re-derived from
      // the class name.
      pending[0]?.fail(new KbError('storage', true, null, '01JREQSTORAGE', 'operator detail'));
      // No envelope parsed: unknown, and unknown is permanently non-retryable.
      pending[1]?.fail(new KbError(null, false, null, '01JREQUNKNOWN', 'HTTP 502'));
    });

    await vi.waitFor(() => {
      expect(document.querySelectorAll('[data-phase="failed"]')).toHaveLength(2);
    });

    const retry = screen.getByRole('button', { name: 'Try again with alpha-handbook.pdf' });
    await expect.element(retry).toBeVisible();
    // WCAG 2.5.3 Label in Name: the VISIBLE label is a contiguous prefix of the accessible name, so
    // speech input on the visible words still activates it. "Try alpha.pdf again" would not be.
    await expect.element(retry).toHaveTextContent('Try again');
    // ...on the row that failed retryably, not floating over the batch.
    expect(rowFor('alpha-handbook.pdf').contains(retry.element())).toBe(true);
    // Offering a retry that cannot help is worse than offering nothing.
    expect(
      screen.getByRole('button', { name: 'Try again with bravo-ledger.csv' }).elements(),
    ).toHaveLength(0);

    // The class-mapped sentences, not the envelope's message.
    expect(document.body.textContent).toContain('File storage is unavailable right now.');
    expect(document.body.textContent).toContain('the reason was not reported');
    expect(document.body.textContent).not.toContain('operator detail');
  });

  it('re-queues a retried row as a NEW create rather than replaying the request', async () => {
    const { uploader, pending } = stubUploader();
    await render(<Harness uploader={uploader} />);

    await choose([ALPHA()]);
    await clickButton('Upload the chosen files');
    await vi.waitFor(() => {
      expect(pending).toHaveLength(1);
    });
    await act(async () => {
      pending[0]?.fail(new KbError('storage', true, null, '01JREQSTORAGE', 'operator detail'));
    });
    await vi.waitFor(() => {
      expect(document.body.textContent).toContain('Failed');
    });

    await clickButton('Try again with alpha-handbook.pdf');

    // A SECOND call into the transport, which is a second create — this request carries no
    // Idempotency-Key, so nothing may replay it automatically and the user has to ask.
    await vi.waitFor(() => {
      expect(pending).toHaveLength(2);
    });
    // The previous failure is off the screen, rather than sitting beside a running bar.
    expect(rowFor('alpha-handbook.pdf').dataset['phase']).not.toBe('failed');
    expect(document.body.textContent).not.toContain('File storage is unavailable right now.');
  });
});

describe('the batch receipt and the announcement', () => {
  it('counts what landed, states what did not, and announces politely', async () => {
    const { uploader, pending } = stubUploader();
    const screen = await render(<Harness uploader={uploader} />);

    await choose([ALPHA(), BRAVO()]);
    await clickButton('Upload the chosen files');
    await vi.waitFor(() => {
      expect(pending).toHaveLength(2);
    });

    await act(async () => {
      pending[0]?.succeed();
      pending[1]?.fail(new KbError('storage', true, null, '01JREQSTORAGE', 'operator detail'));
    });

    await vi.waitFor(() => {
      expect(document.body.textContent).toContain('1 file was uploaded');
    });

    // PARTIAL SUCCESS IS THE DEFAULT READING: one landed, one did not, and neither is a banner over
    // the other.
    await expect
      .element(screen.getByText('1 file was uploaded, and 1 did not. The rows below say why.'))
      .toBeVisible();

    // An INLINE banner, not a toast — there is no toast library and that is deliberate. It is
    // `aria-live="polite"`, which OVERRIDES `<Alert>`'s implicit assertive politeness: a finished
    // upload has no business interrupting whatever the user was reading.
    const receipt = document.querySelector('[data-slot="alert"]');
    expect(receipt?.getAttribute('aria-live')).toBe('polite');

    // The live region carries STATE TRANSITIONS, never the token-by-token progress stream: a region
    // wired to the percentage would announce on every event.
    const status = [...document.querySelectorAll('[role="status"]')].find((node) =>
      node.className.includes('sr-only'),
    );
    expect(status?.textContent).toBe('1 of 2 uploaded, 1 failed.');
  });
});
