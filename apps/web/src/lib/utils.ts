import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * The shadcn class merger. `tailwind-merge` carries its own hardcoded map of Tailwind's conflict
 * groups and ships one release per Tailwind minor to extend it — so `tailwindcss` and
 * `tailwind-merge` are bumped in the SAME PR. A utility family the installed version has never
 * heard of is not a conflict group, so both classes survive and stylesheet order, not prop order,
 * decides which wins.
 *
 * Not shared with apps/widget: tailwind-merge ships Tailwind's entire class-group map into the
 * bundle to resolve duplicates at runtime, a measurable slice of a 30 kB brotli budget for a
 * problem eight hand-written components do not have.
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}
