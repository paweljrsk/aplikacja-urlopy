#!/bin/sh
set -eu
: "${REDIS_PORT:?REDIS_PORT is required}"
: "${REDIS_PASSWORD:?REDIS_PASSWORD is required}"
envsubst '${REDIS_PORT} ${REDIS_PASSWORD}' < /usr/local/etc/redis/redis.conf.template > /usr/local/etc/redis/redis.conf
# Preserve the official image's privilege drop and ownership initialization.
exec /usr/local/bin/docker-entrypoint.sh "$@"
