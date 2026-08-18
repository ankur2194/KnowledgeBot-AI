'use client';

import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';

import { safeThemeDeclarations, type BotTheme } from '@/lib/theme';

/**
 * Per-bot branding for the ADMIN surface — many bots in one document, and the console chrome must
 * stay neutral. Hosted chat takes the other path: a real stylesheet response
 * (`/c/[publicBotId]/theme.css`), which `style-src 'self'` covers with no nonce and which stays
 * cacheable by publicBotId. The two are not interchangeable.
 *
 * Properties are written through the CSSOM, never as a `style=""` attribute and never through
 * dangerouslySetInnerHTML. MDN is explicit that CSP does not intercept this: "styles properties
 * that are set directly on the element's style property will not be blocked." A style attribute
 * WOULD be blocked, because nonces never apply to attributes — and the symptom is a brand colour
 * that applies in development and silently vanishes in production.
 */
export function BotThemeScope({ theme, children }: { theme: BotTheme; children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);
  const mode = useColorMode();

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
    for (const [property, value] of safeThemeDeclarations(theme, mode)) {
      element.style.setProperty(property, value);
    }
  }, [theme, mode]);

  // NO COLOUR CLASSES ON THIS ELEMENT. A theme scope is not a plane — the cards, tables and panels
  // inside it paint their own, and a wrapper that sets a background here would put a second --card
  // directly behind them, which is the flat-card bug in kb-design-language's Gotchas. All this
  // element does is hold the custom properties.
  //
  // Utilities read var(--primary) AT THE ELEMENT, so the override applies to this subtree only —
  // which is exactly why @theme must be `inline`. Without `inline` the whole-page case still works
  // and this one silently does not, which is why the preview panel is the test that matters.
  return <div ref={ref}>{children}</div>;
}

/**
 * The resolved colour mode, read off the DOM rather than from `next-themes`.
 *
 * Deliberately provider-independent: this component is mounted wherever a bot is previewed, and
 * coupling it to a specific theme library would make it unusable in a surface that does not mount
 * one (hosted chat has no ThemeProvider at all). The `.dark` class is the contract every path
 * already writes, so observing it works regardless of who sets it.
 *
 * Starts at 'light' and corrects in an effect, because the server has no way to know: the class is
 * written by a script before React runs, and reading it during render would be a hydration
 * mismatch.
 */
function useColorMode(): 'light' | 'dark' {
  const [mode, setMode] = useState<'light' | 'dark'>('light');

  useEffect(() => {
    const root = document.documentElement;
    const read = () => setMode(root.classList.contains('dark') ? 'dark' : 'light');
    read();

    const observer = new MutationObserver(read);
    observer.observe(root, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
  }, []);

  return mode;
}
