# syntax=docker/dockerfile:1.9
#
# services/core-api/Dockerfile — the Laravel control plane.
# Lives beside the service it builds (ADR-023), not under infrastructure/: the build context is
# this directory, and a Dockerfile two directories away means every `COPY` is a `../..` and the
# .dockerignore stops protecting anything.
#
# ================================================================================================
# ONE IMAGE, SIX SERVICES.
# ================================================================================================
#   laravel-api          kb-serve api      nginx + the `api` FPM pool
#   laravel-api-stream   kb-serve stream   nginx + the `stream` FPM pool (buffering off)
#   laravel-worker       php artisan horizon                (HORIZON_ENVIRONMENT=worker-fast)
#   laravel-worker-long  php artisan horizon                (HORIZON_ENVIRONMENT=worker-long)
#   laravel-scheduler    php artisan schedule:work
#   laravel-migrate      php artisan migrate --force --isolated
# Six roles, one artifact, one digest, one CVE scan. The role is chosen by `command:` in Compose.
#
# Stages:  composerbin -> base -> vendor -> runtime
#
# Base images take a build ARG so CI can pass a DIGEST. An unpinned `FROM php:8.4-fpm` makes the
# SBOM a description of a build that no longer exists and makes "we fixed that CVE" unfalsifiable.
ARG PHP_IMAGE=php:8.4-fpm-bookworm
ARG COMPOSER_IMAGE=composer:2.8

# ================================================================================================
# STAGE: composerbin — the composer BINARY only, not a PHP to run it on.
# ================================================================================================
# The composer image is used purely as a delivery mechanism for a pinned /usr/bin/composer phar.
# `composer install` itself runs in the `vendor` stage below, on OUR php image — see the long
# explanation there. Copying the phar out is also what keeps the composer version pinned by tag
# instead of curl-ing an installer whose output changes daily.
FROM ${COMPOSER_IMAGE} AS composerbin

# ================================================================================================
# STAGE: base — the PHP runtime with every extension. Shared by dev and prod.
# ================================================================================================
FROM ${PHP_IMAGE} AS base

ARG PECL_OPENTELEMETRY_VERSION=1.2.1
ARG PECL_REDIS_VERSION=6.2.0

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    PHP_INI_DIR=/usr/local/etc/php

# NGINX IS IN THIS IMAGE ON PURPOSE.
# Traefik v3 has no FastCGI provider — it speaks HTTP to a backend and nothing else — so something
# must translate. A separate `nginx` Compose service would have to join both `edge` (to receive
# from Traefik) and `application` (to reach php-fpm), which would make it a THIRD member of the
# edge-intersect-application set that is closed at exactly {laravel-api, laravel-api-stream}.
# One container with two processes keeps that set closed.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        nginx \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libxml2-dev \
        libonig-dev \
        zlib1g-dev \
        ca-certificates \
        procps \
    ; \
    rm -rf /var/lib/apt/lists/*

# ------------------------------------------------------------------------------------------------
# ext-pcntl and ext-posix are NOT optional here, and their absence is silent.
# ------------------------------------------------------------------------------------------------
# Worker::supportsAsyncSignals() returns extension_loaded('pcntl'). With no pcntl there is no
# SIGALRM, so `--timeout` DOES NOTHING and no error says so — which removes the ceiling the whole
# retry_after arithmetic depends on (`--timeout < retry_after < lock TTL`). A hung job then pins a
# worker until retry_after redelivers it, and two workers process one source.
# Horizon additionally hard-requires both pcntl and posix.
RUN set -eux; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" \
        pcntl \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        opcache \
        sockets

# phpredis (the C extension), not Predis. It is Laravel's documented recommendation and the default
# value of `redis.client`; a queue worker executes an EVAL per poll, forever, so the extension is
# the right side of that trade. Predis 3.x is the fallback only where the extension cannot build.
# NOTE: options.serializer and options.compression stay UNSET in config — the docs state plainly
# that the redis QUEUE driver does not support them.
#
# PECL opentelemetry: without the extension, OTEL_PHP_AUTOLOAD_ENABLED emits an E_USER_WARNING
# nobody reads and instruments NOTHING — every internal call then starts a fresh root span and the
# Laravel->FastAPI trace splits with no error anywhere.
RUN set -eux; \
    pecl install "redis-${PECL_REDIS_VERSION}"; \
    pecl install "opentelemetry-${PECL_OPENTELEMETRY_VERSION}"; \
    docker-php-ext-enable redis opentelemetry; \
    pecl clear-cache

# ------------------------------------------------------------------------------------------------
# BUILD-TIME ASSERTION. This is the check CI would otherwise have to remember to run.
# ------------------------------------------------------------------------------------------------
# Every one of these missing produces a SILENT behaviour change at runtime, not an error:
#   pcntl/posix  -> --timeout is a no-op and Horizon refuses to balance
#   opentelemetry-> the trace splits at the PHP->Python seam
#   redis        -> Laravel falls back to a driver we did not configure
#   pdo_pgsql    -> the DB_CONNECTION=pgsql driver is unresolvable and every boot 500s
#   opcache      -> every request recompiles every file; the FPM pool sizing in fpm-api.conf is
#                   arithmetic done against compiled-code memory, so without it the box swaps
#   fileinfo     -> libmagic. The upload intake's step 3 sniffs a file's type FROM ITS CONTENT and
#                   deliberately has NO FALLBACK to the extension, so without this every upload
#                   500s. That is the correct failure — an extension-derived MIME is the client's
#                   claim wearing the server's voice — and this line is what turns it into a build
#                   failure instead. Enabled by default in php:*-fpm images, which is exactly why
#                   it is asserted: nothing above installs it, so a base-image change is the way it
#                   would disappear.
#   zip          -> enumerates OPC part names for the vbaProject.bin / /embeddings/ refusal and
#                   reads the ZIP central directory for the three decompression caps. Installed
#                   explicitly above; asserted here so the two cannot drift.
#
# ZEND EXTENSIONS ARE NOT PHP MODULES, AND `php -m` PROVES IT. DO NOT "SIMPLIFY" THIS BACK.
# ------------------------------------------------------------------------------------------------
# The first five above are ordinary PHP extensions and `php -m | grep -qx <name>` tests them
# correctly. OPcache is a ZEND extension, loaded through `zend_extension=` rather than
# `extension=`, and `php -m` prints it under its HUMAN-READABLE name — the literal string
# `Zend OPcache`, in BOTH the [PHP Modules] and [Zend Modules] sections. It is never printed as
# the lowercase `opcache` that names the .so and the ini section.
#
# So `grep -qx opcache` can never match, on any image, whether or not OPcache is installed and
# enabled — which is exactly how this loop failed on the first real build of this image. The bug
# was in the assertion, not the installation. Adding `opcache` to a `php -m` grep list is the
# obvious-looking edit and it is always wrong; the two kinds of extension are checked separately
# below for that reason.
#
# extension_loaded() takes the same human-readable name `php -m` prints, so "Zend OPcache" is the
# argument here too. `opcache_get_status(false)` is deliberately NOT used as the probe: it returns
# false when opcache.enable_cli=0, which is the default and is a perfectly correct configuration
# for a build stage — it would fail a healthy image. Presence of the extension is what this stage
# can honestly assert; whether it is ENABLED for FPM is decided by php.ini, which is COPY'd from
# the `infra` context below and asserted by the runtime healthcheck, not here.
RUN set -eux; \
    for ext in pcntl posix opentelemetry redis pdo_pgsql fileinfo zip; do \
        php -m | grep -qx "$ext" || { echo "FATAL: php extension '$ext' is missing"; exit 1; }; \
    done; \
    php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);' \
        || { echo "FATAL: Zend extension 'Zend OPcache' is missing (php -m prints it as"; \
             echo "       'Zend OPcache', NOT as 'opcache' — do not add it to the grep loop)"; \
             exit 1; }; \
    php -v

# Configuration is COPY'd, never bind-mounted: production forbids source bind mounts, and the FPM
# pool sizing must ship with the artifact it describes.
#
# `--from=infra` is a NAMED BUILD CONTEXT, declared as `additional_contexts: {infra: ../..
# /infrastructure/docker}` on every core-api build in compose.yaml. A Dockerfile cannot COPY from
# outside its own context, so `COPY ../../infrastructure/...` is a build ERROR, not a path that
# resolves. Building this image without that named context fails loudly at this line — which is the
# correct failure, since a build that silently omitted php.ini would ship output_buffering=4096 and
# every SSE answer would arrive as one blob.
COPY --link --from=infra php/php.ini           ${PHP_INI_DIR}/conf.d/zz-kb.ini
COPY --link --from=infra php/fpm-api.conf      /etc/php-pools/fpm-api.conf
COPY --link --from=infra php/fpm-stream.conf   /etc/php-pools/fpm-stream.conf
COPY --link --from=infra php/nginx-api.conf    /etc/nginx-confs/nginx-api.conf
COPY --link --from=infra php/nginx-stream.conf /etc/nginx-confs/nginx-stream.conf

# The image's own default pool would listen on 9000 alongside ours. Remove it.
RUN rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf

# ------------------------------------------------------------------------------------------------
# kb-serve — the role selector, and the reason two processes in one container is safe here.
# ------------------------------------------------------------------------------------------------
# php-fpm runs in the background and nginx in the foreground, with an explicit trap forwarding the
# stop signal to BOTH. Without the trap, `docker compose down` signals only PID 1 (nginx) and
# php-fpm is SIGKILLed when the grace period expires — which on laravel-api-stream means a live
# answer is cut with its usage row unwritten, and the tenant is not billed for tokens spent.
RUN set -eux; cat > /usr/local/bin/kb-serve <<'SH' && chmod +x /usr/local/bin/kb-serve
#!/bin/bash
# BASH, not sh. `wait -n` below is a bash builtin and does not exist in dash, which is /bin/sh on
# Debian — and a missing `wait -n` there does not error usefully: the script waits on the first
# child only, so a dead php-fpm leaves nginx serving 502s in a container Docker considers healthy.
set -eu

ROLE="${1:?usage: kb-serve api|stream}"

case "$ROLE" in
  api)    POOL=/etc/php-pools/fpm-api.conf;    NGINX=/etc/nginx-confs/nginx-api.conf ;;
  stream) POOL=/etc/php-pools/fpm-stream.conf; NGINX=/etc/nginx-confs/nginx-stream.conf ;;
  *)      echo "kb-serve: unknown role '$ROLE' (expected api|stream)" >&2; exit 64 ;;
esac

# Fail fast and loudly rather than serving with the wrong pool.
[ -r "$POOL" ]  || { echo "kb-serve: cannot read $POOL" >&2;  exit 78; }
[ -r "$NGINX" ] || { echo "kb-serve: cannot read $NGINX" >&2; exit 78; }

php-fpm --fpm-config "$POOL" --nodaemonize &
FPM_PID=$!

shutdown() {
  # SIGQUIT is php-fpm's GRACEFUL stop: it lets each child finish its current request. SIGTERM is
  # the immediate one and would cut a stream mid-answer. The container's stop_grace_period
  # (150s on the stream service) is what gives this time to complete.
  kill -QUIT "$FPM_PID" 2>/dev/null || true
  kill -QUIT "$NGINX_PID" 2>/dev/null || true
  wait "$FPM_PID" 2>/dev/null || true
  wait "$NGINX_PID" 2>/dev/null || true
  exit 0
}
# QUIT IS LOAD-BEARING AND IS NOT REDUNDANT WITH THE COMPOSE `stop_signal:`. This image inherits
# `STOPSIGNAL SIGQUIT` from its `php:*-fpm*` base, so a plain `docker run` + `docker stop` — no
# compose file, which is how an operator debugs one container and how any bare `docker run` of it
# behaves —
# delivers QUIT, not TERM. PID 1 DISCARDS ANY SIGNAL WHOSE DISPOSITION IS STILL THE DEFAULT, so an
# untrapped QUIT here is not "terminate", it is IGNORED: the container sits until the grace period
# expires and is then SIGKILLed. MEASURED against this image on 2026-08-17 — `docker stop -t 30`
# took 31s and exited 137 with `trap shutdown TERM INT`, and 0s exiting 0 once QUIT was added.
# The per-service `stop_signal: SIGTERM` in compose.yaml (ADR-045) covers the compose path and
# stays; this covers every other path, and the two are deliberately belt-and-braces.
trap shutdown TERM INT QUIT

nginx -c "$NGINX" &
NGINX_PID=$!

# If EITHER process dies, exit — so `restart: unless-stopped` recovers the container instead of
# leaving a half-dead one that Traefik keeps routing to.
wait -n "$FPM_PID" "$NGINX_PID"
shutdown
SH

# Non-root, with a FIXED uid/gid so the production overlay's `user: "10001:10001"` matches the
# ownership baked in here. A mismatch shows up as permission errors on storage/ at boot.
RUN set -eux; \
    groupadd -g 10001 app; \
    useradd -u 10001 -g app -m -s /usr/sbin/nologin app; \
    mkdir -p /var/lib/nginx /var/log/nginx /var/run; \
    chown -R app:app /var/lib/nginx /var/log/nginx

WORKDIR /app

# ================================================================================================
# STAGE: vendor — dependency resolution only. Nothing from this stage ships except vendor/.
# ================================================================================================
# THIS STAGE IS `FROM base`, NOT `FROM composer:2.8`. THAT IS THE WHOLE POINT OF IT.
# ------------------------------------------------------------------------------------------------
# `composer install` resolves PLATFORM requirements — ext-*, php version — against the PHP THE
# RESOLVER IS RUNNING ON, not against the image the vendor/ tree is destined for. The composer
# image is Alpine PHP with a stock extension set, so running the install there checks our
# composer.json against the WRONG platform and fails on every extension this project actually has:
#     Root composer.json requires PHP extension ext-pdo_pgsql * but it is missing from your system
#     laravel/horizon v5.48.2 requires ext-pcntl * -> it is missing from your system
# (a real failure of this image's first full build, after the lockfile landed). The two tempting
# fixes are both wrong: `--ignore-platform-req=ext-…` disables the only check that would catch a
# package needing an extension we do NOT ship, and a `config.platform` block in composer.json
# hard-codes a platform description that then drifts from this Dockerfile silently.
#
# Running the resolver on `base` makes the check honest: the platform composer validates against
# IS the platform the code will run on, extension for extension, because the assertion above has
# already proven those extensions are present. A new dependency requiring ext-gd now fails HERE,
# in the build, rather than at the first request that touches it.
#
# The cost is a real one and is accepted deliberately: vendor/ no longer builds in parallel with
# base, and editing the apt or pecl layers invalidates the composer install. Correct platform
# resolution is worth more than the parallelism.
FROM base AS vendor

COPY --link --from=composerbin /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# Only the two files that determine the dependency graph, so an edit to a controller does not
# invalidate the (slow) install layer.
COPY composer.json composer.lock ./

# --no-dev: PHPUnit, Pest, Faker and the debug bar do not belong in a shipped artifact — they are
#   attack surface and they inflate every CVE report with findings a self-hoster cannot act on.
# --no-scripts: post-install scripts run artisan, and artisan needs the application source that is
#   not here yet. Package discovery happens in the runtime stage, once.
# --no-autoloader: the classmap is built once in `runtime`, with the source present.
# --prefer-dist + --no-progress: reproducible and quiet.
#
# NEVER DROP composer.lock FROM THE `COPY` LINE ABOVE.
# ------------------------------------------------------------------------------------------------
# The lockfile now exists and is committed, alongside services/ai-service/uv.lock and the four
# per-workspace pnpm-lock.yaml files (apps/web, apps/widget, apps/mobile, packages/contracts —
# four and not one because .npmrc sets shared-workspace-lockfile=false). This image is buildable;
# the paragraph that used to stand here said it was not, and that is no longer true in any clause.
#
# What survives from that paragraph is the reason the COPY names BOTH files. `composer install`
# with composer.json alone is not a build failure — it is a SUCCESSFUL install of a graph nobody
# reviewed, resolved fresh on the day of the build, so two images built a week apart from the same
# commit contain different code and the SBOM describes neither. With the lock present, `composer
# install` installs exactly what is locked and errors if the lock is stale against composer.json.
# Removing composer.lock from the COPY to "fix" a missing-file error therefore trades one loud,
# correct failure for a silent, permanent one. If the lock is genuinely out of date, regenerate it
# on the host (`composer update`) and commit it — never work around it here.
#
# Note the flags deliberately do NOT include --no-plugins: several first-party Laravel packages
# ship installer plugins. They DO exclude dev requirements, so PHPUnit/Pest/Faker never reach the
# artifact even though they are in the lock.
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1 \
    composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --no-interaction \
      --no-progress

# ================================================================================================
# STAGE: runtime — the shipped artifact.
# ================================================================================================
FROM base AS runtime

COPY --link --from=vendor /app/vendor ./vendor
COPY --link --chown=app:app . .

# Autoloader and package discovery run ONCE, here, with the source present. `--optimize` builds a
# classmap; `--classmap-authoritative` is deliberately not used because Laravel resolves some
# classes that are generated at runtime.
#
# composer is BIND-MOUNTED, not COPY'd. It is needed for exactly this one command and must not
# exist in the shipped artifact: a package manager inside a production PHP container turns any
# code-execution foothold into "install an arbitrary package from the internet". A `COPY` followed
# by `rm` would not achieve that — the phar would still be in the earlier layer, visible to
# `docker history` and `docker save` and extractable by anyone who can pull the image. A bind
# mount creates no layer at all, so composer is genuinely absent from the result. (Verify with
# `docker run --rm <image> sh -c 'command -v composer'` — it must print nothing.)
RUN --mount=type=bind,from=composerbin,source=/usr/bin/composer,target=/usr/local/bin/composer \
    set -eux; \
    composer dump-autoload --no-dev --optimize --no-interaction; \
    php artisan package:discover --ansi

# Config/route/view caches are NOT built here.
# `php artisan config:cache` bakes the ENVIRONMENT AT BUILD TIME into a PHP file — including
# whatever DB_HOST and APP_KEY happened to exist in the builder. The artifact would then carry one
# deployment's configuration and ignore env_file: entirely, and the failure is a service that
# connects to the wrong database rather than an error. Caching belongs in an entrypoint or a
# post-deploy step, after the real environment is present.
#
# AND NOW ALSO: config/*.php resolves secrets FILE-FIRST through App\Support\Kb\KbSecrets, so
# bootstrap/cache/config.php contains the KEK, both HMAC signing keys, the database password and
# the S3 secret IN PLAINTEXT. That is deliberate — `config:cache` serialises with var_export(),
# which cannot express a lazy value, so a closure in config makes the whole cache unserialisable.
# The consequence is a hard rule: that file must never enter an image layer. It is in
# .dockerignore and .gitignore, it is written at container START with /run/secrets mounted, and it
# lives on the writable layer that dies with the container. A secret rotation therefore needs a
# config-cache rebuild, i.e. a restart — which is already true of every configuration change here.

RUN set -eux; \
    mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
             storage/logs bootstrap/cache; \
    chown -R app:app storage bootstrap/cache

USER app

EXPOSE 8080

# Default role. Every service in Compose overrides this with an explicit `command:`; the default
# exists so a bare `docker run` of this image does something comprehensible.
CMD ["kb-serve", "api"]
