#!/bin/sh
set -eu

# Grant only the dedicated test database. Schema creation remains with Doctrine.
case "${MYSQL_DATABASE:?MYSQL_DATABASE is required}" in
  *[!a-zA-Z0-9_]*|'') echo 'Unsafe local database name.' >&2; exit 1 ;;
esac
case "${MYSQL_USER:?MYSQL_USER is required}" in
  *[!a-zA-Z0-9_]*|'') echo 'Unsafe local database user.' >&2; exit 1 ;;
esac
test_database="${MYSQL_DATABASE}_test"
# MySQL 8.0 database grants treat underscores as wildcards unless escaped.
test_database_grant=$(printf '%s' "$test_database" | sed 's/_/\\_/g')
MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}" mysql -u root <<SQL
GRANT ALL PRIVILEGES ON \`${test_database_grant}\`.* TO '${MYSQL_USER}'@'%';
SQL
