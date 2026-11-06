###  ====   NODE   ====  ###
FROM node:22.13-slim AS frontend-build
WORKDIR /var/www/html/frontend
COPY frontend .

RUN npm ci
RUN npm run build

FROM node:22.13-slim AS frontend
WORKDIR /var/www/html/frontend
COPY --from=frontend-build --chown=node:node /var/www/html/frontend /var/www/html/frontend
USER node

CMD ["npm", "run", "start"]

###  ====   PHP   ====  ###
FROM php:8.3-fpm AS backend-default
ARG APP_ENV='prod'
WORKDIR /var/www/html/backend
COPY --chown=www-data:www-data backend /var/www/html/backend
RUN echo "APP_ENV=${APP_ENV}" > .env

RUN apt-get update && apt-get install -y --no-install-recommends unzip librabbitmq-dev libssl-dev \
    && docker-php-ext-install pdo_mysql \
    && pecl install amqp redis \
    && docker-php-ext-enable amqp redis \
    && rm -rf /var/lib/apt/lists/*

FROM backend-default AS backend-build
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN export COMPOSER_ALLOW_SUPERUSER=1 &&\
    composer install --no-cache --no-dev --optimize-autoloader

FROM backend-default AS backend
COPY --from=backend-build --chown=www-data:www-data /var/www/html/backend /var/www/html/backend
USER www-data

CMD ["php-fpm"]

 ###  ====  NGINX  ====  ###
FROM nginx:latest AS server
COPY --from=backend --chown=www-data:www-data /var/www/html/backend /var/www/html/backend
COPY --from=frontend --chown=www-data:www-data /var/www/html/frontend /var/www/html/frontend
COPY .docker/config/nginx.conf /etc/nginx/conf.d/default.conf

 ###  ====  REDIS  ====  ###
FROM redis:7-alpine AS redis

RUN apk add --no-cache gettext

COPY .docker/config/redis/redis.conf.template /usr/local/etc/redis/redis.conf.template
COPY .docker/config/redis/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["redis-server", "/usr/local/etc/redis/redis.conf"]

 ###  ====  SUPERVISOR  ====  ###
FROM backend AS supervisor
USER root
RUN apt-get update && apt-get install -y supervisor
RUN mkdir -p /var/log/supervisor

CMD ["/usr/bin/supervisord", "-n"]
