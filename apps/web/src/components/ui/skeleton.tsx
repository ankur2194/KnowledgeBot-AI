import { cn } from '@/lib/utils';

/**
 * kb-motion-and-effects E7. The sweep is a `translateX` on a pseudo-element rather than an animated
 * `background-position`, which would repaint the gradient every frame; the recipe lives in
 * `globals.css` as the `kb-skeleton` utility.
 *
 * `aria-hidden` is not optional. The CONTAINER carries `aria-busy="true"`; the blocks inside are
 * decorative and announcing them makes a screen reader read a loading state as content. Under
 * reduced motion the sweep stops and the tinted block REMAINS — deleting the indicator is not the
 * accommodation, it is the opposite of it.
 *
 * A skeleton mirrors the loaded layout box for box. One that is a different height from the real
 * content makes the page jump on every load and gets debugged as a rendering bug.
 */
function Skeleton({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="skeleton" aria-hidden className={cn('kb-skeleton', className)} {...props} />;
}

export { Skeleton };
