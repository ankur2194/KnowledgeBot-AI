import { render } from 'vitest-browser-react';
import { describe, expect, it } from 'vitest';

import { PageHeader } from '@/components/page-header';

/**
 * The header every admin surface opens with.
 *
 * ── WHY THIS FILE EXISTS AT ALL ──────────────────────────────────────────────────────────────────
 * It did not, until `description` was found to be `ReactNode` rendered inside a `<p>` — the third
 * instance of one defect, after `DataTableCard`'s title and `StateShell`'s body. A `<p>` admits only
 * PHRASING content while `ReactNode` promises nothing, so the container imposed a rule that lived in
 * no type and no comment, and the browser enforces it by REPARENTING during hydration: the markup
 * React rendered and the markup the parser kept disagree.
 *
 * Every caller today passes a string, so this was latent. The assertion is on the CONTAINER so it
 * stays closed for the caller who eventually passes a block.
 */
describe('the header description accepts block content', () => {
  it('does not nest a block-level description inside a paragraph', async () => {
    await render(
      <PageHeader
        title="Sources"
        description={
          <div data-testid="block-description">
            <span>Everything this organization has indexed.</span>
            <span>Two of them are still processing.</span>
          </div>
        }
      />,
    );

    const description = document.querySelector('[data-testid="block-description"]');
    // Present at all: a header that dropped the description would pass the nesting check vacuously.
    expect(description).not.toBeNull();
    expect(description?.closest('p')).toBeNull();
  });

  it('renders the title as the page h1, which is what aria-labelledby points at', async () => {
    const screen = await render(<PageHeader title="Sources" titleId="sources-heading" />);

    const heading = screen.getByRole('heading', { level: 1, name: 'Sources' });
    await expect.element(heading).toBeInTheDocument();
    expect(document.querySelector('h1')?.id).toBe('sources-heading');
  });
});
