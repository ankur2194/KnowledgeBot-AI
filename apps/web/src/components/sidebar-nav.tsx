'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';

import { isNavItemActive, NAV_SECTIONS } from '@/lib/nav';
import { cn } from '@/lib/utils';

/**
 * P2. Sections with `--text-caption` uppercase labels, then items: 2.25rem tall, `--radius-lg`,
 * a 16px icon and a `--text-base` label.
 *
 * REST IS TRANSPARENT · HOVER IS `--accent` · ACTIVE IS `--primary-soft` WITH
 * `--primary-soft-foreground`, and the icon inherits. Never a left border bar AND a fill together —
 * one signal per state.
 *
 * The active item is computed by `isNavItemActive`, shared with the breadcrumb. See its comment for
 * the `startsWith` bug it exists to prevent.
 */
export function SidebarNav({
  collapsed = false,
  onNavigate,
}: {
  /** The 4rem rail: icons plus tooltips, never a full hide. */
  readonly collapsed?: boolean;
  /** Closes the mobile drawer after a selection. */
  readonly onNavigate?: () => void;
}) {
  const pathname = usePathname();

  return (
    <nav aria-label="Main" className="flex flex-col gap-6">
      {NAV_SECTIONS.map((section) => (
        <div key={section.label} className="flex flex-col gap-2">
          {/* Hidden rather than removed on the rail: the grouping still exists for a screen reader,
              which navigates by structure and does not care how wide the sidebar is. */}
          <p
            className={cn(
              'px-2 text-caption text-muted-foreground uppercase',
              collapsed && 'sr-only',
            )}
          >
            {section.label}
          </p>
          <ul className="flex flex-col gap-0.5">
            {section.items.map((item) => {
              const active = isNavItemActive(pathname, item);
              const Glyph = item.glyph;

              return (
                <li key={item.href}>
                  <Link
                    href={item.href}
                    onClick={onNavigate}
                    // `aria-current="page"` is the non-visual half of the active state. Without it
                    // the only cue is a background colour, which is exactly the colour-alone
                    // failure kb-ui-accessibility forbids.
                    aria-current={active ? 'page' : undefined}
                    title={collapsed ? item.label : undefined}
                    className={cn(
                      'flex h-9 items-center gap-2 rounded-lg px-2 text-base',
                      'transition-colors duration-(--dur-1) ease-out',
                      'pointer-coarse:min-h-11',
                      active
                        ? 'bg-primary-soft text-primary-soft-foreground font-medium'
                        : 'text-foreground hover:bg-accent hover:text-accent-foreground active:bg-accent',
                      collapsed && 'justify-center px-0',
                    )}
                  >
                    <Glyph aria-hidden className="size-4 shrink-0" strokeWidth={1.75} />
                    <span className={cn(collapsed && 'sr-only')}>{item.label}</span>
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}
