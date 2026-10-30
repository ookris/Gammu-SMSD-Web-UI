#!/usr/bin/env bash
# Gammu SMSD Web UI – budowa i instalacja Gammu 1.45.1 ze źródeł (Ubuntu 26.04 ma w repozytorium 1.42.0).
#   sudo deploy/build-gammu.sh            – buduje do /usr/local i przełącza usługę gammu-smsd na nową wersję
#   sudo deploy/build-gammu.sh --revert   – wraca do Gammu z paczki Ubuntu
# Pakiet gammu-smsd z Ubuntu zostaje zainstalowany (jego usługa, konfiguracja i logrotate); usługa dostaje tylko drop-in
# z poleceniem /usr/local/bin/gammu-smsd. Wersja 1.44+ używa schematu bazy 18 – skrypt dodaje brakujące kolumny do bazy gammu.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root" >&2; exit 1; }
VERSION=1.45.1
SHA256=4003b3df5e4815c36eb4b6614c73ea88d83acd7d5791fdc3fbdab0d3c323c707
URL="https://github.com/gammu/gammu/archive/refs/tags/$VERSION.tar.gz"
DROPIN=/etc/systemd/system/gammu-smsd.service.d/gammu-local.conf
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ "${1:-}" = --revert ]; then
    systemctl stop gammu-smsd || true
    rm -f "$DROPIN"
    systemctl daemon-reload
    systemctl start gammu-smsd
    echo "Przywrócono Gammu z paczki: $(/usr/bin/gammu-smsd --version 2>&1 | head -1)."
    echo "Schemat bazy 18 jest zgodny wstecz z panelem; Gammu 1.42 oczekuje wersji 17 – jeśli odmówi startu, ustaw: UPDATE gammu SET Version = 17;"
    exit 0
fi

echo "==> Zależności"
apt-get update -qq
apt-get install -y -qq build-essential cmake pkg-config gettext libmariadb-dev libmariadb-dev-compat libusb-1.0-0-dev libcurl4-openssl-dev gammu-smsd

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
echo "==> Pobieranie $VERSION"
curl -fsSL -o "$WORK/gammu.tgz" "$URL"
echo "$SHA256  $WORK/gammu.tgz" | sha256sum -c - || { echo "Suma kontrolna się nie zgadza – przerywam." >&2; exit 1; }
tar xzf "$WORK/gammu.tgz" -C "$WORK"

echo "==> Budowa (kilka minut)"
cmake -S "$WORK/gammu-$VERSION" -B "$WORK/build" -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local \
    -DREQUIRE_MYSQL=ON -DWITH_BLUETOOTH=OFF -DINSTALL_DOC=OFF >/dev/null
cmake --build "$WORK/build" -j"$(nproc)" >/dev/null
echo "==> Instalacja do /usr/local"
cmake --install "$WORK/build" >/dev/null
ldconfig
/usr/local/bin/gammu-smsd --version | head -1

echo "==> Schemat bazy gammu → 18"
VER=$(mariadb -N -e "SELECT Version FROM gammu.gammu" 2>/dev/null || echo 0)
if [ "$VER" = 17 ]; then
    mariadb gammu -e "
      ALTER TABLE inbox ADD COLUMN IF NOT EXISTS InsertIntoDB timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER ReceivingDateTime,
        ADD COLUMN IF NOT EXISTS MessageID integer unsigned NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS SequencePosition integer NOT NULL DEFAULT 1,
        ADD COLUMN IF NOT EXISTS PartCount integer NOT NULL DEFAULT 1;
      UPDATE inbox SET MessageID = ID WHERE MessageID = 0;
      UPDATE inbox SET InsertIntoDB = UpdatedInDB;
      ALTER TABLE outbox ADD COLUMN IF NOT EXISTS SendDays integer NOT NULL DEFAULT 127;
      UPDATE gammu SET Version = 18;"
    mariadb gammu -e "CREATE INDEX IF NOT EXISTS inbox_message ON inbox (MessageID, SequencePosition); CREATE INDEX IF NOT EXISTS inbox_insert ON inbox (InsertIntoDB);"
    echo "Schemat zmieniony z 17 na 18."
else
    echo "Wersja schematu: $VER (bez zmian)."
fi

echo "==> Usługa gammu-smsd używa /usr/local/bin/gammu-smsd"
install -d "$(dirname "$DROPIN")"
cat >"$DROPIN" <<UNIT
# Dodane przez deploy/build-gammu.sh – Gammu $VERSION zbudowane ze źródeł. Usunięcie pliku (lub build-gammu.sh --revert) wraca do paczki.
[Service]
ExecStart=
ExecStart=/usr/local/bin/gammu-smsd --pid=/run/gammu-smsd.pid --daemon
UNIT
systemctl daemon-reload
systemctl restart gammu-smsd
sleep 3
systemctl is-active gammu-smsd
echo "Gotowe. Sprawdź panel (Pulpit → kontrola zdrowia) i wyślij testowy SMS. Powrót: sudo $APP/deploy/build-gammu.sh --revert"
