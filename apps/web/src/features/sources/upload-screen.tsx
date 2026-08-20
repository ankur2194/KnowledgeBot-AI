'use client';

import type { OrgUploadLimits } from '@kb/contracts/forms';
import { uploadDefaults, uploadSchema, type UploadIn, type UploadOut } from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useCallback, useEffect, useId, useMemo } from 'react';
import { useForm } from 'react-hook-form';

import { ErrorState, ForbiddenState } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import {
  SOURCE_MANAGE_ROLE,
  UPLOAD_KNOWN_PATHS,
  canManageSources,
  fetchUploadLimits,
  reindexFileErrors,
  uploadSourceFile,
} from '@/features/sources/api';
import { formatBytes } from '@/features/sources/file-display';
import { UploadDropzone } from '@/features/sources/upload-dropzone';
import { UploadFileList } from '@/features/sources/upload-file-list';
import { batchIssue, fileIssues } from '@/features/sources/upload-issues';
import {
  useFileUploads,
  type FileUploader,
  type FileUploads,
} from '@/features/sources/use-file-uploads';
import { applyServerErrors } from '@/lib/forms/apply-server-errors';

/**
 * `/sources/upload` — drag or choose files, watch each one's own bar, cancel or remove any of them,
 * and read each one's own refusal.
 *
 * ── THE ORGANIZATION IS IN THE SESSION AND IN NONE OF NEXT'S CACHE KEYS ────────────────────────
 * `/sources/upload` is ONE URL for every organization an administrator belongs to. Not one
 * organization-scoped byte is produced by the Next server — not "produced and marked no-store", not
 * produced. The ceilings this screen is a function of arrive from a browser fetch under an
 * ORG-NAMESPACED key:
 *
 *     ['org', orgId, 'source-upload-limits']
 *
 * NOT `['org', orgId, 'sources', 'upload-limits']`, which reads better and is wrong: that key is a
 * PREFIX MATCH of the source list's own `['org', orgId, 'sources']`, so every row mutation on the
 * list screen — disable, reprocess, delete — would invalidate the ceilings too, and this screen's own
 * post-batch invalidation would refetch its own limits. A cache namespace is not a URL path and does
 * not have to look like one.
 *
 * On an organization switch this screen is a hard reset, not a refetch: `useResetQueryClient()`
 * REPLACES the QueryClient and the session re-enters `loading`, so this subtree unmounts — taking
 * the limits, the form state and every chosen `File` with it. That last one matters more here than
 * on a read-only screen: a `File` handle chosen while looking at Acme must not still be sitting in a
 * form that is now posting to Globex.
 *
 * ── FOUR STATES, SHIPPED WITH THE SUCCESS STATE ────────────────────────────────────────────────
 * Loading — a skeleton shaped like the card it becomes, so nothing reflows when the ceilings land.
 * Empty — FIRST-RUN only, and it lives in `<UploadFileList>`: nothing here is filtered, so there is
 * no filtered empty and adding "Clear filters" to a list the user assembled by hand would be exactly
 * the conflation states.md splits the two empties to prevent. Error — the ceilings failed to load,
 * rendered from `error_class` with the `request_id` and a retry only when the envelope says so.
 * Forbidden — real rather than theoretical: `sources.manage` is withheld from ANALYST, and a user
 * who cannot perform an action does not get a disabled button for it.
 *
 * ── THERE IS NO SERVER ACTION HERE AND THERE NEVER WILL BE ─────────────────────────────────────
 * The upload is a browser `fetch` (an `XMLHttpRequest`, in fact — `upload.progress` has no `fetch()`
 * equivalent) straight to Laravel, which rate-limits it, authorizes it through `sources.manage`,
 * refuses it on a suspended organization and writes the audit row. An action would bypass all four.
 */
export function UploadScreen() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') return <UploadSkeleton />;

  if (session.status !== 'authenticated') {
    // `anonymous` and `unavailable`, and NOTHING is rendered for either — `<SessionProvider>` above
    // owns both: it bounces to `/login` for `anonymous` and renders the class-mapped "your account
    // could not be loaded" panel for `unavailable`.
    return null;
  }

  if (orgId === null) {
    // Reachable BY DESIGN: login succeeds with `current_organization_id: null` for a user whose
    // memberships are all `invited` or `suspended`. `useOrgKey()` would THROW on a key built from
    // `undefined` — one shared namespace for every org-less state on the platform — so the gate is a
    // mount condition rather than an `enabled` flag.
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          Files belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  const membership = session.organizations.find((organization) => organization.id === orgId);

  return (
    <UploadForOrganization
      orgId={orgId}
      // The role the viewer holds HERE, from the membership list the server handed us, rather than
      // from a global "am I an admin" flag: a person can be an owner of one organization and an
      // analyst in the next.
      viewerRole={membership?.role ?? null}
      organizationName={membership?.name}
    />
  );
}

/**
 * Mounted only with a real organization, and its ONE job is the forbidden decision.
 *
 * ── FORBIDDEN IS DECIDED BEFORE A QUERY EXISTS, NOT WITH `enabled: false` ──────────────────────
 * `sources.manage` is withheld from ANALYST while the sidebar offers `/sources` to everybody, so a
 * viewer who cannot hold the permission is an ordinary Tuesday and the role really is the reason.
 *
 * The obvious shape — one component with `enabled: canManage` — makes no REQUEST and still registers
 * `['org', orgId, 'source-upload-limits']` in the cache, permanently pending, for a tenant this
 * session may not read. Nothing leaks, and it is still the wrong artifact to leave lying in a cache
 * whose whole discipline is that every key is a namespace somebody may inspect. Splitting the
 * component means the hook never runs, so there is no key, no observer and no `enabled` flag whose
 * value a later refactor could flip without noticing what it turns on.
 *
 * The server remains the gate: `sources.manage` is checked on every part of every upload, and a role
 * that changed under a cached session comes back as a 403 rendered through `error_class`. This
 * decides only which screen to draw.
 */
function UploadForOrganization({
  orgId,
  viewerRole,
  organizationName,
}: {
  readonly orgId: string;
  readonly viewerRole: Parameters<typeof canManageSources>[0];
  readonly organizationName?: string;
}) {
  if (!canManageSources(viewerRole)) {
    return <ForbiddenState requiredRole={SOURCE_MANAGE_ROLE} organizationName={organizationName} />;
  }

  return <UploadForPermittedViewer orgId={orgId} />;
}

/** Mounted only for a viewer whose role can hold `sources.manage`. */
function UploadForPermittedViewer({ orgId }: { readonly orgId: string }) {
  const orgKeyFor = useOrgKey();

  const limitsKey = useMemo(() => orgKeyFor('source-upload-limits'), [orgKeyFor]);
  const listKey = useMemo(() => orgKeyFor('sources'), [orgKeyFor]);

  const limits = useQuery({
    queryKey: limitsKey,
    queryFn: ({ signal }) => fetchUploadLimits(orgId, signal),
    // No `refetchInterval`: ceilings are deployment configuration, not a lifecycle. The client's
    // 30 s `staleTime` and `refetchOnWindowFocus: false` are inherited from `lib/query/client.ts`,
    // and the tenant's namespace is in the key, which is the part that matters.
  });

  if (limits.data === undefined) {
    if (limits.isError) {
      return (
        <ErrorState
          // The class-mapped sentence says WHY; without a title nothing says WHAT, and the reader
          // cannot tell whether the rest of the page is trustworthy.
          title="The upload limits for this organization could not be loaded"
          error={limits.error}
          onRetry={() => void limits.refetch()}
        />
      );
    }

    return <UploadSkeleton />;
  }

  return <UploadBatchForm orgId={orgId} limits={limits.data} listKey={listKey} />;
}

/**
 * FIRST-LOAD SKELETON, and it mirrors the card it becomes rather than being a spinner: the dropzone
 * is a tall dashed box under a two-line header, so that is what this is. A skeleton of a different
 * height makes the page jump when the ceilings land and gets debugged as a rendering bug.
 *
 * The container carries `aria-busy`; every block inside is `aria-hidden` (`<Skeleton>` sets it). Do
 * not announce a skeleton.
 */
function UploadSkeleton() {
  return (
    <Card aria-busy="true">
      <CardHeader>
        <Skeleton className="h-5 w-40 rounded-md" />
        <Skeleton className="h-4 w-4/5 rounded-md" />
      </CardHeader>
      <CardContent>
        <Skeleton className="h-32 w-full rounded-2xl" />
      </CardContent>
    </Card>
  );
}

/**
 * The form, mounted only with real limits in hand.
 *
 * ── THE RESOLVER IS MEMOISED OVER THE FETCHED LIMITS, AND THAT IS NOT A PERFORMANCE NOTE ───────
 * `zodResolver(uploadSchema(limits))` built inline in the render body is a NEW resolver identity on
 * every render, and RHF treats a new resolver as a reason to re-run validation and re-derive form
 * state — a form that resets itself while the user is filling it in. The dependency is the limits
 * OBJECT, whose identity is stable because TanStack Query's structural sharing preserves it across
 * refetches that changed nothing, and because this component is not mounted at all until there is
 * one to memoise over.
 *
 * The alternative shape — a module-level schema with the numbers baked in — is the thing §8.10 makes
 * impossible: the cap and the accepted types are per-organization, so a constant enforces one
 * organization's limits on every other, and the failure is silent in the direction that matters (a
 * form STRICTER than the server removes functionality nobody reports).
 */
function UploadBatchForm({
  orgId,
  limits,
  listKey,
}: {
  readonly orgId: string;
  readonly limits: OrgUploadLimits;
  /** The source list's org-namespaced key PREFIX, captured as an array rather than as the builder:
   *  the invalidation below can fire after an organization switch has unmounted this tree, and
   *  `orgKeyFor(...)` called at that moment would throw inside a callback nobody is watching. */
  readonly listKey: readonly unknown[];
}) {
  const queryClient = useQueryClient();
  const batchErrorId = useId();

  const resolver = useMemo(() => zodResolver(uploadSchema(limits)), [limits]);

  const form = useForm<UploadIn, unknown, UploadOut>({
    resolver,
    // The ONE path from "nothing chosen yet" into form state, so nobody can reach for
    // `reset(someServerObject)` — and a fresh array per call, because RHF takes ownership of it.
    defaultValues: uploadDefaults(),
    mode: 'onTouched',
  });

  /**
   * The transport, bound to this organization, as the hook's `upload` parameter.
   *
   * `useCallback` because the hook keeps its options in a ref it refreshes every render — an inline
   * arrow works and re-allocates on every progress event, which on a five-file batch is a few
   * hundred allocations for nothing.
   */
  const upload = useCallback<FileUploader>(
    (file, options) => uploadSourceFile(orgId, file, options),
    [orgId],
  );

  const uploads = useFileUploads({
    upload,
    /**
     * THE SEAM, COMPOSED EXACTLY AS `use-file-uploads.ts` DOCUMENTS IT.
     *
     * `reindexFileErrors` moves the WIRE's index onto the ROW's: every request sends one file as
     * `files[0]`, so every per-file rejection comes back keyed `files.0` regardless of which row it
     * belongs to, and handing that straight on writes five files' messages onto row 0.
     *
     * `applyServerErrors` then folds `.\d+` to `.*` for the MEMBERSHIP TEST ONLY — `files.3` matches
     * the `files.*` in `UPLOAD_KNOWN_PATHS` while the path it hands `setError` stays `files.3`, which
     * is the row the user is looking at. That fold is not re-implemented here and must not be: a
     * second copy of it is a second copy of the rule.
     *
     * A key this form does not render — `name`, `origin_url`, anything from the two arms of
     * `StoreSourceRequest` the file picker does not draw — becomes an orphan and lands on
     * `root.serverError`, which is rendered as the batch banner below. Visible, rather than written
     * onto a phantom control and displayed nowhere.
     */
    onFileRejected: (index, errors) => {
      applyServerErrors(form, UPLOAD_KNOWN_PATHS, reindexFileErrors(errors, index));
    },
  });

  const { errors } = form.formState;
  const batchMessage = batchIssue(errors);
  const rootMessage = errors.root?.serverError?.message;
  const issues = useMemo(() => fileIssues(errors), [errors]);

  /**
   * FORM STATE FOLLOWS THE ROW LIST, AND IT IS WRITTEN FROM THE HANDLERS RATHER THAN FROM AN EFFECT.
   *
   * An effect keyed on `uploads.items` would fire on every PROGRESS event — the hook rebuilds the
   * array on each one — and a `shouldValidate` write in that effect would re-run the resolver
   * dozens of times per second AND wipe the server errors `onFileRejected` had just written. So the
   * two writes happen where the selection actually changes, and nowhere else.
   *
   * `shouldValidate: true` on both is deliberate in both directions. Adding: the user learns
   * immediately that a 40 MB file is over this organization's ceiling, before spending the bytes.
   * REMOVING: a removal RENUMBERS every row after it, so a `files.2` message written about the file
   * that used to be third would now be displayed against a different file. Re-validating discards
   * them rather than mislabelling them, and the removed row's neighbours keep their own `item.error`
   * blocks, which are keyed by row id and cannot be renumbered.
   */
  const chosen = uploads.items.map((item) => item.file);

  const addFiles = (files: readonly File[]): void => {
    uploads.add(files);
    form.setValue('files', [...chosen, ...files], { shouldValidate: true });
  };

  const removeFile = (id: string): void => {
    uploads.remove(id);
    form.setValue(
      'files',
      uploads.items.filter((item) => item.id !== id).map((item) => item.file),
      { shouldValidate: true },
    );
  };

  /**
   * The hook's surface with its two SELECTION-CHANGING operations replaced by the wrappers above.
   *
   * Decorated rather than handed to `<UploadFileList>` as extra props, because the alternative is a
   * screen that keeps form state in step through one door (the dropzone) and not the other (a row's
   * Remove). Nothing downstream can reach the undecorated `add`/`remove`, so the two cannot drift —
   * and the four pass-through members (`cancel`, `requeue`, `start`, and the counts) change no file
   * set and need no wrapper: `requeue` puts a row back in the queue with the same `File` on it.
   */
  const list: FileUploads = { ...uploads, add: addFiles, remove: removeFile };

  /**
   * The rows a press of Upload would actually start. `failed` and `cancelled` rows are deliberately
   * NOT restarted by it — an upload carries no `Idempotency-Key`, so re-sending is a second source
   * row, and that has to be asked for on the row it is about (`use-file-uploads.ts`).
   */
  const queued = uploads.items.filter((item) => item.phase === 'queued').length;
  const settled = !uploads.isPending;

  /**
   * THE LIST THIS SCREEN DOES NOT RENDER IS THE ONE THAT HAS TO CHANGE.
   *
   * `invalidateQueries` on the org-namespaced PREFIX, so every page and every sort of the source
   * list is marked stale — a new source lands wherever the current sort puts it, not necessarily on
   * the page this administrator was last looking at. Inactive queries are marked and refetch when
   * they next mount, which is exactly what "go back to Sources and see it" needs.
   *
   * NOTHING IS WRITTEN INTO THE CACHE. `uploadSourceFile` resolves `void` on purpose: the 201 body
   * is a `draft`/`queued` snapshot, and the row a screen shows has to be the server's answer with
   * the lifecycle state the pipeline has actually reached. A speculative row here would be the
   * optimistic write this path forbids, and it would be wrong within seconds.
   *
   * The dependency list is `succeeded` as well as `settled`, so a row RETRIED after the batch
   * finished invalidates again. Invalidation is idempotent; a missed one is a list that quietly
   * disagrees with the truth.
   */
  useEffect(() => {
    if (!settled || uploads.succeeded === 0) return;
    void queryClient.invalidateQueries({ queryKey: listKey });
  }, [settled, uploads.succeeded, queryClient, listKey]);

  return (
    <form
      // POST, never the browser's default GET. Asserted for every form by
      // tests/unit/form-method.test.ts.
      method="post"
      /**
       * `handleSubmit` IS THE GATE, AND IT POSTS NOTHING.
       *
       * There is no JSON body on this path — each file is its own multipart request — so the
       * resolver's output is not a payload, it is a verdict: the parse is what stops a batch over
       * this organization's cap, or containing a file over its ceiling, from being sent at all. The
       * handler's argument is therefore deliberately unused, and `getValues()` is not read either.
       *
       * Client validation remains a UX affordance and never a control (rhf-zod-forms NN2). The
       * server re-checks every byte of it and additionally checks an extension allow-list this app
       * cannot see, which is why a file that passes here can still be refused — see the note in the
       * card below, and the row that will carry the refusal.
       */
      onSubmit={form.handleSubmit(() => {
        uploads.start();
      })}
      // The browser's own validation bubbles would pre-empt the server's messages and cannot be
      // styled or read consistently by a screen reader.
      noValidate
      // Sections are separated by --space-8, never by a divider (the composition law).
      className="flex flex-col gap-8"
    >
      <Card>
        <CardHeader>
          <CardTitle>Choose files</CardTitle>
          <CardDescription>
            Each file becomes a source. Your bots answer from what you add here, and every answer
            cites the file it came from.
          </CardDescription>
        </CardHeader>

        <CardContent className="flex flex-col gap-3">
          <UploadDropzone
            limits={limits}
            selectedCount={uploads.items.length}
            onFilesChosen={addFiles}
            disabled={uploads.isPending}
            invalidMessageId={batchMessage === undefined ? undefined : batchErrorId}
          />

          {/* THE CEILINGS, IN THE READER'S UNITS, READ OFF THE DTO. `formatBytes` is the same ladder
              the rows use — one function, so a row and this sentence can never disagree about what
              10 MB means. */}
          <p className="text-sm text-muted-foreground">
            Up to {limits.max_batch} files at a time, and up to {formatBytes(limits.max_bytes)}{' '}
            each.
          </p>

          {/* ── THE PICKER'S FILTER IS NOT A PROMISE, AND SAYING SO IS THE WHOLE MITIGATION ──────
              `accept=` is built from `allowed_mime`, which is ONE of the two terms the server admits
              a file on: the other is an extension allow-list it does not publish, and the two sets
              are not in bijection (`.md` and `.csv` both sniff as `text/plain`, so a `text/plain`
              filter offers a `.txt` the extension step refuses). Guessing the extension list here
              would be a second copy of a server rule nobody sent us — the drift this repo keeps
              removing — so the picker stays as wide as the published list and this sentence stops it
              being read as a guarantee. Reported to the control plane as the fix that would actually
              close it: publish `allowed_extensions` on `OrgUploadLimitsResource`.

              A `<p>`, not an `<Alert>`: nothing has happened and nothing is wrong. `<Alert>` carries
              `role="alert"`, and announcing a standing caveat every time this card renders is the
              live-region abuse kb-ui-accessibility names. */}
          <p className="text-sm text-muted-foreground">
            The picker filters by file type as a convenience, not as a promise. Every file is
            checked again when it arrives — by its contents and by its name — so one can still be
            turned down. If that happens, that file’s row says why.
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>
            {uploads.items.length === 0
              ? 'This batch'
              : `This batch (${uploads.items.length} of ${limits.max_batch})`}
          </CardTitle>
          <CardDescription>
            Each file uploads on its own. One being turned down does not stop the others, and you
            can cancel any of them until its bytes are gone.
          </CardDescription>
        </CardHeader>

        <CardContent className="flex flex-col gap-4">
          {/* ── THE BATCH-LEVEL BANNER, WHICH IS NOT WHERE A PER-FILE MESSAGE EVER GOES ──────────
              Two sources, both about the batch rather than about a file: the `files` path itself
              (the at-least-one rule, this organization's cap, or a 422 keyed on the bare attribute)
              and `root.serverError`, which is where `applyServerErrors` routes a key this form does
              not render. Both are end-user copy — Laravel translates validation messages — unlike
              the envelope's `message`, which is operator-facing and reaches no rendered string here.

              `id` is handed to the dropzone's input as `aria-describedby`, so the control the
              message is about actually carries it. */}
          {batchMessage !== undefined || rootMessage !== undefined || issues.size > 0 ? (
            <Alert variant="destructive">
              <AlertDescription className="flex flex-col gap-1">
                {/* The id is on THIS SPAN and not on the alert, because it is what the file input
                    points `aria-describedby` at — and the two lines below are not about the
                    control. A describedby on the whole alert would read the per-file summary out
                    every time focus reached the picker. */}
                {batchMessage === undefined ? null : <span id={batchErrorId}>{batchMessage}</span>}
                {rootMessage === undefined ? null : <span>{rootMessage}</span>}

                {/* ── THE SUMMARY, AND IT EXISTS BECAUSE PRESSING UPLOAD LOOKED LIKE NOTHING ─────
                    Found in the design pass at 768: with one file over the ceiling, `handleSubmit`
                    re-ran the resolver, refused, and changed nothing on screen — the offending row
                    had been marked since the moment it was chosen, so the click had no visible
                    consequence. That is the "clicking Save does nothing, repeatedly" loop from the
                    other direction: the form was right and said so in the wrong place.

                    It counts rather than listing: the messages are per file and already ON their
                    files, so repeating five of them here would be the batch banner that per-row
                    errors exist to avoid. */}
                {issues.size > 0 ? (
                  <span>
                    {issues.size === 1
                      ? '1 file can’t be uploaded as it is.'
                      : `${issues.size} files can’t be uploaded as they are.`}{' '}
                    Each one is marked below — remove it, or replace it with something this
                    organization accepts.
                  </span>
                ) : null}
              </AlertDescription>
            </Alert>
          ) : null}

          <UploadFileList uploads={list} issues={issues} />
        </CardContent>

        <CardContent className="flex flex-wrap items-center gap-3">
          {/* GATED ON THIS SCREEN'S OWN SELECTION STATE, never on `formState.isDirty`: RHF
              explicitly does not track `File` objects, so a dirty-gated button stays greyed out
              after the user has chosen five files (rhf-zod-forms). It is NOT gated on `isValid`
              either — a disabled button with an error beside it is a puzzle; `handleSubmit` refuses
              the submit and the messages say what to fix. */}
          <Button type="submit" disabled={queued === 0 || uploads.isPending}>
            {uploads.isPending
              ? 'Uploading…'
              : queued === 0
                ? // The disabled state, which is reached twice: before anything is chosen, and after
                  // a batch has settled. "Upload 0 files" is what a naive plural produces and it is
                  // a statement about a batch that does not exist.
                  'Upload files'
                : queued === 1
                  ? 'Upload 1 file'
                  : `Upload ${queued} files`}
          </Button>

          {/* An `<a>`, so it is a link and behaves like one — middle-click, copy address, and the
              browser's own affordances. A button that navigates is a link that lost its powers. */}
          <Button variant="outline" asChild>
            <Link href="/sources">
              {/* Not "Back": the label says where it goes, because it is also the way a screen
                  reader user hears it out of context. */}
              Go to sources
            </Link>
          </Button>
        </CardContent>
      </Card>
    </form>
  );
}
