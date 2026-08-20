import { describe, expect, it } from 'vitest';

import { toUploadProgress } from '@/lib/api/upload';

/**
 * `ProgressEvent` → the shape a file row renders, in the `unit` project (node, no DOM).
 *
 * ── WHY THIS MAPPING GETS ITS OWN SPEC ──────────────────────────────────────────────────────────
 * It is the only part of `lib/api/upload.ts` that the component harness cannot reach: MSW's service
 * worker answers the request inside the page, so `xhr.upload.progress` fires zero times and every
 * branch below is dead under that transport (measured, and asserted as a limitation in
 * tests/components/upload-transport.test.tsx). Left private it would be covered by nothing at all.
 *
 * The parameter is a STRUCTURAL subset of `ProgressEvent`, not the DOM type — which is what lets a
 * node spec drive it. A real `ProgressEvent` satisfies it, so the production call site casts
 * nothing.
 */

describe('toUploadProgress', () => {
  it('reports a percentage when the browser can compute a total', () => {
    expect(toUploadProgress({ lengthComputable: true, loaded: 512, total: 1024 })).toEqual({
      loaded: 512,
      total: 1024,
      percent: 50,
    });
  });

  it('reports `total: null` and `percent: null` when it cannot', () => {
    // A REAL STATE, not an edge case: a body the engine streams without a known length reports
    // `lengthComputable: false`. It has to reach the UI as null, because `<Progress value={null}>`
    // renders 0% (its shadcn-inherited `value || 0`) rather than an indeterminate bar — so a row
    // that treats null as zero shows a determinate bar parked at 0% for the whole upload.
    expect(toUploadProgress({ lengthComputable: false, loaded: 900, total: 0 })).toEqual({
      loaded: 900,
      total: null,
      percent: null,
    });
  });

  it('treats a zero total as indeterminate rather than dividing by it', () => {
    // `loaded / 0` is `NaN` (or Infinity), `NaN` fails every comparison silently, and
    // `<Progress value={NaN}>` renders `translateX(-NaN%)`, which paints nothing at all with no
    // error anywhere. That is the failure this branch exists to prevent, so it is asserted even
    // though a `lengthComputable: true, total: 0` event is unusual.
    const progress = toUploadProgress({ lengthComputable: true, loaded: 0, total: 0 });
    expect(progress.total).toBeNull();
    expect(progress.percent).toBeNull();
    expect(Number.isNaN(progress.percent as unknown as number)).toBe(false);
  });

  it('FLOORS rather than rounds, so 100 means the bytes are gone', () => {
    // `Math.round` reaches 100 at 99.5%, and a bar that says 100% for the last two seconds of a
    // large upload is the "determinate bar parked at 100%" P13 warns about wearing a different
    // number. The row's `finishing` phase is driven off `percent === 100`, so the two are the same
    // decision.
    expect(toUploadProgress({ lengthComputable: true, loaded: 999, total: 1000 }).percent).toBe(99);
    expect(toUploadProgress({ lengthComputable: true, loaded: 1000, total: 1000 }).percent).toBe(100);
  });

  it('clamps above 100, because `loaded` can exceed `total` on a retried write', () => {
    expect(toUploadProgress({ lengthComputable: true, loaded: 1200, total: 1000 }).percent).toBe(100);
  });

  it('starts at 0 rather than at null, so the first event is already a determinate bar', () => {
    expect(toUploadProgress({ lengthComputable: true, loaded: 0, total: 1000 }).percent).toBe(0);
  });
});
