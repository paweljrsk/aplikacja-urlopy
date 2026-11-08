# Refresh pinned runtime digests deliberately, together with smoke tests.
FROM node:22.23.4-bookworm-slim@sha256:5ec23b7c622b00547753bc6ffd78085dd868a68aeb41c8e0a1418f033e13d96d AS frontend-dev
WORKDIR /var/www/html/frontend
RUN chown node:node /var/www/html/frontend
ENV NEXT_TELEMETRY_DISABLED=1 NPM_CONFIG_CACHE=/tmp/npm-cache
USER node
CMD ["npm", "run", "dev", "--", "--hostname", "0.0.0.0"]

FROM frontend-dev AS frontend-build
COPY --chown=node:node frontend .
RUN npm ci && npm run build

FROM frontend-dev AS frontend
COPY --from=frontend-build --chown=node:node /var/www/html/frontend /var/www/html/frontend
ENV NODE_ENV=production
CMD ["npm", "run", "start", "--", "--hostname", "0.0.0.0"]

FROM composer:2@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer-bin

FROM php:8.3-fpm@sha256:03af352853b05e35ee15ab898a2755532573684c7d1120b246dbd4e3c90f5007 AS backend-default
WORKDIR /var/www/html/backend
RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip librabbitmq-dev libssl-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring \
    && pecl install amqp-2.2.0 redis-6.3.0 \
    && docker-php-ext-enable amqp redis \
    && rm -rf /var/lib/apt/lists/*

FROM backend-default AS backend-dev
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_HOME=/tmp/composer
CMD ["php-fpm"]

FROM backend-dev AS backend-build
ENV APP_ENV=prod APP_DEBUG=0
COPY backend .
# Compile dependencies without production secrets or runtime services.
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-dev --prefer-dist --no-scripts --optimize-autoloader

FROM backend-default AS backend
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=backend-build --chown=www-data:www-data /var/www/html/backend /var/www/html/backend
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var
USER www-data
CMD ["php-fpm"]

FROM nginx:stable-alpine@sha256:583b7f2fce1cdb8da5ad5c39ebd3c33b98b87d493190e548bdee1fa8c619c311 AS server-dev
COPY .docker/config/nginx.conf /etc/nginx/conf.d/default.conf

FROM server-dev AS server
COPY --from=backend --chown=nginx:nginx /var/www/html/backend/public /var/www/html/backend/public

FROM redis:7-alpine@sha256:c5d34a98396a4775e223c74e929162c35c5a2182901ba26b50ade939941ce14b AS redis
RUN apk add --no-cache gettext
COPY .docker/config/redis/redis.conf.template /usr/local/etc/redis/redis.conf.template
COPY .docker/config/redis/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["redis-server", "/usr/local/etc/redis/redis.conf"]

FROM backend-dev AS supervisor-dev
RUN apt-get update && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*
COPY .docker/config/supervisord.conf /etc/supervisor/supervisord.conf
COPY .docker/config/supervisor /etc/supervisor/conf.d
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]

FROM backend AS supervisor
USER root
RUN apt-get update && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*
COPY .docker/config/supervisord.conf /etc/supervisor/supervisord.conf
COPY .docker/config/supervisor /etc/supervisor/conf.d
USER www-data
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
