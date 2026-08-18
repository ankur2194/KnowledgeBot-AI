'use client';

import { PanelLeftIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { SidebarNav } from '@/components/sidebar-nav';
import { SkipLink } from '@/components/skip-link';
import { ThemeToggle } from '@/components/theme-toggle';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { SIDEBAR_COOKIE } from '@/lib/sidebar';
import { cn } from '@/lib/utils';

/**
 * P1. The application shell: a `--card` panel at `--radius-3xl` and `--shadow-xl`, inset from the
 * page by `--space-3`, holding a sidebar and a `--canvas` main column.
 *
 * WHY THE INSET GUTTER EXISTS: it is what makes the canvas read as a page rather than as a viewport.
 * Remove it and the shell's radius has nothing to sit against.
 *
 * THIS IS THE ONLY `--shadow-xl` ON SCREEN. A dialog is the other one, and never at the same time —
 * more than one `-xl` and the elevation model stops meaning anything.
 *
 * THE SIDEBAR AND THE MAIN COLUMN SCROLL INDEPENDENTLY. A single scroll container takes the nav off
 * screen on a long page, which is the thing a sidebar exists to prevent.
 *
 * THREE BREAKPOINTS, and there is no fourth:
 *   < 768px      drawer over the content with a scrim, trigger in the header
 *   768–1279px   rail — icons plus tooltips at 4rem, NEVER a full hide
 *   >= 1280px    sidebar at 16rem, collapsible to the rail by preference
 *
 * `defaultCollapsed` is read from a cookie ON THE SERVER by the layout and passed in, so the first
 * paint is already correct. Reading it in an effect instead makes the sidebar visibly snap to its
 * remembered width after hydration on every navigation.
 */
export function AppShell({
  defaultCollapsed = false,
  brand,
  primaryAction,
  sidebarFooter,
  topBar,
  children,
}: {
  readonly defaultCollapsed?: boolean;
  readonly brand: ReactNode;
  /** The one `--primary` action, full width under the brand (P2). */
  readonly primaryAction?: ReactNode;
  /** Org badge, usage meter, user card. */
  readonly sidebarFooter?: ReactNode;
  readonly topBar?: ReactNode;
  readonly children: ReactNode;
}) {
  const [collapsed, setCollapsed] = useState(defaultCollapsed);
  const [drawerOpen, setDrawerOpen] = useState(false);

  const toggle = () => {
    const next = !collapsed;
    setCollapsed(next);
    // Written here rather than through a server action: it is a display preference, it is not
    // org-scoped, and a round trip to persist a sidebar width would be visible.
    document.cookie = `${SIDEBAR_COOKIE}=${next ? '1' : '0'}; path=/; max-age=31536000; samesite=lax`;
  };

  return (
    <div className="flex min-h-dvh flex-col bg-canvas p-3">
      {/* First in the DOM, before the shell — a skip link that comes after the nav skips nothing. */}
      <SkipLink />

      <div className="flex min-h-0 flex-1 overflow-hidden rounded-3xl bg-card shadow-xl">
        <aside
          data-collapsed={collapsed ? '' : undefined}
          className={cn(
            'hidden shrink-0 flex-col gap-4 overflow-y-auto p-3 md:flex',
            // Rail at tablet, full sidebar at desktop unless the user collapsed it.
            'md:w-16',
            collapsed ? 'xl:w-16' : 'xl:w-64',
          )}
        >
          <div className={cn('flex items-center gap-2 px-2', collapsed && 'xl:justify-center xl:px-0')}>
            {brand}
          </div>
          {primaryAction ? (
            <div className={cn('px-1', collapsed && 'xl:hidden')}>{primaryAction}</div>
          ) : null}

          {/* The rail is the md-to-xl case AND the collapsed-at-xl case; the labels are `sr-only`
              in both, so the nav is unchanged for a screen reader either way. */}
          <div className="hidden xl:block">
            <SidebarNav collapsed={collapsed} />
          </div>
          <div className="xl:hidden">
            <SidebarNav collapsed />
          </div>

          {sidebarFooter ? (
            <div className={cn('mt-auto flex flex-col gap-2', collapsed && 'xl:hidden')}>
              {sidebarFooter}
            </div>
          ) : null}
        </aside>

        <div className="flex min-w-0 flex-1 flex-col">
          {/* Sticky within main, `--card` with a `--border` bottom edge — not a floating glass bar.
              E6's frost needs three conditions to hold and a long scrolling list fails the second. */}
          <header className="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-2 border-b border-border bg-card px-gutter-sm">
            <Sheet open={drawerOpen} onOpenChange={setDrawerOpen}>
              <SheetTrigger asChild>
                <Button variant="ghost" size="icon-sm" className="md:hidden" aria-label="Open navigation">
                  <PanelLeftIcon aria-hidden />
                </Button>
              </SheetTrigger>
              <SheetContent side="left" className="w-72 p-3">
                <SheetTitle className="sr-only">Navigation</SheetTitle>
                <div className="flex items-center gap-2 px-2 pb-4">{brand}</div>
                {primaryAction ? <div className="px-1 pb-4">{primaryAction}</div> : null}
                <SidebarNav onNavigate={() => setDrawerOpen(false)} />
              </SheetContent>
            </Sheet>

            <Button
              variant="ghost"
              size="icon-sm"
              onClick={toggle}
              className="hidden xl:inline-flex"
              aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
              aria-expanded={!collapsed}
            >
              <PanelLeftIcon aria-hidden />
            </Button>

            <div className="flex min-w-0 flex-1 items-center gap-2">{topBar}</div>
            <ThemeToggle />
          </header>

          {/* The recessed plane. Content sits on cards ON this, never directly on it. */}
          <main id="main" className="min-h-0 flex-1 overflow-y-auto bg-canvas px-gutter-sm py-6 md:px-gutter-md xl:px-gutter-lg">
            {children}
          </main>
        </div>
      </div>
    </div>
  );
}
