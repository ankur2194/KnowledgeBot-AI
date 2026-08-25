# syntax=docker/dockerfile:1.9
#
# apps/web/Dockerfile — Next.js 16 App Router: admin console (app.<domain>) and hosted chat
# (chat.<domain>). One container, two deliberately separate ORIGINS.
#
# Beside the app it builds (ADR-023). `--from=workspace` is a named build context pointing at the
# repo root, because this is a pnpm workspace member: pnpm-workspace.yaml, .npmrc and
# packages/{contracts,design-tokens} all live above apps/web, and a Dockerfile cannot COPY outside
# its own context.
#
# NOTE ON .dockerignore, WHICH IS NOT WHERE YOU EXPECT.
# apps/web is the PRIMARY context, but not one COPY below reads from it — every source line says
# `--from=workspace`. So apps/web/.dockerignore filters a context this build never touches, and
# the file that actually excludes secrets and build junk from what lands here is the repo-root
# /.dockerignore (BuildKit applies a local named context's own root .dockerignore). If you add an
# exclusion for this image, add it there.
#
# The lockfile is apps/web/pnpm-lock.yaml, NOT the root one — .npmrc sets
# shared-workspace-lockfile=false, so there are four lockfiles and the root one describes a
# manifest with no dependencies. See the COPY block below.
#
# ================================================================================================
# THIS IMAGE CONTAINS NO CREDENTIAL, AND THAT IS STRUCTURAL
# ================================================================================================
# apps/web is a CLIENT of Laravel, not a peer of ai-api. No provider key, no internal HMAC key, no
# FastAPI hostname. The Compose service is on the `edge` network ONLY (ADR-013), so a route handler
# that tried fetch('http://ai-api:8000/...') cannot even resolve the name — "clients never reach
# FastAPI" is enforced by the network rather than by review.
ARG NODE_IMAGE=node:22-bookworm-slim

# ================================================================================================
# STAGE: deps — workspace install. Also the target the dev overlay builds (`pnpm dev`).
# ================================================================================================
FROM ${NODE_IMAGE} AS deps

ENV PNPM_HOME=/pnpm \
    PATH=/pnpm:$PATH \
    CI=true
RUN corepack enable

WORKDIR /app

# Only the manifests, so editing a component does not reinstall the tree. Every workspace member's
# package.json is required or pnpm resolves a different (smaller) graph and the build fails later
# with a missing-module error that points at the wrong place.
#
# ------------------------------------------------------------------------------------------------
# .npmrc IS PART OF THE DEPENDENCY GRAPH, NOT A DEVELOPER CONVENIENCE. IT MUST BE COPIED.
# ------------------------------------------------------------------------------------------------
# pnpm 10 writes the settings that affected resolution INTO the lockfile (`settings:
# autoInstallPeers / excludeLinksFromLockfile`) and a frozen install refuses to proceed when the
# ambient config disagrees. The repo's .npmrc sets auto-install-peers=false; omit the file and the
# build inherits pnpm's default of true, and the install dies with
#   ERR_PNPM_LOCKFILE_CONFIG_MISMATCH  ... "settings.autoInstallPeers" ...
# which reads like a stale lockfile and is not. (That is a real build failure this image hit.)
#
# THERE ARE FOUR LOCKFILES, AND THE ROOT ONE IS NOT THE APP'S.
# ------------------------------------------------------------------------------------------------
# .npmrc also sets shared-workspace-lockfile=false, so each workspace member resolves into its OWN
# pnpm-lock.yaml — apps/web, apps/widget, apps/mobile and packages/contracts, which is exactly the
# set security-scanning-toolchain feeds to `osv-scanner --lockfile`. The root pnpm-lock.yaml is a
# near-empty file describing a manifest with zero dependencies (the root declares none, by design:
# a single root dependency would write a fifth lockfile), so copying only that one gives the
# resolver nothing to freeze against. Every member this build installs therefore brings its own
# lockfile alongside its package.json. apps/mobile is absent on purpose: it is not in this image's
# graph, and pnpm skips a workspace glob that matches nothing.
#
# tsconfig.base.json comes along for the same class of reason: every workspace tsconfig does
# `"extends": "../../tsconfig.base.json"`, and its ABSENCE IS NOT A BUILD FAILURE. tsc reports
# `error TS5083: Cannot read file '/app/tsconfig.base.json'` once per file and the build keeps
# going with compiler defaults — so packages/contracts emits declarations WITHOUT `strict`,
# `noUncheckedIndexedAccess` or `verbatimModuleSyntax`, and the image ships types that are quietly
# laxer than the ones CI type-checked. It installs nothing and is copied here, with the manifests,
# because it changes about as often as they do.
COPY --link --from=workspace .npmrc package.json pnpm-lock.yaml pnpm-workspace.yaml tsconfig.base.json ./
COPY --link --from=workspace apps/web/package.json apps/web/pnpm-lock.yaml ./apps/web/
COPY --link --from=workspace packages/contracts/package.json packages/contracts/pnpm-lock.yaml ./packages/contracts/
COPY --link --from=workspace packages/design-tokens/package.json packages/design-tokens/pnpm-lock.yaml ./packages/design-tokens/

# --frozen-lockfile: fail rather than silently resolving different versions. A resolver that picks
# a new minor makes the SBOM describe a build nobody reviewed.
RUN --mount=type=cache,target=/pnpm/store \
    pnpm install --frozen-lockfile

# ================================================================================================
# STAGE: build
# ================================================================================================
FROM deps AS build

COPY --link --from=workspace packages ./packages
COPY --link --from=workspace apps/web ./apps/web

# packages/contracts carries REAL RUNTIME CODE — the SSE frame parser, the client event union's
# guards, KbError — not only types, so it is built before the app that imports it. Three clients
# read the same stream and three hand-written parsers is three ways to disagree about `: ping`.
RUN pnpm --filter contracts build \
 && pnpm --filter design-tokens build

# ------------------------------------------------------------------------------------------------
# THERE IS EXACTLY ONE NEXT_PUBLIC_* VALUE. DO NOT ADD A SECOND ONE HERE.
# ------------------------------------------------------------------------------------------------
# NEXT_PUBLIC_* values are INLINED INTO THE BUNDLE at build time, which means they are baked into
# the image and cannot be changed by env_file: afterwards. An image built with the wrong DOMAIN
# serves a bundle that calls the wrong API host, and the symptom is CORS failures rather than a
# configuration error.
#
# apps/web/src/lib/env.ts holds the allow-list — `ALLOWED_PUBLIC_KEYS = ['NEXT_PUBLIC_API_ORIGIN']`
# — and next.config.ts calls assertPublicEnv(), so ANY other NEXT_PUBLIC_* variable present in the
# build environment FAILS `next build` by design:
#     Error: Unknown NEXT_PUBLIC_* environment variable(s): …
# That gate exists because the prefix carries no type safety and no secret classification: it is
# the one mechanism by which a server-side value silently reaches every client bundle. This
# Dockerfile previously declared four such ARGs, one of them (NEXT_PUBLIC_OTEL_TRACES_ENDPOINT)
# with a DEFAULT, so it was set on every build and the image could never build at all.
#
# The app and chat origins are NOT needed at build time: they are the two Traefik hostnames this
# one container is served on, and the app derives everything else from the request. The browser's
# OTLP endpoint is a same-origin path handled by Traefik (ADR-025), not a bundled constant; if it
# ever must be configurable, it belongs on the allow-list in env.ts first — which is
# admin-web-engineer's call, not this file's.
ARG NEXT_PUBLIC_API_ORIGIN
ENV NEXT_PUBLIC_API_ORIGIN=${NEXT_PUBLIC_API_ORIGIN} \
    NEXT_TELEMETRY_DISABLED=1 \
    NODE_ENV=production

# Requires `output: 'standalone'` in next.config. Without it the runtime stage below has no
# server.js and the image starts and immediately exits — which reads as a crash loop rather than a
# missing build option.
RUN pnpm --filter web build

# ================================================================================================
# STAGE: runtime — standalone output only
# ================================================================================================
FROM ${NODE_IMAGE} AS runtime

ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

# Fixed uid/gid matching `user: "10001:10001"` in compose.prod.yaml.
RUN set -eux; \
    groupadd -g 10001 app; \
    useradd -u 10001 -g app -m -s /usr/sbin/nologin app

WORKDIR /app

# `standalone` traces exactly the files the server needs and bundles a minimal node_modules. It is
# what keeps this image from carrying the entire monorepo's dev dependencies — which matters here
# beyond size: an image scan against a full node_modules reports dozens of devDependency CVEs a
# self-hoster cannot act on, and a report nobody can act on is a report nobody reads.
COPY --link --from=build --chown=app:app /app/apps/web/.next/standalone ./
COPY --link --from=build --chown=app:app /app/apps/web/.next/static ./apps/web/.next/static
COPY --link --from=build --chown=app:app /app/apps/web/public ./apps/web/public

USER app
EXPOSE 3000

# The standalone server, not `next start`. `next start` needs the full next package, which
# standalone deliberately does not ship.
CMD ["node", "apps/web/server.js"]

# ================================================================================================
# STAGE: browsers — the ONLY image in this repository that can run a browser
# ================================================================================================
# Nothing in the production path targets this stage, and `runtime` above does not descend from it,
# so adding it changed the size of exactly zero shipped images. BuildKit builds a stage only when
# it is an ancestor of the requested target.
#
# WHY IT HAD TO EXIST. Two suites in this repository need a real Chromium and neither could run:
#
#   * `vitest --project components` — 22 spec files that mount components in Vitest's browser mode
#     (`vitest.config.ts` → `browser.instances: [{ browser: 'chromium' }]`). Vitest resolves that
#     browser through `playwright-core`, so "chromium is installed" means "the exact build number
#     `playwright-core@1.62.1` asks for is under PLAYWRIGHT_BROWSERS_PATH", not "some chromium is
#     on PATH".
#   * `playwright test` — the `setup`, `admin` and `public` projects in `playwright.config.ts`.
#
# `knowledgebot/web:dev` is `node:22-bookworm-slim` plus the workspace install. It carries no
# browser and none of the shared libraries one needs — measured: zero of libnss3, libnspr4,
# libatk-1.0, libcups2, libgbm1 and libasound2 are present. So the failure was not "the browser is
# missing", which is a one-line fix; it was that `playwright install chromium` alone would land a
# binary that cannot dynamically link, and the error for THAT is a spawn failure with no mention of
# a library. `--with-deps` is the part that matters, and it needs root and apt, which is why this
# is a build stage rather than something a test command does at runtime.
#
# THE VERSION IS NOT WRITTEN DOWN HERE, AND THAT IS DELIBERATE. The CLI invoked below is the one
# `pnpm install` put in the workspace, so the browser revision is whatever `@playwright/test` in
# apps/web/package.json resolves to. Pinning a build number in this file would create a second
# place to update, and the failure mode of the two disagreeing is the message this stage exists to
# prevent someone seeing again:
#     Executable doesn't exist at /ms-playwright/chromium_headless_shell-<n>/...
#
# CHROMIUM ONLY. `playwright.config.ts` declares chromium projects and nothing else; firefox and
# webkit would roughly triple the download for browsers no suite names.
FROM deps AS browsers

# OUTSIDE /app ON PURPOSE. Every way this image is used bind-mounts the repo over /app, and a
# browser installed under the workspace would be hidden by that mount at the moment it is needed.
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

# `--with-deps` shells out to apt-get; the lists it leaves behind are ~40 MB of files no test reads.
RUN apps/web/node_modules/.bin/playwright install --with-deps chromium \
    && rm -rf /var/lib/apt/lists/* \
    && chmod -R a+rX /ms-playwright

# Runs as root by default like every other stage here; callers pass `--user` to keep the artefacts
# they write into the mounted workspace owned by the developer rather than by root. That is not a
# hardening claim — this image exists to drive a browser against a development stack and has no
# place in a deployment.
