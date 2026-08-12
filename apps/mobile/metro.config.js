// Learn more: https://docs.expo.dev/guides/monorepos/
const path = require('node:path');
const { getDefaultConfig } = require('expo/metro-config');

/**
 * METRO IN A pnpm WORKSPACE — the highest-risk file in this package.
 *
 * The failure this config exists to prevent: pnpm's default isolated node-linker installs each
 * package into `node_modules/.pnpm/<name>@<version>/node_modules/<name>` and symlinks only the
 * declared dependencies into each workspace's own `node_modules`. Metro then has to (a) follow
 * symlinks out of apps/mobile and (b) not walk up the directory tree finding a different copy of
 * react/react-native. Get either wrong and you get "Unable to resolve module", or — far worse —
 * two React copies loaded at once, which surfaces as "Invalid hook call" from a component nobody
 * touched.
 *
 * THE WORKAROUND WE DO NOT TAKE: `node-linker=hoisted` in the ROOT .npmrc. It is the answer every
 * Expo-monorepo thread gives, and it directly contradicts tanstack-query-table's Definition of
 * Done, which relies on pnpm's strict layout to prove that `@tanstack/react-store` is a DECLARED
 * dependency of apps/web rather than a transitive one that happens to resolve. Hoisting globally
 * makes every undeclared transitive dependency resolve everywhere, in every workspace, forever.
 *
 * Be precise about what that costs, because right now it costs nothing measurable: no ecosystem in
 * this repo has a lockfile yet, and `.github/workflows/gates.yml` says so in its own header: every
 * job in it is a pure text pass, with no `pnpm install`, no typecheck, no Jest and no build. It
 * enforces nothing at all under `apps/`; the dependency-installing jobs land in a later `ci.yml`.
 * So an undeclared transitive dependency is caught by NOTHING automated today — strict layout or
 * hoisted, the CI result is the same green. What the strict layout preserves is the ABILITY to
 * catch it: under it, `pnpm install && pnpm web:build` fails on an undeclared import — on a
 * developer's machine today, and in CI once that job exists. Hoisting removes that ability. So the
 * cost is in the future tense: it converts a class of bug that CI WILL catch, as soon as the apps/
 * install jobs exist, into one that nothing can ever catch — while leaving the gap that exists in
 * the meantime looking exactly the same.
 *
 * WHAT WE DO INSTEAD: modern Metro (the version shipped with RN 0.86 / Expo SDK 57) resolves
 * symlinks natively, so the three settings below are sufficient:
 *   - watchFolders: the repo root, so edits in packages/contracts trigger a rebuild
 *   - nodeModulesPaths: this package's node_modules, then the root's
 *   - disableHierarchicalLookup: stop Metro walking UP the tree past those two
 *
 * THE ESCAPE HATCH, if a real device build genuinely fails on resolution: add
 *
 *     node-linker=hoisted
 *
 * to `apps/mobile/.npmrc` — that file ONLY, never the root. Per-workspace .npmrc is honoured by
 * pnpm, and the root's `shared-workspace-lockfile=false` means apps/mobile already owns its own
 * pnpm-lock.yaml, so a hoisted layout here changes this workspace's resolution and nothing else.
 * apps/web's strict layout stays intact, and with it the tanstack-query-table Definition-of-done
 * item that depends on it (`@tanstack/react-store` DECLARED, not transitive).
 *
 * Note what does and does not enforce that. The DoD item is a review checklist line in
 * `.claude/skills/tanstack-query-table/SKILL.md`; there is no grep behind it, and nothing in
 * `.github/workflows/` inspects the node_modules layout. The one control here that IS automatable
 * is the setting itself, because a hoisted workspace cannot exist without writing the word down:
 *
 *     grep -rn --include='.npmrc' 'node-linker' . --exclude-dir=node_modules
 *
 * Run from the repo root, that returns nothing today (exit 1) — no .npmrc in this tree sets
 * node-linker. After the escape hatch is taken it must return exactly ONE line, and that line must
 * be in `apps/mobile/.npmrc`. Any other path in the output means the root or another workspace was
 * hoisted, which is what this note forbids. Nothing runs that grep for you yet; it belongs in the
 * mobile CI job alongside the install. Document the reason in the .npmrc when you add it; an
 * undocumented node-linker line is indistinguishable from a cargo-culted one.
 */

const projectRoot = __dirname;
const workspaceRoot = path.resolve(projectRoot, '../..');

const config = getDefaultConfig(projectRoot);

// 1. Watch the whole workspace so a change in packages/contracts/dist rebuilds the app. Without
//    this, editing the shared frame parser appears to do nothing until you restart the bundler —
//    and the version you are debugging is the stale one.
config.watchFolders = [workspaceRoot];

// 2. Resolve from this package first, then the workspace root. Order matters: apps/mobile's own
//    react/react-native must win over anything hoisted to the root by another workspace.
config.resolver.nodeModulesPaths = [
  path.resolve(projectRoot, 'node_modules'),
  path.resolve(workspaceRoot, 'node_modules'),
];

// 3. Stop the Node-style upward walk. With hierarchical lookup left on, Metro keeps climbing past
//    the two paths above and can bind a SECOND copy of react from an unrelated directory. The
//    symptom is "Invalid hook call" or a hooks-order crash in a file that has not changed, which
//    is why this is the setting people find last.
config.resolver.disableHierarchicalLookup = true;

// 4. `@kb/contracts` publishes an exports map with two subpaths (`.` and `./forms`, ADR-028) and
//    a `require`/`import` condition split. Package-exports support is what makes
//    `@kb/contracts/forms` resolvable at all; without it that import fails while the root entry
//    keeps working, so the breakage looks like a problem with zod rather than with resolution.
//    Stated explicitly rather than relied on as a default, because this is the one package in the
//    tree whose resolution silently degrades.
config.resolver.unstable_enablePackageExports = true;

// Symlink resolution is native in this Metro, but the workspace link
// (apps/mobile/node_modules/@kb/contracts -> packages/contracts) is the thing that breaks first on
// a downgrade, so it is asserted here rather than assumed.
config.resolver.unstable_enableSymlinks = true;

module.exports = config;
