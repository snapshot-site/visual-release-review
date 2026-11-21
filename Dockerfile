# syntax=docker/dockerfile:1
FROM node:22-alpine@sha256:1314fb30ec37592d5845e5a160d522c0e680972aa534549940b8d73bde88481a AS node_dependencies
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund

FROM dunglas/frankenphp:1-php8.4-alpine@sha256:f81693fa548684eb12964eeab78d86325b75c5cd91289c09ffc4dd4404a641e5 AS php_base
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions intl zip \
    && apk upgrade --no-cache \
    && apk add --no-cache libstdc++ libgcc libatomic
COPY --from=node_dependencies /usr/local/bin/node /usr/local/bin/node
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /app

FROM php_base AS php_dependencies
COPY --from=composer:2@sha256:50ea4d33fdcde1adb0f045547295faa1548b33872e5f789dae5b02ce2fb5de93 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
COPY src/ src/
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist \
    --no-interaction --no-progress --no-scripts --classmap-authoritative \
    && composer check-platform-reqs --no-dev

FROM php_base AS production
ENV APP_ENV=prod APP_DEBUG=0 \
    TRUSTED_HOSTS="^(localhost|127\\.0\\.0\\.1|visual-review\\.snapshot-site\\.com)$" \
    TRUSTED_PROXIES="" \
    XDG_CONFIG_HOME=/app/var/config XDG_DATA_HOME=/app/var/data
RUN rm -rf /usr/src/php* /usr/local/include/php \
    && rm -f /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/install-php-extensions \
    && setcap -r /usr/local/bin/frankenphp \
    && addgroup -g 10001 app \
    && adduser -D -u 10001 -G app app \
    && mkdir -p /app/var/cache /app/var/log /app/var/sessions /app/var/config /app/var/data \
    && chown -R app:app /app/var
COPY --from=php_dependencies /app/vendor/ vendor/
COPY --from=node_dependencies /build/node_modules/ node_modules/
COPY .env composer.json composer.lock ./
COPY bin/console bin/console
COPY config/bundles.php config/bundles.php
COPY src/ src/
COPY templates/ templates/
COPY translations/ translations/
COPY public/ public/
RUN rm -f public/router.php
COPY docker/health.php health/index.php
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/app-entrypoint
USER app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1:8080/healthz || exit 1
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
