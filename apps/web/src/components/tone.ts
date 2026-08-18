/**
 * The closed set of six categorical tints (kb-design-language, *Tone*).
 *
 * TWO RULES, and both are load-bearing:
 *
 * 1. ASSIGNMENT IS FROM A STABLE KEY, NEVER A POSITION. Mapping a tone from an array index into a
 *    sorted list means a user who re-sorts a table watches every category change colour, which
 *    destroys the only thing the tone was doing. Hence a lookup object keyed by the source type.
 *
 * 2. THE LOOKUP RETURNS WHOLE CLASS STRINGS. Tailwind's scanner is a plain-text pass over source
 *    files, not an evaluator, so `` `bg-tone-${x}-surface` `` is a name that never appears literally
 *    and therefore never exists — the element renders with no background, with no error and no build
 *    warning (tailwind-shadcn Gotcha 3).
 *
 * Tones are DECORATIVE AND CATEGORICAL ONLY. A tone does not mean "good" or "urgent"; reaching for
 * `--tone-rose-*` to signal danger is how a colourblind user loses the only cue. Semantic outcomes
 * are the status family.
 *
 * The set is closed at six because six low-chroma tints are already near the discrimination limit.
 * A seventh category becomes "Other"; a seventh tone means the screen is categorising too finely.
 */
export type ToneName = 'amber' | 'sky' | 'violet' | 'mint' | 'rose' | 'slate';

export interface ToneClasses {
  /** Wash + its paired text. There is no muted variant of a tone. */
  readonly surface: string;
  /**
   * Only when the tile sits on `--card` rather than on `--canvas` and needs an edge.
   * A RING, not an arbitrary `shadow-[...]`: a literal shadow value is a review failure, and a ring
   * also composes with the elevation shadow in one property instead of fighting it.
   */
  readonly edge: string;
}

export const TONE: Readonly<Record<ToneName, ToneClasses>> = Object.freeze({
  amber: { surface: 'bg-tone-amber-surface text-tone-amber-foreground', edge: 'ring-1 ring-tone-amber-border' },
  sky: { surface: 'bg-tone-sky-surface text-tone-sky-foreground', edge: 'ring-1 ring-tone-sky-border' },
  violet: { surface: 'bg-tone-violet-surface text-tone-violet-foreground', edge: 'ring-1 ring-tone-violet-border' },
  mint: { surface: 'bg-tone-mint-surface text-tone-mint-foreground', edge: 'ring-1 ring-tone-mint-border' },
  rose: { surface: 'bg-tone-rose-surface text-tone-rose-foreground', edge: 'ring-1 ring-tone-rose-border' },
  slate: { surface: 'bg-tone-slate-surface text-tone-slate-foreground', edge: 'ring-1 ring-tone-slate-border' },
});

/**
 * The product's categorical keys, mapped once.
 *
 * `satisfies` rather than a loose Record: the object is exhaustive over the source-type union, so
 * adding a source type without giving it a tone is a TYPE ERROR rather than a screen where one
 * category silently renders untinted.
 */
export type SourceKind = 'document' | 'spreadsheet' | 'presentation' | 'website' | 'image';

export const SOURCE_TONE = {
  document: 'sky',
  spreadsheet: 'mint',
  presentation: 'amber',
  website: 'violet',
  image: 'rose',
} as const satisfies Record<SourceKind, ToneName>;
