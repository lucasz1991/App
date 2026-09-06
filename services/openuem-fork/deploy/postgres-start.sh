#!/bin/sh
# No set -x, env secrets, password arguments or automatic empty-volume creation.
set -eu
umask 077
data=/var/lib/postgresql/data
password=/run/railtime-secrets/postgres-bootstrap-password
bin=/usr/lib/postgresql/17/bin
fail() { echo "OpenUEM PostgreSQL: $1" >&2; exit 1; }
[ "$(id -u)" = 54321 ] || fail 'must run as dedicated container UID54321'
[ "$(id -un)" = postgres ] || fail 'explicit postgres NSS identity mapping required'
[ -d "$data" ] && [ ! -L "$data" ] || fail 'explicit existing data directory required'
[ "$(stat -c %u "$data")" = 54321 ] || fail 'unexpected data owner'
[ "$(stat -c %a "$data")" = 700 ] || fail 'data directory must be0700'
case "${1:-serve}" in
  init)
    [ "$#" = 1 ] || fail 'init accepts no extra arguments'
    [ -z "$(find "$data" -mindepth 1 -maxdepth 1 -print -quit)" ] || fail 'init refuses nonempty data directory'
    [ -f "$password" ] && [ ! -L "$password" ] || fail 'operator-provided password file required'
    [ "$(stat -c %u "$password")" = 54321 ] || fail 'password must be owned by UID54321'
    [ "$(stat -c %h "$password")" = 1 ] || fail 'hardlinked password rejected'
    case "$(stat -c %a "$password")" in 400|600) ;; *) fail 'password must be0400 or0600' ;; esac
    [ "$(wc -c < "$password")" -ge 24 ] || fail 'operator password file must contain at least24bytes'
    exec "$bin/initdb" --pgdata="$data" --username=postgres --pwfile="$password" \
      --auth-local=peer --auth-host=scram-sha-256 --encoding=UTF8 --locale=C.UTF-8 --data-checksums
    ;;
  serve)
    [ "$#" -le 1 ] || fail 'serve accepts no extra arguments'
    [ -f "$data/PG_VERSION" ] && [ ! -L "$data/PG_VERSION" ] || fail 'not initialized; explicit reviewed init is required'
    [ "$(cat "$data/PG_VERSION")" = 17 ] || fail 'only PostgreSQL major17 is accepted'
    exec "$bin/postgres" -D "$data" -c config_file=/etc/railtime/postgresql.conf
    ;;
  *) fail 'only explicit init or serve is supported' ;;
esac
