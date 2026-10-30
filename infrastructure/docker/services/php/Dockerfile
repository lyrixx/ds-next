# hadolint global ignore=DL3008

# Where the application lives in the containers: /var/www is the repository root,
# and the application may live in a sub directory (see "app_dir" in .castor/context.php)
ARG APP_PATH=/var/www/application

FROM debian:13.7-slim AS php-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ca-certificates \
        gnupg \
    && curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(sed -n 's/^VERSION_CODENAME=//p' /etc/os-release) main" > /etc/apt/sources.list.d/sury.list \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash-completion \
        procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

ARG PHP_VERSION
# Minimal patch version of each PHP branch, to bump after a security release: it
# invalidates the build cache (local and registry) of the PHP packages, which would
# keep the vulnerable version otherwise, and the build fails until Sury publishes it
ARG PHP_MIN_VERSIONS="8.3.36 8.4.27 8.5.12"
# The extensions of the project, from the "php_extensions" variable of castor.php: e.g.
# "imagick redis" installs php8.5-imagick and php8.5-redis
ARG PHP_EXTENSIONS=""

# hadolint 2.15 checks the commands as POSIX sh, whatever the SHELL above
# hadolint ignore=SC3054,SC3060
RUN read -ra extensions <<< "${PHP_EXTENSIONS}" \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        "php${PHP_VERSION}-apcu" \
        "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-common" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-gd" \
        "php${PHP_VERSION}-iconv" \
        "php${PHP_VERSION}-intl" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-pgsql" \
        "php${PHP_VERSION}-uuid" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-zip" \
        "${extensions[@]/#/php${PHP_VERSION}-}" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
    && for min in ${PHP_MIN_VERSIONS}; do \
        if [ "${min%.*}" = "${PHP_VERSION}" ] && dpkg --compare-versions "$(php -r 'echo PHP_VERSION;')" lt "${min}"; then \
            echo "PHP ${min} is required, packages.sury.org does not have it yet." >&2; \
            exit 1; \
        fi; \
    done

# The system packages of the project, in every image, from the "system_packages"
# variable of castor.php: e.g. "imagemagick ghostscript"
ARG SYSTEM_PACKAGES=""
# hadolint ignore=SC3054
RUN read -ra packages <<< "${SYSTEM_PACKAGES}" \
    && if [ "${#packages[@]}" -gt 0 ]; then \
        apt-get update \
        && apt-get install -y --no-install-recommends "${packages[@]}" \
        && apt-get clean \
        && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*; \
    fi

# Every PHP setting of every image: each stage enables its own ones with phpenmod
COPY php/mods-available/ /etc/php/${PHP_VERSION}/mods-available/

ENV PHP_VERSION=${PHP_VERSION}
ENV HOME=/home/app
ENV COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www

FROM php-base AS frontend

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        "php${PHP_VERSION}-fpm" \
        runit \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
    && rm -r "/etc/php/${PHP_VERSION}/fpm/pool.d/"

RUN useradd -s /bin/false nginx

ARG APP_PATH

# Same php-fpm pool and nginx site as the production images (see "Production stages"), the
# container runs as the host user so the socket directory must be writable by anyone
COPY php/fpm/php-fpm.conf /etc/php/${PHP_VERSION}/fpm/php-fpm.conf
COPY nginx/nginx.conf /etc/nginx/nginx.conf
COPY nginx/conf.d/ /etc/nginx/conf.d/
RUN sed -i "s#/var/www/application#${APP_PATH}#" /etc/nginx/conf.d/default.conf \
    && mkdir -p /var/run/php && chmod 1777 /var/run/php
RUN rm -rf /etc/service/
COPY runit/ /etc/service/
RUN mkdir -p /etc/service/nginx/supervise /etc/service/php-fpm/supervise \
    && chmod 777 /etc/service/*/supervise/

RUN phpenmod app-default \
    && phpenmod -s fpm app-fpm

EXPOSE 8080

CMD ["runsvdir", "-P", "/etc/service"]

FROM php-base AS worker

FROM php-base AS builder

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Default toys
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        "git" \
        "make" \
        "php${PHP_VERSION}-dev" \
        "unzip" \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# The system packages of the project only needed in dev, from the
# "builder_system_packages" variable of castor.php: e.g. "mariadb-client"
ARG BUILDER_SYSTEM_PACKAGES=""
# hadolint ignore=SC3054
RUN read -ra packages <<< "${BUILDER_SYSTEM_PACKAGES}" \
    && if [ "${#packages[@]}" -gt 0 ]; then \
        apt-get update \
        && apt-get install -y --no-install-recommends "${packages[@]}" \
        && apt-get clean \
        && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*; \
    fi

# Node.js and Yarn, from the "nodejs" variable of castor.php: its version, or none
# (empty NODEJS_VERSION). Yarn is the version of the "packageManager" of package.json, or
# the stable one. Corepack keeps them out of $HOME, which the containers mount from the
# host: writable by the host user, to download the version of a project
ARG NODEJS_VERSION=24.x
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
ENV COREPACK_HOME=/usr/local/share/corepack
RUN if [ -n "${NODEJS_VERSION}" ]; then \
        curl -s https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /usr/share/keyrings/nodesource.gpg \
        && echo "deb [signed-by=/usr/share/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODEJS_VERSION} nodistro main" > /etc/apt/sources.list.d/nodesource.list \
        && apt-get update \
        && apt-get install -y --no-install-recommends \
            "nodejs" \
        && apt-get clean \
        && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
        && corepack enable \
        && corepack prepare yarn@stable --activate \
        && chmod -R a+rwX "${COREPACK_HOME}"; \
    fi

# Install a fake sudo command
# The real `sudo` refuses to work because the container runs as the host's
# raw UID, which has no /etc/passwd entry. This is a security risk if you use
# this image in production, but it's fine for the dev-only builder image.
# Use it at your own risk
# COPY base/sudo.sh /usr/local/bin/sudo
# RUN curl -L https://github.com/tianon/gosu/releases/download/1.16/gosu-amd64 -o /usr/local/bin/gosu \
#     && chmod u+s /usr/local/bin/gosu \
#     && chmod +x /usr/local/bin/gosu \
#     && chmod +x /usr/local/bin/sudo

# Config. The logs of app-builder.ini go to /var/log/php, writable by the host user
# the container runs as
RUN mkdir -p /var/log/php \
    && chmod 777 /var/log/php \
    && phpenmod app-default \
    && phpenmod app-builder

# Composer
COPY --from=composer/composer:2.10.0 /usr/bin/composer /usr/bin/composer

# Pie
RUN curl -L --output /usr/local/bin/pie https://github.com/php/pie/releases/download/1.4.5/pie.phar \
    && chmod +x /usr/local/bin/pie

# Autocompletion
ADD https://raw.githubusercontent.com/symfony/symfony/refs/heads/7.4/src/Symfony/Component/Console/Resources/completion.bash /tmp/completion.bash

# Composer symfony/console version is too old, and doest not support "API version feature", so we remove it
# Hey, while we are at it, let's add some more completion
RUN sed /tmp/completion.bash \
        -e "s/{{ COMMAND_NAME }}/composer/g" \
        -e 's/"-a{{ VERSION }}"//g' \
        -e "s/{{ VERSION }}/1/g"  \
        > /etc/bash_completion.d/composer \
    && sed /tmp/completion.bash \
        -e "s/{{ COMMAND_NAME }}/console/g" \
        -e "s/{{ VERSION }}/1/g"  \
        > /etc/bash_completion.d/console

# Third party tools
ENV PATH="$PATH:/var/www/tools/bin"

# We can't rely on \u: the container runs as the host's raw UID, which has no
# /etc/passwd entry, so bash would print "I have no name!" instead. Show
# "builder" normally, and "root" once elevated (e.g. via the fake sudo).
RUN cat >> /etc/bash.bashrc <<'EOF'
. /etc/bash_completion

PS1='\[\e[01;33m\]$([ "$EUID" = 0 ] && echo root || echo builder) \[\e[00;32m\]\w\[\e[0m\] '
EOF

ARG APP_PATH
WORKDIR ${APP_PATH}

# ------------------------- Production stages -------------------------
#
# Two self-contained images, "php" and "nginx": the application code is baked
# in (no bind mounts), dependencies are installed without dev packages and
# containers run as a non-root user. They talk to each other through the
# php-fpm unix socket /var/run/php/php-fpm.sock, shared via a volume (docker
# compose) or an emptyDir (Kubernetes). Cron jobs and workers, if any, are run
# by the orchestrator with the "php" image (`bin/console ...`).
#
# The php-fpm and nginx configuration is the one of the dev "frontend" stage
# (php/ and nginx/), the production differences live in php/mods-available/app-prod.ini.
#
# The application source code is expected in the additional build context
# named "app", pointing to the application directory (its .dockerignore applies),
# and copied to APP_PATH:
#   docker buildx build --build-context app=../../../../application --target php .
#
# Every build step only runs if the application needs it (composer.json,
# package.json, importmap.php...), so adapt them to your project.

FROM builder AS app-build

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first, for better layer caching. "app" is a build context, not a stage.
# The brackets make every file optional
# hadolint ignore=DL3022
COPY --from=app composer.jso[n] composer.loc[k] symfony.loc[k] ./
RUN if [ -f composer.json ]; then \
        composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader; \
    fi

# hadolint ignore=DL3022
COPY --from=app . .
RUN if [ -f composer.json ]; then \
        composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
        && composer dump-env prod; \
    fi \
    && if [ -f yarn.lock ]; then \
        yarn install --immutable && yarn build; \
    elif [ -f package-lock.json ]; then \
        npm ci && npm run build; \
    fi \
    && rm -rf node_modules \
    && if [ -f importmap.php ]; then \
        bin/console importmap:install \
        && bin/console asset-map:compile; \
    fi \
    && if [ -f bin/console ]; then \
        bin/console cache:warmup; \
    fi

FROM php-base AS php

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

ENV APP_ENV=prod
ENV APP_DEBUG=0

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        "php${PHP_VERSION}-fpm" \
        libfcgi-bin \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
    && rm -r "/etc/php/${PHP_VERSION}/fpm/pool.d/"

# Same php-fpm pool as the dev "frontend" stage, plus the production-only settings
COPY php/fpm/php-fpm.conf /etc/php/${PHP_VERSION}/fpm/php-fpm.conf
RUN phpenmod app-default \
    && phpenmod -s fpm app-fpm \
    && phpenmod app-prod

RUN groupadd -g 1000 app \
    && useradd -M -u 1000 -g 1000 -s /bin/false app \
    && mkdir -p /var/run/php \
    && chown app:app /var/run/php

ARG APP_PATH
WORKDIR ${APP_PATH}

COPY --from=app-build --chown=app:app ${APP_PATH} ${APP_PATH}

# The directories the application writes in at runtime, usually the mount points of
# volumes, from the "writable_dirs" variable of castor.php (e.g. "var/share public/uploads"):
# owned by the application user, so that a volume inherits it when it is first created
ARG WRITABLE_DIRS=""
# hadolint ignore=SC3054
RUN read -ra dirs <<< "${WRITABLE_DIRS}" \
    && if [ "${#dirs[@]}" -gt 0 ]; then \
        mkdir -p "${dirs[@]}" \
        && chown app:app "${dirs[@]}"; \
    fi

USER 1000:1000

HEALTHCHECK --interval=10s --timeout=5s --retries=3 \
    CMD ["sh", "-c", "SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping cgi-fcgi -bind -connect /var/run/php/php-fpm.sock || exit 1"]

CMD ["sh", "-c", "exec /usr/sbin/php-fpm${PHP_VERSION}"]

FROM nginx:1.29-alpine AS nginx

ARG APP_PATH

COPY nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf
RUN sed -i "s#/var/www/application#${APP_PATH}#" /etc/nginx/conf.d/default.conf

COPY --from=app-build ${APP_PATH}/public ${APP_PATH}/public

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --retries=3 \
    CMD ["wget", "-q", "-O", "/dev/null", "http://127.0.0.1:8080/ping"]
