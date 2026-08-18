import { expect, test } from '@playwright/test';

/**
 * `prefers-reduced-motion: reduce` IS HONOURED, AND HONOURING IT DOES NOT MEAN `animation: none`.
 *
 * The contract (`kb-motion-and-effects`): transforms are removed, looping stops, and anything that
 * CONVEYS PROGRESS KEEPS CONVEYING IT by another channel. A component that vanishes its own loading
 * indicator under reduced motion has made the app unusable for exactly the user the media query is
 * for — which is the opposite of the accommodation, and is why each assertion below checks that the
 * signal SURVIVES rather than that the animation stopped.
 *
 * playwright.config.ts sets `contextOptions: { reducedMotion: 'reduce' }` for the whole suite, so
 * this file also has to prove the no-preference side — otherwise "reduced motion is respected" and
 * "nothing ever animated" are indistinguishable.
 */

const PROBE = `
  <div id="probe-skeleton" class="kb-skeleton" style="width:200px;height:16px"></div>
  <span id="probe-caret" class="kb-caret"></span>
`;

test.describe('with reduce set', () => {
  test('the skeleton keeps its tinted block and drops only the sweep', async ({ page }) => {
    await page.goto('/c/e2e-probe-bot');
    await page.evaluate((html) => document.body.insertAdjacentHTML('beforeend', html), PROBE);

    const skeleton = await page.evaluate(() => {
      const element = document.querySelector('#probe-skeleton') as HTMLElement;
      return {
        background: getComputedStyle(element).backgroundColor,
        sweep: getComputedStyle(element, '::after').display,
      };
    });

    // The sweep is gone…
    expect(skeleton.sweep).toBe('none');
    // …and the indicator is not. A transparent block is a deleted indicator.
    expect(skeleton.background).not.toBe('rgba(0, 0, 0, 0)');
  });

  test('the streaming caret stops blinking and stays SOLID', async ({ page }) => {
    await page.goto('/c/e2e-probe-bot');
    await page.evaluate((html) => document.body.insertAdjacentHTML('beforeend', html), PROBE);

    const caret = await page.evaluate(() => {
      const element = document.querySelector('#probe-caret') as HTMLElement;
      const style = getComputedStyle(element);
      return { animation: style.animationName, opacity: Number.parseFloat(style.opacity) };
    });

    expect(caret.animation).toBe('none');
    // Not 0.15, which is where the blink keyframe ends. A caret that fades out removes the only
    // signal that the answer is still arriving.
    expect(caret.opacity).toBe(1);
  });

  test('nothing on the page loops', async ({ page }) => {
    await page.goto('/c/e2e-probe-bot');
    const looping = await page.evaluate(() =>
      [...document.querySelectorAll('*')]
        .filter((element) => {
          const style = getComputedStyle(element);
          return style.animationName !== 'none' && style.animationIterationCount === 'infinite';
        })
        .map((element) => `${element.tagName}.${element.className}`.slice(0, 60)),
    );
    expect(looping).toEqual([]);
  });
});

test.describe('without it — the control side', () => {
  // Same page, no preference. If these also came back inert, the assertions above would be proving
  // nothing at all.
  test.use({ contextOptions: { reducedMotion: 'no-preference' } });

  test('the skeleton sweeps and the caret blinks', async ({ page }) => {
    await page.goto('/c/e2e-probe-bot');
    await page.evaluate((html) => document.body.insertAdjacentHTML('beforeend', html), PROBE);

    const state = await page.evaluate(() => ({
      sweep: getComputedStyle(document.querySelector('#probe-skeleton') as Element, '::after').display,
      caret: getComputedStyle(document.querySelector('#probe-caret') as Element).animationName,
    }));

    expect(state.sweep).not.toBe('none');
    expect(state.caret).toBe('kb-caret');
  });
});
