import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import { WIDGET_BASE, captureShadowRoots } from './_shadow.js';

/**
 * A CUSTOMER CSP THAT ALLOWS OUR SCRIPT AND WITHHOLDS `frame-src`.
 *
 * The frame then silently never loads: no `error` event on the element, nothing in our logs. The
 * loader watches two signals — `securitypolicyviolation` on the host document, and a 10 s load
 * timeout — and swaps in an anchor to hosted chat on `chat.<domain>`, which needs no frame.
 *
 * The three directives a customer actually needs are `script-src https://<widget-domain>`,
 * `frame-src https://<widget-domain>` and `connect-src https://api.<domain>` — and the third names
 * a DIFFERENT REGISTRABLE DOMAIN from the first two. A reviewer who tidies them into one is
 * reintroducing the defect the domain split exists to prevent. `?csp=strict` serves exactly that
 * set MINUS `frame-src`, which is the single directive under test.
 *
 * It used to serve a blanket `default-src 'self'`, and this whole file was inert: `script-src` falls
 * back to `default-src`, so the policy blocked OUR OWN LOADER, and degrade() lives inside it. Both
 * specs asserted a subject that never executed. The describe name said `default-src 'self'` for the
 * same reason, and is corrected here — a spec named after the wrong stimulus is how the next person
 * re-introduces it.
 */
/** Count the degraded anchors across every shadow root the page created. */
const anchorCount = (page: Page): Promise<number> =>
  page.evaluate(() => {
    const roots = ((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ??
      []) as ShadowRoot[];
    return roots.reduce((n, r) => n + r.querySelectorAll('a.kb-degraded').length, 0);
  });

test.describe('host CSP: script allowed, frame-src withheld', () => {
  test.beforeEach(async ({ page }) => {
    await captureShadowRoots(page);
    /**
     * THE POSITIVE CONTROL FOR THIS FILE. Every other spec proves the widget booted; here it must
     * NOT boot, so the observable that proves the stimulus is real is the browser's own CSP
     * violation. Without it, "exactly one anchor, exactly one error event" is equally satisfied by a
     * page where the frame was never even requested — which is precisely the shipping bug
     * `loading = "lazy"` once caused, diagnosed as a customer CSP problem that did not exist.
     */
    await page.addInitScript(() => {
      const violations: string[] = [];
      (window as unknown as Record<string, unknown>)['__kbViolations'] = violations;
      document.addEventListener('securitypolicyviolation', (event) => {
        violations.push(`${event.effectiveDirective} ${event.blockedURI}`);
      });
    });
  });

  test('degrades to a hosted-chat anchor within 10 s, exactly once — across BOTH signals', async ({
    page,
  }) => {
    const navigatedAt = Date.now();
    await page.goto('/?csp=strict');

    await expect.poll(() => anchorCount(page), { timeout: 12_000 }).toBe(1);

    // THE STIMULUS ACTUALLY HAPPENED: the browser refused our frame under the customer's policy.
    // A degrade that fired for any other reason would not produce this.
    const violations = await page.evaluate(
      () => (window as unknown as Record<string, unknown>)['__kbViolations'] as string[],
    );
    expect(violations.join(' ')).toContain('frame-src');
    expect(violations.join(' ')).toContain(WIDGET_BASE);
    // And the loader itself ran — it must, or degrade() (which lives inside it) could not have.
    // The observable is the SHADOW ROOT, not the launcher: `degrade()` calls `replaceChildren`, so
    // the launcher is gone by design once the fallback link is in place.
    const roots = await page.evaluate(
      () =>
        (((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ?? []) as ShadowRoot[])
          .length,
    );
    expect(roots).toBe(1);

    const anchor = await page.evaluate(() => {
      const roots = ((window as unknown as Record<string, unknown>)['__kbShadowRoots'] ??
        []) as ShadowRoot[];
      const link = roots
        .map((r) => r.querySelector('a.kb-degraded'))
        .find((el): el is HTMLAnchorElement => el !== null);
      return link === undefined
        ? null
        : { href: link.href, rel: link.rel, target: link.target, text: link.textContent };
    });

    expect(anchor).not.toBeNull();
    // Hosted chat, derived from the baked API origin — never from iframe.src or a data-* attribute.
    expect(anchor?.href).toContain('/c/pub_01J8FIXTUREBOT0000000000');
    expect(anchor?.href).not.toContain('/embed');
    // noopener stops reverse tabnabbing; noreferrer keeps the customer's page URL out of our logs.
    expect(anchor?.rel).toBe('noopener noreferrer');
    expect(anchor?.target).toBe('_blank');
    // It has to be readable on a page whose CSP just blocked our frame.
    expect(anchor?.text?.trim()).not.toBe('');

    /**
     * BOTH SIGNALS MUST FIRE, AND THE WAIT HAS TO BE LONG ENOUGH FOR THE SECOND ONE.
     *
     * The loader watches two independent signals: `securitypolicyviolation`, which fires within
     * milliseconds, and a 10 s load timeout, which is the only detector of a block that raises no
     * violation at all. This spec claimed to prove they do not produce two links — and then waited
     * 2 s from an assertion that resolved at ~200 ms, so it returned at ~2.2 s and the second signal
     * had not happened yet. It was asserting idempotence across one signal and calling it two.
     *
     * Ten seconds of wall clock is genuinely unavoidable here: the timer under test is ten seconds
     * long, and shortening it would mean putting a test seam into the one mechanism whose whole
     * purpose is to notice a frame that never loaded. What makes the wait honest rather than
     * hopeful is everything above it — the violation was observed, the loader ran, one anchor
     * already exists — so a page where nothing happened fails before ever reaching this line.
     */
    await expect
      .poll(() => Date.now() - navigatedAt, { timeout: 20_000, intervals: [500] })
      .toBeGreaterThan(11_000);

    expect(await anchorCount(page)).toBe(1);
    // One anchor AND one event: degrade() is idempotent on both surfaces, not just in the DOM.
    const errors = await page.evaluate(() => {
      const events = ((window as unknown as Record<string, unknown>)['__kbEvents'] ?? []) as Array<
        [string, unknown]
      >;
      return events.filter(([name]) => name === 'error').length;
    });
    expect(errors).toBe(1);
  });

  test('emits exactly one `error` SDK event, carrying metadata and no internal detail', async ({
    page,
  }) => {
    await page.goto('/?csp=strict');

    await expect
      .poll(
        () =>
          page.evaluate(() => {
            const events = ((window as unknown as Record<string, unknown>)['__kbEvents'] ??
              []) as Array<[string, unknown]>;
            return events.filter(([name]) => name === 'error').length;
          }),
        { timeout: 12_000 },
      )
      .toBe(1);

    // Same positive control: the event under test must have been produced by a real refusal.
    const violations = await page.evaluate(
      () => (window as unknown as Record<string, unknown>)['__kbViolations'] as string[],
    );
    expect(violations.join(' ')).toContain('frame-src');

    const payload = await page.evaluate(() => {
      const events = ((window as unknown as Record<string, unknown>)['__kbEvents'] ?? []) as Array<
        [string, unknown]
      >;
      return events.find(([name]) => name === 'error')?.[1] ?? null;
    });

    // EXACT, not partial. This payload is delivered to `onEvent` on a customer's own page and is a
    // commitment to code we will never see, so an added field is as much a contract change as a
    // removed one.
    //
    //   `authorization` — the page is not permitted to frame the widget: either the customer's CSP
    //   has no `frame-src` for <widget-domain>, or their origin is not on the bot's allow-list and
    //   Laravel served `frame-ancestors 'none'`. Not `internal_dependency`: nothing downstream of us
    //   failed, we have no defect, and that row PAGES.
    //
    //   `retryable: false` — stated, never inferred. After ADR-029 (finding O1) an `error_class`
    //   alone no longer answers the retry question, because `internal_dependency` renders
    //   503/retryable for `downstream` and 500/not-retryable for `self` and the axis is not on the
    //   wire. Here the answer is unambiguous: no number of attempts changes a CSP.
    expect(payload).toEqual({
      error_class: 'authorization',
      retryable: false,
      reason: 'frame_blocked',
    });
    // Nothing that names our internals: no hostname, no stack, no upstream provider text, and no
    // operator-facing `message`.
    expect(JSON.stringify(payload)).not.toMatch(/http|stack|Error:/i);
  });
});
