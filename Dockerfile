# syntax=docker/dockerfile:1

# ---- Stage 1: install PHP dependencies (cached independently of app code) ----
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs

COPY . .

# --no-scripts: the composer.json post-autoload-dump hook runs
# `artisan package:discover`, which boots the full framework — at build time
# there is no .env and no injected environment variables (docker build never
# sees docker-compose's `environment:` block), so any boot-time config guard
# (e.g. AppServiceProvider's production SEPAY_WEBHOOK_TOKEN check) would fail
# here regardless of what's configured for the real deployment. Defer package
# discovery to the entrypoint, where real runtime env vars are present.
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---- Stage 2: build the user-portal SPA frontend (cached independently of PHP code) ----
# Produces public/build/manifest.json, which resources/views/tool-spa.blade.php's
# @vite(...) directive requires at render time — without this stage, every request
# that renders that view (which, via routes/web.php's Route::fallback(), is nearly
# every non-API request) throws a Vite-manifest exception and 500s.
FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources/js resources/js
COPY resources/scss resources/scss
COPY resources/css resources/css

RUN npm run build

# ---- Stage 3: runtime image (nginx + php-fpm, managed by supervisord) ----
FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        nginx \
        supervisor \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        bcmath \
        zip \
        opcache \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY --from=frontend /app/public/build /var/www/html/public/build

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && mkdir -p /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
