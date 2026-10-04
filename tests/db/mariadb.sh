#!/usr/bin/env bash
# Lokalna instancja MariaDB do testów (Mac, Homebrew mariadb@11.8) – obok ewentualnego MySQL:
# własny port 3307, gniazdo i dane w ~/.local/share/smsgui-mariadb (ścieżka bez spacji – mariadb-install-db
# nie obsługuje spacji), własny plik opcji (nie czyta /opt/homebrew/etc/my.cnf).
#   tests/db/mariadb.sh start | stop | status | client [baza]
set -euo pipefail
BIN="${MARIADB_BIN:-$(brew --prefix mariadb@11.8 2>/dev/null)/bin}"
DIR="${MARIADB_DIR:-$HOME/.local/share/smsgui-mariadb}"
PORT="${MARIADB_PORT:-3307}"
CNF="$DIR/my.cnf"
SOCK="$DIR/mysqld.sock"

init() {
    mkdir -p "$DIR/data"
    cat >"$CNF" <<CNF
[mariadbd]
datadir = "$DIR/data"
socket = "$SOCK"
pid-file = "$DIR/mariadbd.pid"
log-error = "$DIR/error.log"
port = $PORT
bind-address = 127.0.0.1
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
innodb_buffer_pool_size = 128M

[client]
socket = "$SOCK"
port = $PORT
CNF
    if [ ! -d "$DIR/data/mysql" ]; then
        "$BIN/mariadb-install-db" --defaults-file="$CNF" --auth-root-authentication-method=normal --skip-test-db >/dev/null
        echo "Zainicjalizowano katalog danych: $DIR/data"
    fi
}

running() { [ -f "$DIR/mariadbd.pid" ] && kill -0 "$(cat "$DIR/mariadbd.pid")" 2>/dev/null; }

case "${1:-status}" in
    start)
        init
        if running; then echo "MariaDB już działa (port $PORT)"; exit 0; fi
        "$BIN/mariadbd-safe" --defaults-file="$CNF" >/dev/null 2>&1 &
        for _ in $(seq 1 30); do
            "$BIN/mariadb-admin" --defaults-file="$CNF" -uroot ping >/dev/null 2>&1 && { echo "MariaDB $("$BIN/mariadb" --defaults-file="$CNF" -uroot -N -e 'SELECT VERSION()') działa na 127.0.0.1:$PORT"; exit 0; }
            sleep 1
        done
        echo "MariaDB nie wystartowała – zobacz $DIR/error.log" >&2; exit 1 ;;
    stop)
        running && "$BIN/mariadb-admin" --defaults-file="$CNF" -uroot shutdown && echo "Zatrzymano" || echo "Nie działa" ;;
    status)
        running && echo "działa (port $PORT, PID $(cat "$DIR/mariadbd.pid"))" || { echo "nie działa"; exit 3; } ;;
    client)
        shift; exec "$BIN/mariadb" --defaults-file="$CNF" -uroot "$@" ;;
    *) echo "Użycie: $0 start|stop|status|client [baza]" >&2; exit 1 ;;
esac
