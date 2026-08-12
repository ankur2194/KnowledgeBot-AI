'use client';

import { useLayoutEffect, useRef, type ReactNode } from 'react';

import { safeThemeDeclarations, type BotTheme } from '@/lib/theme';

/**
 * Per-bot branding for the ADMIN surface — many bots in one document, and the console chrome must
 * stay neutral. Hosted chat takes the other path: a real stylesheet response
 * (`/c/[publicBotId]/theme.css`), which `style-src 'self'` covers with no nonce and which stays
 * cacheable by publicBotId.
 *
 * Properties are written through the CSSOM, never as a `style=""` attribute and never through
 * dangerouslySetInnerHTML. MDN is explicit that CSP does not intercept this: "styles properties
 * that are set directly on the element's style property will not be blocked." A style attribute
 * WOULD be blocked, because nonces never apply to attributes — and the symptom is a brand colour
 * that applies in development and silently vanishes in production.
 */
export function BotThemeScope({ theme, children }: { theme: BotTheme; children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const element = ref.current;
    if (!element) return;

    // Clear first: a bot switched in place must not inherit the previous bot's palette.
    const existing = Array.from({ length: element.style.length }, (_, i) => element.style.item(i));
    for (const property of existing) {
      if (property.startsWith('--')) element.style.removeProperty(property);
    }
    // setProperty(), never setAttribute('style', …): no value can escape its own declaration into
    // a new rule, and the CSSOM path is not a style attribute.
    for (const [property, value] of safeThemeDeclarations(theme)) {
      element.style.setProperty(property, value);
    }
  }, [theme]);

  // Utilities read var(--primary) AT THE ELEMENT, so the override applies to this subtree only —
  // which is exactly why @theme must be `inline` in globals.css. Without `inline` the whole-page
  // case still works and this one silently does not, which is why the preview panel is the test
  // that matters.
  return (
    <div ref={ref} className="bg-background text-foreground">
      {children}
    </div>
  );
}
