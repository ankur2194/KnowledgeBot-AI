import { PlusIcon } from 'lucide-react';
import { render } from 'vitest-browser-react';
import { describe, expect, it, vi } from 'vitest';

import {
  EmptyState,
  FilteredEmptyState,
  PageOutOfRangeState,
  RefetchIndicator,
  TableSkeleton,
} from '@/components/states';
import { Button } from '@/components/ui/button';

/**
 * The state components a data surface ships WITH its success state.
 *
 * `server-data-table.test.tsx` asserts which state is CHOSEN; this file asserts what each one is,
 * because the two failures are different: choosing the filtered empty for a first-run collection is a
 * dispatch bug, and a filtered empty that offers "Add bot" is a content bug that survives correct
 * dispatch.
 */

describe('the empty states are two components with two different jobs', () => {
  it('first-run empty is an onboarding moment: a glyph and the primary action', async () => {
    const screen = await render(
      <EmptyState
        glyph={PlusIcon}
        title="No bots yet"
        body="A bot answers from the sources you give it."
        action={<Button>Add bot</Button>}
      />,
    );

    await expect.element(screen.getByText('No bots yet')).toBeInTheDocument();
    await expect.element(screen.getByRole('button', { name: 'Add bot' })).toBeInTheDocument();
    // The glyph is decorative — the tone behind it is a wash, not a signal — so it is hidden from
    // assistive technology and the heading carries the meaning.
    expect(document.querySelectorAll('[aria-hidden="true"] svg').length).toBeGreaterThan(0);
  });

  it('filtered empty is a correction moment: no illustration, and Clear filters', async () => {
    const onClear = vi.fn();
    const screen = await render(
      <FilteredEmptyState describeFilter={'No bots match "invoice".'} onClear={onClear} />,
    );

    // Restating the filter is what lets a user — and support, reading a screenshot — tell a
    // too-narrow filter from a broken screen.
    await expect.element(screen.getByText('No bots match "invoice".')).toBeInTheDocument();
    await screen.getByRole('button', { name: 'Clear filters' }).click();
    expect(onClear).toHaveBeenCalledOnce();
    expect(document.querySelectorAll('svg')).toHaveLength(0);
  });

  it('is a real heading in both, so a screen reader can navigate to it', async () => {
    const screen = await render(<FilteredEmptyState describeFilter="Nothing matched." />);

    await expect.element(screen.getByRole('heading', { name: 'No matches' })).toBeInTheDocument();
  });
});

describe('the out-of-range page state', () => {
  it('names the problem it actually has and offers the only action that fixes it', async () => {
    const onFirstPage = vi.fn();
    const screen = await render(<PageOutOfRangeState onFirstPage={onFirstPage} />);

    await expect.element(screen.getByText('That page is empty')).toBeInTheDocument();
    // Not "Clear filters": clearing a filter would usually make the page valid again by accident,
    // which teaches the wrong lesson about a URL that is simply out of date.
    expect(screen.getByRole('button', { name: 'Clear filters' }).elements()).toHaveLength(0);

    await screen.getByRole('button', { name: 'Go to first page' }).click();
    expect(onFirstPage).toHaveBeenCalledOnce();
  });
});

describe('the table skeleton mirrors the loaded layout rather than three text lines', () => {
  it('renders a header strip plus one block row per row, at the loaded row height', async () => {
    await render(<TableSkeleton rows={5} columns={4} />);

    // 4 header blocks + 5 rows x 4 = 24. A skeleton that is a different SHAPE from the table makes
    // the page reflow when data arrives, and that reads as a rendering bug.
    expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(24);
    expect(document.querySelectorAll('.h-13')).toHaveLength(5);
  });

  it('announces nothing: aria-busy on the container, aria-hidden on every block', async () => {
    await render(<TableSkeleton rows={2} columns={2} />);

    expect(document.querySelectorAll('[aria-busy="true"]')).toHaveLength(1);
    expect(document.querySelectorAll('[data-slot="skeleton"]:not([aria-hidden])')).toHaveLength(0);
  });

  it('varies the block widths, because identical bars read as a broken table', async () => {
    await render(<TableSkeleton rows={3} columns={3} />);

    const widths = new Set(
      [...document.querySelectorAll('[data-slot="skeleton"]')].map((block) => block.className),
    );
    expect(widths.size).toBeGreaterThan(1);
  });
});

describe('the refetch indicator', () => {
  it('is not a live region, and is hidden from assistive technology', async () => {
    await render(<RefetchIndicator />);

    // A table polling every five seconds while a source ingests would otherwise announce itself every
    // five seconds, which is a reason to stop using the product.
    expect(document.querySelectorAll('[aria-live]')).toHaveLength(0);
    expect(document.querySelectorAll('[role="status"]')).toHaveLength(0);
    expect(document.querySelectorAll('[aria-hidden="true"]')).toHaveLength(1);
  });

  it('reuses the skeleton shimmer, so reduced motion stops the sweep and keeps the bar', async () => {
    await render(<RefetchIndicator />);

    // `kb-skeleton` carries its own prefers-reduced-motion arm: the ::after sweep is dropped and the
    // tinted block remains. Deleting the indicator is not the accommodation.
    expect(document.querySelectorAll('.kb-skeleton')).toHaveLength(1);
  });
});

/**
 * `StateShell`'s `body` IS `ReactNode`, so its container may not be a paragraph.
 *
 * The same defect `DataTableCard` carried (see `server-data-table.test.tsx`, "the card title accepts
 * block content"): a `<p>` admits only PHRASING content, `ReactNode` promises nothing of the kind, and
 * the constraint appeared in no type and no comment. Every caller today passes a string or a
 * phrasing-only fragment — `ForbiddenState` is the closest, and it is still just text — so this was
 * latent rather than broken. It is asserted here so the first caller that passes a block does not
 * rediscover it as a hydration error in a browser.
 *
 * `EmptyState` is the vehicle because it delegates straight to `StateShell`, as do
 * `FilteredEmptyState`, `ForbiddenState` and `PageOutOfRangeState` — one container, four callers.
 */
describe('the state body accepts block content', () => {
  it('does not nest a block-level body inside a paragraph', async () => {
    await render(
      <EmptyState
        glyph={PlusIcon}
        title="No bots yet"
        body={
          <div data-testid="block-body">
            <span>A bot answers from the sources you give it.</span>
            <span>Add one to begin.</span>
          </div>
        }
        action={<Button>Add bot</Button>}
      />,
    );

    const body = document.querySelector('[data-testid="block-body"]');
    // Present at all: a shell that dropped the body would satisfy the nesting check vacuously.
    expect(body).not.toBeNull();
    expect(body?.closest('p')).toBeNull();
  });
});
