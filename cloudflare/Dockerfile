FROM dunglas/frankenphp:php8.4-bookworm AS base
WORKDIR /app
RUN install-php-extensions pdo_sqlite intl zip opcache pcntl
COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV PUPPETEER_SKIP_DOWNLOAD=1

FROM base AS assets
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY . .
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer dump-autoload --no-dev \
    && cp .env.example .env && php artisan key:generate --force \
    && npm run build \
    && rm .env

FROM base
RUN apt-get update && apt-get install -y --no-install-recommends \
        chromium fonts-liberation fonts-noto-core fonts-noto-color-emoji git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY package.json package-lock.json .npmrc ./
RUN npm ci --omit=dev

COPY . .
COPY --from=assets /app/public/build ./public/build
COPY --from=assets /app/resources/js/actions ./resources/js/actions
COPY --from=assets /app/resources/js/routes ./resources/js/routes
COPY --from=assets /app/resources/js/wayfinder ./resources/js/wayfinder
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs database \
    && touch database/database.sqlite \
    && chmod -R a+rwX storage bootstrap/cache database

# Browsershot renders the app's own URL, so APP_URL must be reachable inside the container.
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://127.0.0.1:8080 \
    DB_CONNECTION=sqlite \
    SESSION_DRIVER=cookie \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER=Monolog\\Formatter\\JsonFormatter \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    BROWSERSHOT_NO_SANDBOX=true \
    SERVER_NAME=:8080

EXPOSE 8080

CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan migrate --force && exec frankenphp php-server --root public/ --listen :8080"]
