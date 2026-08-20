'use client';

import { SendHorizontalIcon } from 'lucide-react';

import { BotThemeScope } from '@/components/bot-theme-scope';
import type { BotTheme } from '@/lib/theme';

/**
 * WHAT AN END USER ACTUALLY SEES, drawn from the values in the form as they are typed.
 *
 * Five of this tab's fields are invisible on the tab itself — the welcome message, the composer
 * placeholder, the two theme colours and the corner radius are all things a STRANGER reads, on a
 * surface the operator is not looking at. A settings screen that shows only the inputs makes an
 * operator publish a brand colour and find out what it looked like from a customer, so this panel
 * renders the arrangement instead: bot name, opening message, the suggestion chips, the composer.
 *
 * ── IT IS A PREVIEW, WHICH MEANS NOTHING IN IT IS FOCUSABLE ────────────────────────────────────
 * Every element here is a `span` or a `div`. A preview containing real buttons puts three extra
 * stops in the tab order that do nothing when activated, which is worse for a keyboard user than a
 * missing preview: the control looks operable and is not. The send glyph is `aria-hidden` for the
 * same reason — it is a picture of a button. The TEXT is deliberately not hidden: the welcome
 * message and the chips are the content being previewed, and a screen-reader user needs to hear
 * what they just wrote.
 *
 * ── TENANT TEXT IS A JSX CHILD, NEVER MARKUP ───────────────────────────────────────────────────
 * The name, the opening message, the chips and the placeholder are all operator-supplied strings.
 * They are interpolated as children and React escapes them; there is no `dangerouslySetInnerHTML`
 * anywhere on this path, and there is no markdown renderer either — a preview that parsed the
 * welcome message would be a second, unreviewed sanitizer path.
 *
 * ── THE COLOURS COME THROUGH `BotThemeScope`, WHICH IS THE ADMIN-SURFACE MECHANISM ─────────────
 * Properties are written through the CSSOM on a wrapper element, never as a `style=""` attribute
 * and never through a `<style>` element: a nonce does not cover a style attribute, so the attribute
 * form applies in development and silently vanishes under the production CSP. Hosted chat takes the
 * other path — a real stylesheet response at `/c/[publicBotId]/theme.css` — and the two are not
 * interchangeable, because the admin console draws many bots in one document and its own chrome must
 * stay neutral.
 *
 * `safeThemeDeclarations` re-checks the grammar and DROPS anything that fails, and `--primary` is
 * admitted only together with the whole ramp derived from it. So a half-typed `oklch(` previews as
 * the platform theme rather than as a broken one, which is the honest rendering: the server would
 * refuse it too.
 *
 * ── THE CORNER RADIUS IS AN ORDINARY `rounded-*` STEP ──────────────────────────────────────────
 * It reads the tenant's `--radius` because the generated `@theme inline` block carries the derived
 * scale as the CALC ITSELF rather than as `var(--radius-xl)`, so the arithmetic lands in the utility
 * and resolves against the `--radius` this element inherits from `BotThemeScope`. This file used to
 * carry `rounded-(--radius)` as a local workaround for the opposite emission, which previewed the
 * `lg` step while the hosted composer shipped `xl` — the console under-reporting the corner it was
 * there to show. The fix is in `packages/design-tokens/scripts/build.mjs`; the nested-override case
 * is pinned by `tests/components/design-system-css.test.tsx`.
 */
export function BotIdentityPreview({
  name,
  welcomeMessage,
  placeholderText,
  theme,
  questions,
}: {
  readonly name: string;
  /** `null` when the bot opens with no message of its own. */
  readonly welcomeMessage: string | null;
  /** `null` when the composer falls back to the platform's own placeholder. */
  readonly placeholderText: string | null;
  readonly theme: BotTheme;
  /** In `sort_order` order, which is the order they render in. Empty is every bot's default. */
  readonly questions: readonly string[];
}) {
  return (
    <BotThemeScope theme={theme}>
      {/* --card-inset inside a --card, never a second --card: the elevation ladder is tuned for a
          card-on-canvas lightness gap, so the same shadow on a --card parent is invisible. */}
      <div
        // The same `data-slot` convention every vendored primitive carries. It is what lets a spec
        // assert the property that matters here and cannot be asserted any other way: that NOTHING
        // inside this subtree is focusable. Every accessible-name query would match the row controls
        // instead, because their labels quote the very text being previewed.
        data-slot="bot-preview"
        className="flex flex-col gap-4 rounded-2xl bg-card-inset p-card-pad-md"
      >
        <div className="flex items-center gap-3">
          {/* The one place --primary appears at full strength in this preview, which is the point:
              it is the colour a stranger reads the brand from. */}
          <span
            aria-hidden
            className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary text-caption font-semibold text-primary-foreground"
          >
            {initialOf(name)}
          </span>
          <span className="min-w-0 truncate text-base font-medium">{name}</span>
        </div>

        {welcomeMessage === null ? (
          <p className="text-sm text-muted-foreground">
            No opening message. The conversation starts with the composer and nothing above it.
          </p>
        ) : (
          <p className="rounded-xl bg-card p-3 text-base shadow-xs">{welcomeMessage}</p>
        )}

        {questions.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No suggestion chips. The first-run screen offers nothing to click.
          </p>
        ) : (
          <ul className="flex flex-wrap gap-2">
            {questions.map((question, index) => (
              // The index is part of the key because two chips may legitimately carry the same text
              // — the server enforces no uniqueness on the label — and `sort_order` is what makes
              // the pair stable within one render of one ordered list.
              <li
                key={`${String(index)}:${question}`}
                className="max-w-full truncate rounded-full bg-card px-3 py-1 text-sm shadow-hairline"
              >
                {question}
              </li>
            ))}
          </ul>
        )}

        <div className="flex items-center gap-2 rounded-xl bg-card px-3 py-2 ring-1 ring-input">
          {/* A placeholder is TEXT and takes --muted-foreground: --subtle-foreground does not clear
              4.5:1 and is restricted to disabled controls and decoration. */}
          <span className="min-w-0 flex-1 truncate text-base text-muted-foreground">
            {placeholderText ?? 'Ask a question…'}
          </span>
          <span
            aria-hidden
            className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground"
          >
            <SendHorizontalIcon className="size-3.5" />
          </span>
        </div>

        {/* --accent is shadcn's neutral hover wash rather than a second brand colour, and it is
            invisible everywhere else in this preview — so it gets one sample, labelled, instead of
            being a field whose effect the operator has to guess at. */}
        <p className="rounded-xl bg-accent px-3 py-2 text-sm text-accent-foreground">
          Highlight — the wash behind a hovered row or a selected suggestion.
        </p>
      </div>
    </BotThemeScope>
  );
}

/**
 * The avatar glyph. `Array.from` and not `name[0]`: a string index splits a surrogate pair, so a bot
 * called "🌊 Tide" would render half a code point, and `[...name][0]` is the same walk written in a
 * way `security/detect-object-injection` reports on.
 */
function initialOf(name: string): string {
  return (Array.from(name.trim())[0] ?? '?').toUpperCase();
}
