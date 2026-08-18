'use client';

import { MonitorIcon, MoonIcon, SunIcon } from 'lucide-react';
import { useTheme } from 'next-themes';
import { useSyncExternalStore } from 'react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Dark mode is a FIRST-CLASS PALETTE, not an inversion — every token has a dark value chosen against
 * the dark canvas, and the elevation model changes with it. `next-themes` was already mounted in
 * `providers.tsx` and nothing ever called `setTheme`, so the app shipped a complete dark palette
 * with no way to reach it except the OS setting.
 *
 * THREE OPTIONS, NOT TWO. "System" has to be reachable: a two-state toggle silently pins the user to
 * an explicit choice the first time they touch it, and they can never get back to following the OS.
 *
 * The mounted guard is the standard `next-themes` one and it is about correctness, not flicker: the
 * resolved theme is unknowable on the server, so rendering the active state before mount is a
 * hydration mismatch. `prefers-color-scheme` is still honoured on first paint by the script
 * next-themes injects — a flash of the wrong theme is a real problem for light-sensitive users, not
 * a polish item.
 */
export function ThemeToggle() {
  const { theme, setTheme } = useTheme();
  // `false` on the server, `true` once hydrated — the canonical hydration-safe client check, and it
  // subscribes to nothing, so there is no effect and no cascading render. A `useState` + `useEffect`
  // pair does the same job by scheduling a second render on every mount of this component.
  const mounted = useSyncExternalStore(
    () => () => {},
    () => true,
    () => false,
  );

  const OPTIONS = [
    { value: 'light', label: 'Light', glyph: SunIcon },
    { value: 'dark', label: 'Dark', glyph: MoonIcon },
    { value: 'system', label: 'System', glyph: MonitorIcon },
  ] as const;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        {/* An icon-only control needs an accessible name, and the name says the ACTION rather than
            the icon (kb-ui-accessibility). */}
        <Button variant="ghost" size="icon-sm" aria-label="Change colour theme">
          <SunIcon aria-hidden className="dark:hidden" />
          <MoonIcon aria-hidden className="hidden dark:block" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {OPTIONS.map(({ value, label, glyph: Glyph }) => (
          <DropdownMenuItem
            key={value}
            onSelect={() => setTheme(value)}
            // The checked state is announced rather than only drawn — colour and a tick are both
            // visual channels, and a menu of three where one is "current" needs to say which.
            aria-current={mounted && theme === value ? 'true' : undefined}
          >
            <Glyph aria-hidden />
            {label}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
