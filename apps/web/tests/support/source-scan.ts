import { readdir } from 'node:fs/promises';
import { join } from 'node:path';

/**
 * SHARED PRIMITIVES FOR THE SOURCE-TEXT INVARIANT SPECS — `form-method.test.ts` and
 * `body-hydration.test.ts`.
 *
 * Both encode the same shape of rule: an attribute that MUST be present on every occurrence of a JSX
 * element anywhere under `src/`, checked as a closure over the tree so a file added next quarter is
 * covered by an existing spec. Both need the same two primitives, and `tsxFiles` was already
 * byte-identical in two places before this module existed — the duplication that ends with the two
 * copies disagreeing.
 *
 * NOT under `tests/unit/`, deliberately: the `unit` project's `include` is `tests/unit/**\/*.test.ts`,
 * so a helper there is merely uncollected, whereas here it is unambiguously not a spec.
 */

/** Every `.tsx` under `dir`, recursively. */
export async function tsxFiles(dir: string): Promise<readonly string[]> {
  const entries = await readdir(dir, { withFileTypes: true });
  const found: string[] = [];
  for (const entry of entries) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) found.push(...(await tsxFiles(path)));
    else if (entry.name.endsWith('.tsx')) found.push(path);
  }
  return found;
}

/**
 * Source with comments removed, so that a tag NAME MENTIONED IN PROSE is not mistaken for the element.
 *
 * THIS IS NOT TIDINESS — it is a false FAILURE this repo produced immediately. Explaining why `<body>`
 * needs `suppressHydrationWarning` requires writing `<body>` in the comment above it, and a scan for
 * `/<body[^>]*>/` then matches the prose: three files failed while all three were correct. The mirror
 * hazard is worse and is what makes this shared rather than local — a comment reading `<form
 * method="post">` would have made `form-method.test.ts` PASS for a file whose real form lacked it.
 *
 * Block comments cover both spellings that matter here: JSX comments are `{/* … *\/}` and docblocks are
 * `/** … *\/`. Line comments are stripped only when `//` is not preceded by `:`, which keeps a `https://`
 * inside a string literal from swallowing the rest of its line — the one case where over-stripping
 * could hide a real tag rather than a described one.
 *
 * A regex is honest enough for this and a JSX parse is not warranted: the failure mode of a
 * mis-stripped file is a spec that reports a tag it should not have found, i.e. a loud false failure on
 * a specific named file, not a silent pass.
 */
export function stripComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}
