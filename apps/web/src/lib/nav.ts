import { BotIcon, LayoutDashboardIcon, LibraryIcon, SettingsIcon, type LucideIcon } from 'lucide-react';

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

export const NAV_SECTIONS: readonly NavSection[] = [
  {
    label: 'Workspace',
    items: [
      { href: '/', label: 'Overview', glyph: LayoutDashboardIcon },
      { href: '/bots', label: 'Bots', glyph: BotIcon, matchesDescendants: true },
      { href: '/sources', label: 'Sources', glyph: LibraryIcon, matchesDescendants: true },
    ],
  },
  {
    label: 'Organization',
    items: [{ href: '/settings', label: 'Settings', glyph: SettingsIcon, matchesDescendants: true }],
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
