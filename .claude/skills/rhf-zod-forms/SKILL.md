---
name: rhf-zod-forms
description: React Hook Form + Zod form schemas for apps/web and packages/contracts — keeping a schema from drifting from the Laravel FormRequest that enforces the rules, mapping a 422 onto per-field errors, and upload constraints. Use whenever adding or editing a form, resolver, submit handler, error mapper, or file picker, or when a form submits cleanly and the server rejects it. Ownership columns are unrepresentable in a form schema. Pairs with kb-error-taxonomy (the error envelope) and laravel-rbac-policies (the FormRequest opposite).
---

# React Hook Form + Zod Forms

`react-hook-form` **7.84.0**, `zod` **4.4.3**, `@hookform/resolvers` **5.7.1** — one `@hookform/resolvers/zod` entry point serves Zod 3 and 4 and sniffs the major at runtime. Zod 4 needs `"moduleResolution": "bundler" | "node16" | "nodenext"` in `tsconfig.json` or its subpath exports fail to resolve. RHF **8.x is beta only** and there is no Zod 5 — chase neither.
**Authoritative spec:** docs/05-tech-stack.md §9.1, docs/17-testing-performance.md §22.2, docs/12-api-areas.md §17.1, docs/02-functional-auth-tenancy-bots.md §8.3–8.4, docs/03-functional-knowledge-sources.md §8.10

## Non-negotiables

1. **`organization_id` and every other ownership column are absent from every schema, every `defaultValues` object, and every submitted payload.** The server guards them — not fillable, not in any rule set (`laravel-rbac-policies` NN 5) — so a client that sends one is attempting privilege escalation and gets a silent 200. The org comes from the authenticated context, never from request input (`kb-tenancy-isolation` NN 6). Schemas are `z.strictObject` so the mistake fails a test instead of needing a reviewer to spot it.
2. **Client-side validation is a UX affordance, never a control.** Everything Zod checks the FormRequest checks again, and nothing is trusted because the form checked it. Upload type and size especially: `kb-security-baseline` owns upload safety, and its extension/MIME allow-lists, size caps, and content sniffing are the enforcement.
3. **A schema ships with a committed rule manifest and a drift test, or it does not ship** (docs/17 §22.2, *"contracts between frontend and Laravel"*). The failure is asymmetric: a form looser than the server produces a visible 422; a form *stricter* than the server silently removes functionality nobody reports.
4. **Branch on `error_class`, never on HTTP status, and never render `message` to an end user.** The envelope is `{error_class, message, retryable, request_id}` (`kb-error-taxonomy`, `kb-internal-api-contracts`); `message` is operator-facing. One class renders two statuses — `authorization` is 403 on admin surfaces, 404 on public ones.
5. **Forms submit to Laravel, never to a Next.js route handler that reaches FastAPI.** The App Router makes that reachable in principle and `docs/22` records it as an unratified deviation; do not be the first caller. Validation, authorization, and rate limiting live in the control plane (`kb-architecture-map`).
6. **No provider credential is ever seeded into form state.** The API returns `masked_key` (`sk-…4a91`); it is display text, never a `defaultValues` entry and never a field value.

## How we use it

Schemas are shared TypeScript, so they live once in `packages/contracts` (§27) and are imported by `apps/web` and `apps/mobile`. Nothing tenant-scoped and nothing secret goes in them.

`packages/contracts/src/forms/*.ts` (schemas + defaults builders, plus `ownership.ts` holding `OWNERSHIP_KEYS`), `packages/contracts/rules/*.json` (generated, committed), `packages/contracts/test/form-drift.test.ts`, and `apps/web/src/lib/forms/apply-server-errors.ts`.

### Drift: duplicate deliberately, diff behaviourally

The rules exist twice — a Zod schema in the browser, a `FormRequest` in `services/core-api` — and only the second is enforcement. We keep both and make disagreement a red test. The alternatives lose for concrete reasons. **Generating Zod from `rules()`** yields a schema silently *weaker* than the server for exactly the rules that matter: `exists:`, `unique:`, `Rule::when`, closures, and cross-field `required_if` have no Zod expression and generate to nothing — and the first hand-edit kills regeneration forever. **One shared schema (JSON Schema, or the OpenAPI contract) as source of truth** cannot express `prepareForValidation`, authorization, or DB-backed rules, so Laravel keeps a second hand-written layer and the drift returns. §9.4's *"OpenAPI generated from maintained contracts"* is a **third** copy: the manifest below is dumped from the FormRequest's own `rules()`, from executing code, never from the OpenAPI document — otherwise you diff two documents while the enforcing code walks away from both.

**The manifest.** `php artisan kb:dump-form-rules` instantiates every registered FormRequest, normalizes `rules()` to canonical strings (Stringable rule objects via `(string)`; closures and DB-backed rules become `"@server-only"`), and writes one JSON file per endpoint into `packages/contracts/rules/`. CI re-runs the dump and fails on `git diff --exit-code`, so a rule change nobody dumped breaks Laravel's own pipeline.

```json
{ "endpoint": "PATCH /api/v1/organizations/{organization}/bots/{bot}",
  "fields": { "name": ["required","string","max:120"], "slug": ["required","string","max:60","@server-only"],
              "retrieval.top_k": ["required","integer","min:1","max:50"],
              "starter_questions": ["array","max:6"], "starter_questions.*": ["string","max:200"] } }
```

**The test compares behaviour, not structure.** Matching `z.string().max(120)` against the string `"max:120"` needs a rule interpreter that drifts on its own. Instead the manifest generates probe values and both sides answer the same yes/no question:

```ts
// packages/contracts/test/form-drift.test.ts
for (const [path, rules] of Object.entries(manifest.fields))
  for (const { value, serverAccepts } of probesFor(rules)) {         // max:120 → 120 chars ok, 121 not
    const clientAccepts = accepts(botSettingsSchema, path, value);
    // Two separately-named failures: one is bad UX, the other is invisible lost functionality.
    if (serverAccepts) expect(clientAccepts, `form blocks input the server accepts: ${path}`).toBe(true);
    else               expect(clientAccepts, `form accepts input the server rejects: ${path}`).toBe(false);
  }
expect(new Set(schemaPaths(botSettingsSchema))).toEqual(new Set(Object.keys(manifest.fields)));
```

`probesFor` covers `required` (omitted), `nullable` (null), `min`/`max`/`between` (boundary ± 1), `in:` (each member plus a non-member), and type mismatch. `"@server-only"` fields are asserted **present** in the schema but exempt from value comparison — a client cannot evaluate `exists:`, and pretending otherwise is how you ship a form that blocks a valid slug. `email` and `url` are value-exempt too: Laravel's `email` is egulias/RFC validation, `z.email()` is a regex, and they will never agree on the edge corpus.

### One form, end to end

```ts
// packages/contracts/src/forms/bot.ts
/** Empty number inputs post "". Number("") === 0, so a bare z.coerce.number().min(1)
 *  says "must be at least 1" on a cleared field instead of "required". */
const intField = (min: number, max: number) =>
  z.preprocess(v => (v === "" || v === null ? undefined : v), z.coerce.number().int().min(min).max(max));

/** strictObject, not object: an unknown key means our defaults builder leaked a server field
 *  into form state. z.object() strips it silently and hides the bug until something bypasses
 *  the parse — FormData uploads do. Ownership keys are unrepresentable here by construction. */
export const botSettingsSchema = z.strictObject({
  name:              z.string().trim().min(1, { error: "Name is required" }).max(120),
  status:            z.enum(["draft", "testing", "published", "paused", "archived"]),
  welcome_message:   z.string().trim().max(500),
  starter_questions: z.array(z.string().trim().min(1).max(200)).max(6),
  retrieval:         z.strictObject({ top_k: intField(1, 50) }),
});
export type BotSettingsIn = z.input<typeof botSettingsSchema>;
export type BotSettingsOut = z.output<typeof botSettingsSchema>;

/** Never reset(resource). The API Resource carries id, organization_id, timestamps and counters;
 *  reset() replaces form state with exactly what it is handed, getValues() returns those keys,
 *  and submit posts them back. This pick is the only path from server data into form state. */
export const botFormDefaults = (b: BotResource): BotSettingsIn => ({
  name: b.name, status: b.status, welcome_message: b.welcome_message,
  starter_questions: b.starter_questions, retrieval: { top_k: b.retrieval.top_k },
});
```

```tsx
// apps/web/src/features/bots/bot-settings-form.tsx
"use client";
export function BotSettingsForm({ bot }: { bot: BotResource }) {
  // Three generics, input then output: a field with .default() is optional on z.input and
  // required on z.output, so one generic pins both and fails to typecheck against zodResolver.
  const form = useForm<BotSettingsIn, unknown, BotSettingsOut>({
    resolver: zodResolver(botSettingsSchema), defaultValues: botFormDefaults(bot), mode: "onTouched",
  });

  const save = useMutation({
    // handleSubmit hands us the resolver's OUTPUT — parsed, coerced, stripped. Post that,
    // never getValues(), which is raw form state and has been through no schema at all.
    mutationFn: (values: BotSettingsOut) => patchBot(bot.id, values),
    retry: false,                        // exactly one tier retries, and it is not this one
    onError: (e: KbErrorEnvelope) =>
      e.error_class === "validation"
        ? applyServerErrors(form, KNOWN_PATHS, e.errors)   // per-field; orphans → root
        // error_class, never e.status: `authorization` is 403 here and 404 on public surfaces,
        // and a 422 can also be an idempotency conflict carrying no `errors` map at all.
        : form.setError("root.serverError", { type: e.error_class, message: endUserCopy(e) }),
  });

  return (                             {/* fields via shadcn Form primitives — tailwind-shadcn */}
    <form onSubmit={form.handleSubmit(v => save.mutate(v))} noValidate>
      {form.formState.errors.root?.serverError && (
        <Alert role="alert">{form.formState.errors.root.serverError.message}</Alert>)}
    </form>
  );
}
```

```ts
// apps/web/src/lib/forms/apply-server-errors.ts
export function applyServerErrors<I extends FieldValues, O>(
  form: UseFormReturn<I, unknown, O>,
  knownPaths: string[],             // from the generated manifest — the server's own field vocabulary
  errors: Record<string, string[]>, // Laravel's 422 `errors` map
) {
  const known = new Set(knownPaths), orphans: string[] = []; let focused = false;
  for (const [key, messages] of Object.entries(errors)) {
    // Laravel already keys nested and array errors as dot paths — "retrieval.top_k",
    // "starter_questions.2" — which are valid RHF names as-is (RHF rejects bracket syntax,
    // and Laravel never emits it). No translation, only the index → `*` fold for lookup.
    if (known.has(key.replace(/\.\d+/g, ".*"))) {
      // `type` is mandatory: an untyped setError on a parent path replaces child errors.
      form.setError(key as Path<I>, { type: "server", message: messages[0] }, { shouldFocus: !focused });
      focused = true;
    } else orphans.push(...messages);   // a rule on a field this form does not render
  }
  // Validation strings ARE end-user copy — Laravel translates them. Envelope `message` is not.
  if (orphans.length) form.setError("root.serverError", { type: "server", message: orphans.join(" ") });
}

/** Envelope `message` is operator-facing — provider text, model ids, internal hostnames. Render
 *  our own copy plus request_id so support can grep the failure across both services. */
export const endUserCopy = (e: KbErrorEnvelope) => `${COPY[e.error_class] ?? COPY.default} (ref ${e.request_id})`;
```

Both `setError` calls must happen **after** `handleSubmit` has run: with a resolver it assigns `_formState.errors` wholesale and then unsets the whole `root` namespace, so anything written before or during validation is discarded. Writing them from the mutation's `onError` (as above) is what makes them stick, and it also means a stale server error never survives into the next attempt. We do not use `useForm`'s `errors` prop for this: it wants errors already in RHF's nested shape and gives orphans nowhere to go.

### Upload forms

Zod 4 ships `z.file()` with `.min()`, `.max()`, `.mime()` — use it over `z.instanceof(File)`. The limits are **not constants**: §8.10 makes maximum file size and per-organization storage limits configurable, so the schema is a factory over the limits the bootstrap config returns, and the drift test asserts it is a function of that DTO rather than of a hard-coded number.

```ts
export const uploadSchema = (l: OrgUploadLimits) => z.strictObject({
  files: z.array(z.file().max(l.max_bytes).mime(l.allowed_mime)).min(1).max(l.max_batch),
});
```

`.mime()` reads `File.type`, which the browser derives from the **extension** on most platforms and which any caller can forge. It filters the picker and produces a fast message; the server's extension allow-list, libmagic sniffing independent of filename, compression-ratio caps, and malware hook are the control (`kb-security-baseline` → `references/file-upload-safety.md`). A `.pdf` that is really a ZIP passes every client check and must still be rejected server-side, so the upload form renders server field errors like any other form. Multipart submission, progress, and cancellation belong to `tanstack-query-table`; note only that FormData is assembled by hand from an explicit key allow-list, because a strict-object parse cannot protect a payload it never sees.

## Gotchas

- **A `PATCH` returns 200, the bot is unchanged, and the audit row shows a write.** `reset(botResource)` seeded the whole API Resource into form state — RHF keeps every key handed to `reset`, `shouldUnregister` defaults to `false` so nothing prunes them, and `id`/`organization_id`/`created_at` rode the submit back. It is silent because Laravel guards the column rather than erroring. Never hand a server object to `reset`/`defaultValues`; go through a `*FormDefaults` pick and keep `z.strictObject` so CI catches it.
- **Clicking Save does nothing, repeatedly, and the network tab shows the same 422 every time.** The server returned a key this form does not render — `withValidator`, `$validator->after()`, or a service-layer `ValidationException` all emit keys no rule set lists — and `setError` attached it to an unregistered name, which displays nowhere. It does not even persist: with a resolver, the next `handleSubmit` replaces `formState.errors` wholesale, so the request re-fires and the loop closes with no visible state anywhere. Partition against the manifest's key set and route the remainder to `root.serverError`.
- **A field error appears, then a later error wipes it and nobody notices.** `setError` was called without `type` on a parent path (`"retrieval"`) after a child path (`"retrieval.top_k"`); the parent write replaces the child subtree. Always pass `type`.
- **`shouldFocus: true` silently does nothing on half the form.** Focus needs the `ref` that `register` installs, and it is skipped on disabled inputs; shadcn's `Select`, `Switch`, and any `Controller` whose `field.ref` was never attached have none, so the page does not scroll and the user never sees the error below the fold. Focus the first *registered* field, or scroll to the error container yourself.
- **A field rendered `disabled` to show it is read-only 422s as `required`.** RHF strips disabled names from the submitted values, so the key never reaches the server. Use `readOnly` for display, or drop the rule; a disabled control is a client-side decision the FormRequest knows nothing about.
- **The Save button stays greyed out after the user picks a file.** It was gated on `formState.isDirty`, which explicitly does not track `File` objects or `FileList` — RHF's own docs say file inputs must be managed at the app level, and RHF only ever *clears* a file input's DOM value, never writes one. Gate on your own selection state.
- **A form that validates cleanly is rejected as one character too long.** Laravel's global `TrimStrings` middleware runs before `max:120`, so `"  " + 119 chars` is 119 server-side and 121 in the browser. Put `.trim()` on every string field. Its sibling `ConvertEmptyStringsToNull` is the same trap inverted: `""` arrives as `null`, so a `nullable|string` field typed `z.string()` disagrees with the server about the empty case.
- **Clearing a number input shows "must be at least 1" instead of "required".** `z.coerce.number()` is `Number()`, and `Number("")` is `0`. Preprocess `""`/`null` to `undefined` first (see `intField`). `register(..., { valueAsNumber: true })` has the mirror problem: an empty input becomes `NaN` and `z.number()` rejects it with a type error nobody can act on.
- **`useForm<BotSettings>(...)` stops typechecking the moment a field gains `.default()`.** That field is optional on `z.input` and required on `z.output`; one generic pins both and conflicts with `zodResolver`. Use `useForm<z.input<S>, unknown, z.output<S>>` — the resolvers README documents this exact case.
- **A validation error surfaces as "something went wrong".** The handler branched on `response.status === 422`. Idempotency conflicts also return 422, with an `error_class` and **no** `errors` map (`docs/22`), and `authorization` returns 403 or 404 by surface. Branch on `error_class` only.
- **Every provider call starts failing `provider_auth` after someone renamed a connection.** The edit form prefilled `api_key` from the resource's `masked_key` and submitted `"sk-…4a91"` as the new key. Model the field as optional-means-unchanged, never seed it, and render the mask as text outside the form.
- **`ReferenceError: FileList is not defined` in `next build` or Vitest.** `z.instanceof(FileList)` evaluates at module load and `FileList` is a DOM type absent from Node, so importing a shared schema from a server component or a node-environment test crashes the whole file. `File` is a Node ≥20 global and is safe; use `z.file()` and read `Array.from(input.files ?? [])`.
- **Errors vanish from a field named `root`.** `@hookform/resolvers` dropped errors for the special names `root`, `constructor`, and `__proto__` until 5.5.8/5.6.0. Pin ≥5.6.0 and, separately, never name a domain field `root`. Relatedly, `errors[name].types` is always undefined unless `useForm` sets `criteriaMode: "all"` — the default `firstError` yields one issue per field and the resolver honours it.
- **The drift test is green and the form still disagrees with production.** Either the manifest was dumped from a stale checkout, or `rules()` reads `$this->route('bot')`, the dump command fataled on a null route, and that request got quietly excluded. Requests whose rules vary by route or user expose a static shape and push the dynamic part behind `"@server-only"`; CI fails on any registered FormRequest missing from the manifest.
- **A crawl URL the form accepted comes back as `validation`.** No client check can evaluate SSRF — it needs DNS resolution and per-hop redirect revalidation (`kb-security-baseline`). `z.url()` plus an explicit `http`/`https` protocol refinement mirroring Laravel's `url:http,https` is the entire client-side contribution; the field must render the server's message rather than assume its own check sufficed.

**Not defined here.** App Router layout, RSC/client boundary, route handlers, caching — `nextjs-app-router`. `Form`/`FormField` primitives, field markup, error styling — `tailwind-shadcn`. Mutations, cache invalidation, optimistic updates, upload progress — `tanstack-query-table`. Test harness, component tests, the §22.4 E2E flows — `vitest-playwright`. The FormRequest, its rules, ownership-column guarding — `laravel-rbac-policies`, `laravel-control-plane`. Error classes, statuses, retry policy — `kb-error-taxonomy`; the envelope wire shape — `kb-internal-api-contracts`.

## Official docs

- [React Hook Form — `setError`](https://react-hook-form.com/docs/useform/seterror) — `root.serverError`, why `type` matters on nested paths, that root errors do not persist across submissions, and that `shouldFocus` needs a registered `ref`.
- [React Hook Form — `useForm`](https://react-hook-form.com/docs/useform) — the three generics, `mode`/`reValidateMode`, `criteriaMode`, `shouldUnregister`, and the `errors` prop (the alternative server-error channel we do not use).
- [Zod — API](https://zod.dev/api) and [v4 changelog](https://zod.dev/v4/changelog) — `z.strictObject`, `z.file`, top-level `z.email`/`z.url`, and the unified `error` param that replaced `message`/`required_error`/`invalid_type_error`.
- [Zod — error formatting](https://zod.dev/error-formatting) — `z.treeifyError`, `z.flattenError`, `z.prettifyError`, and the deprecation of `.format()`/`.flatten()`.
- [@hookform/resolvers](https://github.com/react-hook-form/resolvers) — the single `zod` entry point for both majors, the `raw` resolver option, `criteriaMode` handling, the `.default()` generics note.
- [Laravel — Validation](https://laravel.com/docs/13.x/validation) — the 422 body shape and how `errors` keys are formed for nested and array attributes.

## Definition of done

- [ ] Every new or changed schema is `z.strictObject`; a test asserts no schema path intersects `OWNERSHIP_KEYS` (`organization_id`, `org_id`, `user_id`, `created_by`, `id`). `rg -n 'reset\(|defaultValues:' apps/web packages/contracts` shows no server resource passed directly — each goes through a `*FormDefaults` pick.
- [ ] `packages/contracts/rules/*.json` is regenerated by `php artisan kb:dump-form-rules` in CI with `git diff --exit-code` clean, and every registered FormRequest appears in it.
- [ ] `form-drift.test.ts` green for the changed endpoint, both directions asserted separately, a deliberate one-character `max:` change proven to fail it; schema path set equals manifest path set, and `"@server-only"` fields are present but value-exempt.
- [ ] The submit handler posts `handleSubmit`'s argument, not `getValues()`; the mutation sets `retry: false`; every string field carries `.trim()` and every numeric field preprocesses `""`/`null` to `undefined`.
- [ ] The error handler branches on `error_class` only — `rg -n 'status === 4|status === 5' apps/web/src` is empty in form code — and no path renders envelope `message`; `request_id` is shown instead. A test posts a 422 with one known and one unknown key and asserts the first lands on the field, the second on `root.serverError`.
- [ ] Upload schemas are factories over the server's limits DTO; no byte or MIME constant is hard-coded in `apps/web` or `packages/contracts`.
- [ ] Credential fields are optional-means-unchanged and absent from `defaultValues`; a test asserts a submit without `api_key` leaves the stored ciphertext untouched.
