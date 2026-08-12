/* GENERATED FILE — do not edit.
 * Source: packages/design-tokens/src/tokens.json
 * Regenerate: pnpm tokens:build
 * Committed on purpose: CI runs the build and then `git diff --exit-code`.
 */

export type TokenName = 'background' | 'foreground' | 'card' | 'card-foreground' | 'popover' | 'popover-foreground' | 'primary' | 'primary-foreground' | 'secondary' | 'secondary-foreground' | 'muted' | 'muted-foreground' | 'accent' | 'accent-foreground' | 'destructive' | 'destructive-foreground' | 'border' | 'input' | 'ring';

export type TenantOverridableColorKey = 'primary' | 'primary-foreground' | 'accent' | 'accent-foreground';

export type RadiusValue = '0rem' | '0.25rem' | '0.5rem' | '0.625rem' | '0.75rem' | '1rem';

export interface TokenPair {
  readonly light: string;
  readonly dark: string;
}

export declare const colors: Readonly<Record<TokenName, TokenPair>>;

export declare const DEFAULT_RADIUS: RadiusValue;

export declare const RADIUS_VALUES: readonly RadiusValue[];

export declare const TENANT_OVERRIDABLE_COLOR_KEYS: readonly TenantOverridableColorKey[];
