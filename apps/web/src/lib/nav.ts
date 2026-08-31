import {
  BotIcon,
  GaugeIcon,
  LayoutDashboardIcon,
  LibraryIcon,
  MessagesSquareIcon,
  ScrollTextIcon,
  SettingsIcon,
  type LucideIcon,
} from 'lucide-react';

export interface NavItem {
  readonly href: string;
  readonly label: string;
  readonly glyph: LucideIcon;
  /**
   * Whether descendants of `href` also count as active. True for exactly the items that are
   * genuinely a parent section, and it is opt-in rather than assumed.
   */
  readonly matchesDescendants?: boolean;
}

export interface NavSection {
  /** `--text-caption` uppercase label. Three sections is comfortable; six means the information
   *  architecture is wrong, not that the sidebar needs scrolling. */
  readonly label: string;
  readonly items: readonly NavItem[];
}

/**
 * ── EVERY ITEM IS OFFERED TO EVERY ROLE, AND THAT IS DELIBERATE ─────────────────────────────────
 *
 * Four of these routes are gated server-side by a permission not every role holds —
 * `analytics.view` on `/` and `/quotas`, `conversations.view` on `/conversations`, `audit.view` on
 * `/audit-logs`, `sources.view` on `/sources` — and the sidebar does not hide any of them.
 *
 * The alternative is worse in both directions. Hiding an item means the console's navigation becomes
 * a SECOND authorization model, computed from a cached session's role, which is a value that can be
 * stale for exactly as long as the session is: a member promoted a minute ago would find the item
 * missing and conclude the feature does not exist. And a role that changed the other way would still
 * see the item, because the same stale value hid nothing — so the "protection" is absent precisely
 * when it would have mattered.
 *
 * What each screen does instead is name the role in its FORBIDDEN state, and only for the viewer
 * whose role genuinely cannot hold the permission. That turns a dead end into an action ("ask an
 * owner"), which a missing nav item cannot do.
 */
export const NAV_SECTIONS: readonly NavSection[] = [
  {
    label: 'Workspace',
    items: [
      { href: '/', label: 'Overview', glyph: LayoutDashboardIcon },
      { href: '/bots', label: 'Bots', glyph: BotIcon, matchesDescendants: true },
      { href: '/sources', label: 'Sources', glyph: LibraryIcon, matchesDescendants: true },
      // `matchesDescendants` because `/conversations/{id}` is genuinely a child of this section —
      // and the boundary is `href + '/'`, so it does not claim `/conversationsomething`.
      {
        href: '/conversations',
        label: 'Conversations',
        glyph: MessagesSquareIcon,
        matchesDescendants: true,
      },
    ],
  },
  {
    label: 'Organization',
    items: [
      { href: '/settings', label: 'Settings', glyph: SettingsIcon, matchesDescendants: true },
      // NO `matchesDescendants` on either: both are single routes with no children, and opting in
      // where there are no descendants is how `/quotas` comes to claim `/quotas-archive` the day
      // somebody adds one.
      { href: '/quotas', label: 'Quotas', glyph: GaugeIcon },
      { href: '/audit-logs', label: 'Audit log', glyph: ScrollTextIcon },
    ],
  },
];

/**
 * THE ONE FUNCTION. The sidebar and the breadcrumb both call it, so they cannot disagree.
 *
 * The bug it exists to prevent is `pathname.startsWith(href)`, which highlights TWO rows: `/settings`
 * matches `/settings/members`, and — worse — `/` matches everything, so the Overview item is
 * permanently active on every page in the app. Matching is exact by default; a section that really
 * does own its descendants opts in, and even then the boundary is `href + '/'` so `/bots` does not
 * claim `/botsomething`.
 */
export function isNavItemActive(pathname: string, item: NavItem): boolean {
  if (pathname === item.href) return true;
  if (!item.matchesDescendants) return false;
  return pathname.startsWith(`${item.href}/`);
}
