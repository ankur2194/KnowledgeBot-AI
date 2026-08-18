/* GENERATED FILE — do not edit.
 * Source: packages/design-tokens/src/tokens.json
 * Regenerate: pnpm tokens:build
 * Committed on purpose: CI runs the build and then `git diff --exit-code`.
 */

export type TokenName = 'canvas' | 'card' | 'card-inset' | 'popover' | 'overlay' | 'foreground' | 'muted-foreground' | 'subtle-foreground' | 'border' | 'border-strong' | 'input' | 'ring' | 'primary' | 'primary-foreground' | 'primary-hover' | 'primary-active' | 'primary-soft' | 'primary-soft-foreground' | 'accent' | 'accent-foreground' | 'success' | 'success-soft' | 'success-soft-foreground' | 'warning' | 'warning-soft' | 'warning-soft-foreground' | 'destructive' | 'destructive-strong' | 'destructive-foreground' | 'destructive-soft' | 'destructive-soft-foreground' | 'info' | 'info-soft' | 'info-soft-foreground' | 'tone-amber-surface' | 'tone-amber-border' | 'tone-amber-foreground' | 'tone-sky-surface' | 'tone-sky-border' | 'tone-sky-foreground' | 'tone-violet-surface' | 'tone-violet-border' | 'tone-violet-foreground' | 'tone-mint-surface' | 'tone-mint-border' | 'tone-mint-foreground' | 'tone-rose-surface' | 'tone-rose-border' | 'tone-rose-foreground' | 'tone-slate-surface' | 'tone-slate-border' | 'tone-slate-foreground' | 'chart-1' | 'chart-2' | 'chart-3' | 'chart-4' | 'chart-5' | 'chart-6' | 'chart-grid' | 'chart-axis';

export type LegacyTokenName = 'background' | 'card-foreground' | 'popover-foreground' | 'secondary' | 'secondary-foreground' | 'muted';

export type ShadowName = 'hairline' | 'xs' | 'sm' | 'md' | 'lg' | 'xl';

export type RadiusStep = 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | 'full';

export type SpaceName = '1' | '2' | '3' | '4' | '5' | '6' | '8' | '10' | '12' | '16' | '20' | '24' | '0-5' | 'gutter-sm' | 'gutter-md' | 'gutter-lg' | 'card-pad-sm' | 'card-pad-md' | 'card-pad-lg';

export type TypeStep = 'caption' | 'sm' | 'base' | 'md' | 'lg' | 'h3' | 'h2' | 'h1' | 'display' | 'metric';

export type DurationName = 'dur-1' | 'dur-2' | 'dur-3' | 'dur-4';

export type EasingName = 'out' | 'in' | 'in-out' | 'spring';

export type TenantOverridableColorKey = 'primary' | 'primary-foreground' | 'accent' | 'accent-foreground';

export type RadiusValue = '0rem' | '0.25rem' | '0.5rem' | '0.625rem' | '0.75rem' | '1rem';

export interface TokenPair {
  readonly light: string;
  readonly dark: string;
}

export interface TypeStepValues {
  readonly size: string;
  readonly lineHeight: string;
  readonly weight: string;
  readonly tracking: string;
}

export declare const colors: Readonly<Record<TokenName, TokenPair>>;

/** DEPRECATED shadcn-inheritance names kept alive for apps/widget. Do not use. */
export declare const legacyColors: Readonly<Record<LegacyTokenName, TokenPair>>;

export declare const shadows: Readonly<Record<ShadowName, TokenPair>>;

export declare const radiusScale: Readonly<Record<RadiusStep, string>>;

export declare const space: Readonly<Record<SpaceName, string>>;

export declare const fonts: Readonly<{ sans: string; mono: string }>;

export declare const type: Readonly<Record<TypeStep, TypeStepValues>>;

export declare const motion: Readonly<{
  durations: Readonly<Record<DurationName, string>>;
  easings: Readonly<Record<EasingName, string>>;
}>;

export declare const DEFAULT_RADIUS: RadiusValue;

export declare const RADIUS_VALUES: readonly RadiusValue[];

export declare const TENANT_OVERRIDABLE_COLOR_KEYS: readonly TenantOverridableColorKey[];
