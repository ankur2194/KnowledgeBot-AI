# The four states, and the words in them

A surface that renders fetched data has **four** states, and they ship together. Building the success state first and "adding the others later" reliably produces a screen where three of the four are a spinner, a blank box and a red string — which is to say, three of the four are unfinished.

There are actually six, because two of them split:

| State | Split | What the user is looking at |
| --- | --- | --- |
| Loading | first load · refetch | Nothing yet · something already correct |
| Empty | **first-run** · **filtered** | Nothing exists · nothing matched |
| Error | retryable · terminal | Something you can try again · something you cannot |
| Forbidden | — | Something that exists but is not theirs to see |

## Loading

**First load is a skeleton, not a spinner**, and the skeleton mirrors the loaded layout closely enough that nothing reflows when data arrives. A skeleton that is a different height from the real content produces a page that jumps on every load and reads as a rendering bug.

- Blocks are `--card-inset` with the shimmer from `kb-motion-and-effects` E7, `--radius-md` for text lines and the real radius for real shapes. Text-line skeletons vary in width (100% / 80% / 60%) — three identical bars read as a broken table.
- The container carries `aria-busy="true"` and the skeleton blocks are `aria-hidden`. Do not announce a skeleton.
- **Refetch is not a skeleton.** Data that is already on screen and still correct stays on screen. Show a subtle progress affordance — a 2px indeterminate bar under the card header, or a spinner in the refresh control — and never blank out content the user is reading.
- Show nothing at all for the first ~150ms. A skeleton that flashes for 80ms is worse than a still frame.

## Empty

The two empties are different states with different copy and different actions, and conflating them is the most common content bug in an admin console.

**First-run empty** — the collection has never had anything in it. This is an onboarding moment.
- A 24px icon in a `--tone-slate-surface` circle, an `--text-h3` line, one `--text-base` sentence in `--muted-foreground` explaining what will live here, and **the primary action**.
- *"No sources yet — upload a document, a spreadsheet or a website and this bot can start answering from it."* + `[ Add source ]`

**Filtered empty** — things exist; the current filter matched none of them. This is a correction moment.
- Same geometry, no illustration, and the action is **Clear filters**, not the create action. Restate the filter so the user can see what they asked for.
- *"No sources match “invoice” with status Failed."* + `[ Clear filters ]`

Offering "Upload your first document" to someone whose search matched nothing is the failure this split exists to prevent.

**Zero is not empty.** A metric with a real value of zero renders `0`. An unavailable metric renders an em dash with a tooltip saying why. "No data yet" is a third thing. Pick deliberately; a `0` where the truth is "we never measured this" is a false statement.

## Error

Render the sentence mapped from `error_class` plus the `request_id`. **Never the envelope's `message`** — that field is operator-facing and can carry an internal hostname, raw upstream provider text, or an identifier nobody wrote for a reader. Log it; show the class-mapped sentence (`kb-error-taxonomy`, `kb-internal-api-contracts`).

- `error_class: null` means no envelope parsed, which means **unknown**, and unknown is permanently non-retryable. **No "Try again" button on it.** Offering a retry that cannot help is worse than offering nothing.
- Retryable classes get one retry affordance, and it is a button — not an automatic loop the user cannot see or stop.
- Scale the presentation to the blast radius: a whole page that failed is a centred block; one card that failed is a `--destructive-soft` banner inside that card while the rest of the page works; a failed row is an inline pill in the row.
- `request_id` renders in `--font-mono` `--text-caption`, with a copy affordance. It is the one string that makes a support conversation short.
- The words: what happened, in the user's terms; whether it is theirs to fix; what to do next. *"We couldn't reach the provider for this bot. This is usually temporary."* — not *"Upstream 502"*, and not *"Something went wrong."*

## Forbidden

Something exists and is not theirs.

- **Admin surfaces answer 403 and say so**: the user is authenticated, in the right org, and lacks the role. *"You need the Admin role to manage provider credentials. Ask an owner of Acme to change your role."* Naming the role and who can grant it turns a dead end into an action.
- **Public surfaces answer 404 and reveal nothing** — no "you lack permission", which confirms the resource exists. The deny split is `laravel-rbac-policies`'; the UI must not undo it by rendering a 404 with a permissions explanation.
- A user who cannot perform an action does not see a disabled button for it. Hide it. A disabled control with no explanation is a puzzle, and one with an explanation is a permissions disclosure.

## Two more that are not states but are always forgotten

**Partial success.** A batch where 8 of 10 rows worked. The screen shows the successes, lists the failures with their per-row reason, and offers a retry scoped to the failures. A partial that renders as a success is a data-loss bug the user finds out about later; a partial that renders as a failure loses eight rows of real work.

**Degraded.** Something worked, but not fully — an answer produced without reranking because the provider could not rerank, a source indexed with OCR coverage below the floor. Say so, quietly and in place: a `--warning-soft` inline note or a badge on the affected item, with a link to what it means. Silence here is the failure mode `docs/23` records for the blur ≥ 3.0 case, where a real degradation reaches the index and nothing warns.

## Microcopy rules

- **Sentence case everywhere.** Titles, buttons, labels, menu items. No Title Case, no ALL CAPS except the `--text-caption` column labels.
- **Buttons are verbs, and they are the specific verb.** "Add source", "Delete bot", "Invite member" — not "Submit", "OK", "Confirm". The verb in the button matches the verb in the dialog title.
- **Say what will happen, in numbers, before something irreversible.** *"This removes 1,204 chunks from 3 bots and cannot be undone."*
- **Second person, present tense, active voice.** "You need the Admin role", not "The Admin role is required".
- **No blame and no apology.** Not "You entered an invalid URL" and not "Sorry, something went wrong". State the condition and the fix: *"That URL isn't reachable. Check the address, or allow our crawler in your robots.txt."*
- **Never surface internal vocabulary.** Chunks, embeddings, collections, spans, queues and error classes are ours. The user has documents, answers, sources and bots. `request_id` is the deliberate exception, because it is a handle, not a concept.
- **Dates are relative with the absolute available.** "2 days ago" with the full timestamp in `title`, in the user's locale and timezone. A bare ISO string in a UI is an unfinished cell.

## Checklist

- [ ] First-run empty and filtered empty are distinct components with distinct copy and distinct actions
- [ ] Skeleton mirrors the loaded layout; the transition was watched, not just read
- [ ] Refetch does not blank content that is still correct
- [ ] No `message` from the error envelope reaches a rendered string; `request_id` is shown and copyable
- [ ] No retry affordance on a non-retryable class or on `error_class: null`
- [ ] Forbidden is 403-with-the-role on admin and 404-with-nothing on public
- [ ] No disabled control stands in for a hidden one
- [ ] Partial success lists its failures with per-row reasons and a scoped retry
- [ ] Degradation is stated where it happened, not only in a log
- [ ] Buttons are specific verbs; no Title Case; no internal vocabulary
