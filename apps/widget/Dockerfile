# syntax=docker/dockerfile:1.9
#
# apps/widget/Dockerfile — the embedded widget: a tiny host-page loader plus the iframe chat app.
#
# NOTE ON .dockerignore, WHICH IS NOT WHERE YOU EXPECT.
# apps/widget is the PRIMARY build context, but not one COPY below reads from it — every source
# line says `--from=workspace`, the named context pointing at the repo root. So there is no
# apps/widget/.dockerignore worth writing; the file that actually excludes secrets and build junk
# from what lands here is the repo-root /.dockerignore (BuildKit applies a local named context's
# own root .dockerignore). If you add an exclusion for this image, add it there.
#
# ================================================================================================
# NAMING SEAM — READ THIS BEFORE GREPPING
# ================================================================================================
# The directory is `apps/widget`. The Compose service is `sdk` (that is §24.2's name for it). Both
# names are correct and neither is being renamed to match the other: `sdk` is what the deployment
# calls the thing that serves the loader and the static iframe assets, `widget` is what the source
# tree calls the code. `grep sdk apps/widget` finds nothing, and `grep widget infrastructure/`
# finds the hostname but not the service. This comment is the map.
#
# Vite 8 library mode builds TWO artifacts from one package:
#   dist/loader/kb-widget.js   a single IIFE, no code splitting, <= 5 kB brotli, runs on a HOSTILE
#                              third-party page. It imports nothing from packages/contracts.
#   dist/app/                  an ordinary code-split Vite app build, served in the iframe on OUR
#                              origin (${WIDGET_DOMAIN} — a SEPARATE REGISTRABLE DOMAIN).
#
# The budgets are a BUILD GATE, not a target: `size-limit` runs in the same CI job as the build and
# its exit code is the job's. This code competes with a customer's analytics and ad tags for the
# same main thread.
ARG NODE_IMAGE=node:22-bookworm-slim
ARG NGINX_IMAGE=nginx:1.29-alpine

# ================================================================================================
# STAGE: deps
# ================================================================================================
FROM ${NODE_IMAGE} AS deps

ENV PNPM_HOME=/pnpm \
    PATH=/pnpm:$PATH \
    CI=true
RUN corepack enable

WORKDIR /app

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
# near-empty file describing a manifest with zero dependencies, and copying only that one gives the
# resolver nothing to freeze against. Every member this build installs therefore brings its own
# lockfile alongside its package.json. apps/mobile is absent on purpose: it is not in this image's
# graph, and pnpm skips a workspace glob that matches nothing.
#
# tsconfig.base.json comes along for the same class of reason: every workspace tsconfig does
# `"extends": "../../tsconfig.base.json"`, and its ABSENCE IS NOT A BUILD FAILURE. tsc reports
# `error TS5083: Cannot read file '/app/tsconfig.base.json'` once per file and tsup keeps going
# with compiler defaults — so the declarations for packages/contracts get emitted WITHOUT
# `strict`, `noUncheckedIndexedAccess` or `verbatimModuleSyntax`, and the image ships types that
# are quietly laxer than the ones CI type-checked. It installs nothing and is copied here, with
# the manifests, because it changes about as often as they do.
COPY --link --from=workspace .npmrc package.json pnpm-lock.yaml pnpm-workspace.yaml tsconfig.base.json ./
COPY --link --from=workspace apps/widget/package.json apps/widget/pnpm-lock.yaml ./apps/widget/
COPY --link --from=workspace packages/contracts/package.json packages/contracts/pnpm-lock.yaml ./packages/contracts/
COPY --link --from=workspace packages/design-tokens/package.json packages/design-tokens/pnpm-lock.yaml ./packages/design-tokens/

RUN --mount=type=cache,target=/pnpm/store \
    pnpm install --frozen-lockfile

# ================================================================================================
# STAGE: build — also the target the dev overlay builds (`pnpm --filter widget dev`)
# ================================================================================================
FROM deps AS build

COPY --link --from=workspace packages ./packages
COPY --link --from=workspace apps/widget ./apps/widget

RUN pnpm --filter contracts build \
 && pnpm --filter design-tokens build

# __KB_* constants are Vite `define` TEXT SUBSTITUTIONS, not bindings. An undeclared one is NOT a
# build error — it survives verbatim into the IIFE and the loader dies on a customer's live page
# with `ReferenceError: __KB_API_ORIGIN__ is not defined` before the launcher paints. An UNSET one
# is quieter still: the loader ships `https://undefined` and every session mint fails CORS.
ARG KB_WIDGET_ORIGIN
ARG KB_API_ORIGIN
ARG KB_VERSION=dev
ENV KB_WIDGET_ORIGIN=${KB_WIDGET_ORIGIN} \
    KB_API_ORIGIN=${KB_API_ORIGIN} \
    KB_VERSION=${KB_VERSION} \
    NODE_ENV=production

RUN set -eux; \
    pnpm --filter widget build; \
    # The built bundles must contain no `__KB_` substring. If one survives, `define` was missing a
    # key that the source uses — the exact failure described above, caught here instead of on a
    # customer's page.
    if grep -rl '__KB_' apps/widget/dist 2>/dev/null | grep -q .; then \
        echo "FATAL: an unsubstituted __KB_* constant survived into the build."; \
        echo "       Every __KB_* in apps/widget/src must also be a key in vite.config's define."; \
        exit 1; \
    fi

# size-limit runs HERE, in the same stage as the build, because it reads dist/ — and its exit code
# is this stage's. A budget checked in a different job, or with `|| true`, is a wish.
RUN pnpm --filter widget size

# ================================================================================================
# STAGE: runtime — static assets behind nginx
# ================================================================================================
FROM ${NGINX_IMAGE} AS runtime

# Serves static files and nothing else: no upstream, no FastCGI, no application code. That is why
# it is a stock nginx image rather than the two-process arrangement the core-api image needs.
RUN set -eux; cat > /etc/nginx/conf.d/default.conf <<'CONF'
server {
    listen 8080 default_server;
    server_name _;
    root /usr/share/nginx/html;

    # Access logging is off: the edge already logs with a field allow-list, and Traefik drops
    # RequestPath because url.String() carries the query string.
    access_log off;

    # The container healthcheck. Deliberately not `/` — a SPA index that renders is not evidence
    # that nginx is serving, and an index that 200s on an error page hides a broken deploy.
    location = /healthz {
        add_header Content-Type text/plain;
        return 200 'ok';
    }

    # The loader is fetched by a <script> tag on a customer's page, from an origin we do not
    # control, so it needs CORS. It is public, non-secret, static code.
    location /loader/ {
        add_header Access-Control-Allow-Origin '*' always;
        add_header Cross-Origin-Resource-Policy 'cross-origin' always;
        # SHORT cache. This file is on pages we cannot redeploy: a year-long immutable cache means
        # a security fix takes a year to reach the customers who need it most.
        add_header Cache-Control 'public, max-age=300' always;
        add_header X-Content-Type-Options 'nosniff' always;
        try_files $uri =404;
    }

    # The iframe app's hashed assets. Content-hashed filenames, so immutable is safe here.
    location /assets/ {
        add_header Cache-Control 'public, max-age=31536000, immutable' always;
        add_header X-Content-Type-Options 'nosniff' always;
        try_files $uri =404;
    }

    location / {
        add_header X-Content-Type-Options 'nosniff' always;
        # NO frame-ancestors and NO Content-Security-Policy here.
        # The iframe DOCUMENT is served by Laravel at ${WIDGET_DOMAIN}/embed/*, precisely because
        # its frame-ancestors is derived PER BOT from the live allow-list on every request. A
        # static CSP in this file would either be wrong for every tenant or would be `*`.
        try_files $uri $uri/ /index.html;
    }
}
CONF

COPY --link --from=build /app/apps/widget/dist /usr/share/nginx/html

# nginx:alpine's default config listens on 80 as root. Rewrite the pid path and drop to the fixed
# uid so `user: "10001:10001"` and `read_only: true` in compose.prod.yaml both work.
RUN set -eux; \
    addgroup -g 10001 app; \
    adduser -u 10001 -G app -D -H app; \
    sed -i 's,^pid .*,pid /var/run/nginx.pid;,' /etc/nginx/nginx.conf; \
    sed -i '/^user /d' /etc/nginx/nginx.conf; \
    chown -R app:app /var/cache/nginx /usr/share/nginx/html

USER app
EXPOSE 8080

CMD ["nginx", "-g", "daemon off;"]
