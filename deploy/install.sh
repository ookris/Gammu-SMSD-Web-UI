#!/usr/bin/env bash
# Gammu SMSD Web UI – instalacja na Ubuntu Server 26.04 (rozdz. 6.3). Idempotentny: ponowne uruchomienie
# aktualizuje kod, naprawia uprawnienia i nie pyta ponownie o modem ani konto, jeśli już są.
#
#   curl -fsSL https://raw.githubusercontent.com/ookris/Gammu-SMSD-Web-UI/main/deploy/install.sh | sudo bash
#   sudo ./deploy/install.sh                       (z pobranego repozytorium)
#
# Bez pytań – zmienne: SMSGUI_MODEM (ścieżka lub skip), SMSGUI_PIN, SMSGUI_PHONEID, SMSGUI_HOST, SMSGUI_HTTPS (none/self-signed),
# SMSGUI_USER, SMSGUI_PASSWORD, SMSGUI_TEST_NUMBER; pobieranie: SMSGUI_DIR, SMSGUI_REPO, SMSGUI_BRANCH.
#
# ⚠ Część wartości zależy od paczki Ubuntu (U1–U10, rozdz. 3.14) – skrypt je wykrywa zamiast zakładać.
set -euo pipefail

REPO="${SMSGUI_REPO:-https://github.com/ookris/Gammu-SMSD-Web-UI.git}"
BRANCH="${SMSGUI_BRANCH:-main}"
DIR="${SMSGUI_DIR:-/opt/smsgui}"
DATA=/var/lib/smsgui
ETC=/etc/smsgui
CONF=/etc/gammu-smsdrc
GAMMU_LOG=/var/log/gammu-smsd/smsd.log

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[33m  ⚠ %s\033[0m\n' "$*"; }
die()  { printf '\033[31m  ✘ %s\033[0m\n' "$*" >&2; exit 1; }

# Pytanie z wartością domyślną; czyta z terminala także przy „curl | bash”. ask ZMIENNA "Pytanie" "domyślnie" [ukryte]
ask() {
    local var=$1 prompt=$2 def=${3:-} hidden=${4:-} answer=''
    if [ -n "${!var:-}" ]; then return; fi
    if [ -r /dev/tty ]; then
        if [ -n "$hidden" ]; then read -r -s -p "    $prompt: " answer </dev/tty; echo >/dev/tty
        else read -r -p "    $prompt${def:+ [$def]}: " answer </dev/tty; fi
    fi
    printf -v "$var" '%s' "${answer:-$def}"
}
randpw() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32; }

[ "$(id -u)" -eq 0 ] || die "Uruchom jako root: sudo $0"

# ---------- 1. Pobranie aplikacji ----------
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo /nonexistent)"
if [ ! -f "$SELF_DIR/../src/bootstrap.php" ]; then
    say "Pobieranie aplikacji do $DIR"
    apt-get update -qq && apt-get install -y -qq git ca-certificates >/dev/null
    if [ -d "$DIR/.git" ]; then git -C "$DIR" pull --ff-only; else git clone --branch "$BRANCH" "$REPO" "$DIR"; fi
    exec bash "$DIR/deploy/install.sh" "$@"
fi
APP="$(cd "$SELF_DIR/.." && pwd)"
info "Aplikacja: $APP"

# ---------- 2. Pakiety ----------
say "Pakiety systemowe"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq gammu gammu-smsd mariadb-server nginx php-fpm php-cli php-mysql php-mbstring openssl >/dev/null
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
php -r 'exit(version_compare(PHP_VERSION, "8.5.0", ">=") ? 0 : 1);' || die "Wymagane PHP 8.5 lub nowsze (jest $PHPV)"
GAMMUV=$(gammu --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -1 || true)
info "PHP $PHPV, Gammu ${GAMMUV:-?}"
FPM_SOCK=$(ls /run/php/php"$PHPV"-fpm.sock 2>/dev/null || ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || echo "/run/php/php$PHPV-fpm.sock")
systemctl enable --now mariadb >/dev/null 2>&1 || true

# ---------- 3. Baza danych ----------
say "Bazy MariaDB"
install -d -m 0750 "$ETC"
SECRETS="$ETC/secrets.env"           # hasła kont bazy (tylko root) – przy ponownym uruchomieniu te same
[ -f "$SECRETS" ] && . "$SECRETS"
GAMMU_DB_PASSWORD="${GAMMU_DB_PASSWORD:-$(randpw)}"
SMSGUI_DB_PASSWORD="${SMSGUI_DB_PASSWORD:-$(randpw)}"
HOOK_DB_PASSWORD="${HOOK_DB_PASSWORD:-$(randpw)}"
umask 077
printf 'GAMMU_DB_PASSWORD=%s\nSMSGUI_DB_PASSWORD=%s\nHOOK_DB_PASSWORD=%s\n' "$GAMMU_DB_PASSWORD" "$SMSGUI_DB_PASSWORD" "$HOOK_DB_PASSWORD" >"$SECRETS"
umask 022
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS gammu  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS smsgui CHARACTER SET utf8mb4 COLLATE utf8mb4_polish_ci;
CREATE USER IF NOT EXISTS 'gammu'@'localhost' IDENTIFIED BY '$GAMMU_DB_PASSWORD';
CREATE USER IF NOT EXISTS 'smsgui'@'localhost' IDENTIFIED BY '$SMSGUI_DB_PASSWORD';
CREATE USER IF NOT EXISTS 'smsgui_hook'@'localhost' IDENTIFIED BY '$HOOK_DB_PASSWORD';
ALTER USER 'gammu'@'localhost' IDENTIFIED BY '$GAMMU_DB_PASSWORD';
ALTER USER 'smsgui'@'localhost' IDENTIFIED BY '$SMSGUI_DB_PASSWORD';
ALTER USER 'smsgui_hook'@'localhost' IDENTIFIED BY '$HOOK_DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON gammu.* TO 'gammu'@'localhost';
GRANT ALL ON smsgui.* TO 'smsgui'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON gammu.* TO 'smsgui'@'localhost';
FLUSH PRIVILEGES;
SQL
if ! mariadb gammu -e 'SELECT Version FROM gammu' >/dev/null 2>&1; then
    SQLFILE=$(dpkg -L gammu-smsd 2>/dev/null | grep -E '/mysql\.sql(\.gz)?$' | head -1 || true)   # ⚠ U4
    if [ -n "$SQLFILE" ]; then
        info "Schemat Gammu z paczki: $SQLFILE"
        if [[ "$SQLFILE" == *.gz ]]; then zcat "$SQLFILE" | mariadb gammu; else mariadb gammu <"$SQLFILE"; fi
    else
        warn "Nie znaleziono mysql.sql w paczce – używam deploy/sql/gammu-mysql-17.sql"
        mariadb gammu <"$APP/deploy/sql/gammu-mysql-17.sql"
    fi
fi
VER=$(mariadb -N gammu -e 'SELECT Version FROM gammu' 2>/dev/null || echo 0)
[ "$VER" = 17 ] || warn "Schemat bazy Gammu w wersji $VER – panel wymaga 17 (Gammu 1.42)"
for t in gammu inbox outbox outbox_multipart phones sentitems; do   # MyISAM → InnoDB (transakcje, rozdz. 3.2)
    ENG=$(mariadb -N -e "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='gammu' AND TABLE_NAME='$t'")
    if [ -n "$ENG" ] && [ "$ENG" != InnoDB ]; then info "gammu.$t: $ENG → InnoDB"; mariadb gammu -e "ALTER TABLE \`$t\` ENGINE=InnoDB"; fi
done

# ---------- 4. Kod, dane, uprawnienia, config.php ----------
say "Pliki aplikacji i uprawnienia"
chown -R root:root "$APP"
install -d -o www-data -g www-data -m 0750 "$DATA" "$DATA/backups" "$DATA/sessions"
touch "$DATA/exclude-numbers.txt" "$DATA/smsgui.log"   # log tworzony z góry – CLI uruchamiane jako root nie może go przejąć
chown www-data:www-data "$DATA/exclude-numbers.txt" "$DATA/smsgui.log" && chmod 0644 "$DATA/exclude-numbers.txt" && chmod 0640 "$DATA/smsgui.log"
install -d -m 0750 -g www-data /var/log/gammu-smsd
CONFIG="$APP/config/config.php"
if [ ! -f "$CONFIG" ]; then
    cat >"$CONFIG" <<PHP
<?php
// Konfiguracja lokalna – utworzona przez deploy/install.sh ($(date +%F)). Wartości domyślne: config.example.php
return [
    'db' => ['password' => '$SMSGUI_DB_PASSWORD'],
    'hook' => ['command' => '/usr/bin/php $APP/bin/smsgui hook call', 'cnf' => '$ETC/hook.cnf'],
];
PHP
else
    php -r '$c = require $argv[1]; exit(isset($c["db"]["password"]) && $c["db"]["password"] !== "" ? 0 : 1);' "$CONFIG" \
        || warn "config.php bez hasła do bazy – uzupełnij db.password (hasło w $SECRETS)"
fi
chown root:www-data "$CONFIG" && chmod 0640 "$CONFIG"
install -m 0440 "$APP/deploy/sudoers-smsgui" /etc/sudoers.d/smsgui
visudo -cq || die "Błąd w /etc/sudoers.d/smsgui"

# Usługa Gammu: nazwa, użytkownik, przeładowanie (⚠ U1)
GAMMU_USER=$(systemctl show -p User --value gammu-smsd 2>/dev/null || true); GAMMU_USER=${GAMMU_USER:-root}
if [ "$(systemctl show -p CanReload --value gammu-smsd 2>/dev/null || echo no)" != yes ]; then
    info "gammu-smsd.service bez ExecReload – dodaję przeładowanie sygnałem SIGHUP"
    install -d /etc/systemd/system/gammu-smsd.service.d
    printf '[Service]\nExecReload=/bin/kill -HUP $MAINPID\n' >/etc/systemd/system/gammu-smsd.service.d/smsgui.conf
    systemctl daemon-reload
fi
cat >"$ETC/hook.cnf" <<CNF
[client]
user = smsgui_hook
password = $HOOK_DB_PASSWORD
socket = /run/mysqld/mysqld.sock
database = smsgui
CNF
chown root:"$(id -gn "$GAMMU_USER")" "$ETC/hook.cnf" && chmod 0640 "$ETC/hook.cnf"
chown root:"$(id -gn "$GAMMU_USER")" "$ETC" && chmod 0750 "$ETC"
chmod 0600 "$SECRETS"

# ---------- 5. Modem i gammu-smsdrc (kreator) ----------
say "Modem i konfiguracja Gammu SMSD"
CUR_DEVICE=$(grep -iE '^\s*device\s*=' "$CONF" 2>/dev/null | head -1 | sed -E 's/^[^=]*=\s*//' || true)
CUR_SERVICE=$(grep -iE '^\s*service\s*=' "$CONF" 2>/dev/null | head -1 | sed -E 's/^[^=]*=\s*//' | tr 'A-Z' 'a-z' || true)
SETUP_ARGS=(--db-user=gammu --db-password="$GAMMU_DB_PASSWORD" --db-name=gammu --logfile="$GAMMU_LOG")
if [[ "$CUR_DEVICE" == /dev/serial/by-id/* && "$CUR_SERVICE" == sql ]]; then
    info "Modem skonfigurowany: $CUR_DEVICE – bez zmian"
else
    systemctl stop gammu-smsd 2>/dev/null || true
    PORTS=(); WORKING=()
    for p in /dev/serial/by-id/*; do
        [ -e "$p" ] || continue
        PORTS+=("$p")
        TMPRC=$(mktemp); printf '[gammu]\ndevice = %s\nconnection = at\n' "$p" >"$TMPRC"
        if ID=$(timeout 20 gammu -c "$TMPRC" identify 2>/dev/null); then
            WORKING+=("$p"); info "✔ $p – $(echo "$ID" | grep -iE 'Manufacturer|Model' | sed -E 's/\s+:\s+/: /' | paste -sd' ' -)"
        else
            info "  $p – brak odpowiedzi"
        fi
        rm -f "$TMPRC"
    done
    DEF_MODEM=${WORKING[0]:-skip}
    [ ${#PORTS[@]} -eq 0 ] && warn "Nie wykryto modemu w /dev/serial/by-id/ – panel zainstaluje się bez niego (podłączysz go później i uruchomisz skrypt ponownie)"
    ask SMSGUI_MODEM "Port modemu (Enter = $DEF_MODEM, 'skip' = pomiń)" "$DEF_MODEM"
    ask SMSGUI_PHONEID "Nazwa modemu (PhoneID)" "GSM1"
    if [ "$SMSGUI_MODEM" != skip ]; then
        TMPRC=$(mktemp); printf '[gammu]\ndevice = %s\nconnection = at\n' "$SMSGUI_MODEM" >"$TMPRC"
        SEC=$(timeout 20 gammu -c "$TMPRC" getsecuritystatus 2>&1 || true)
        if echo "$SEC" | grep -qi 'PUK'; then rm -f "$TMPRC"; die "Karta SIM wymaga kodu PUK – odblokuj ją w telefonie i uruchom skrypt ponownie"; fi
        if echo "$SEC" | grep -qi 'PIN'; then
            ask SMSGUI_PIN "PIN karty SIM" "" hidden
            # PIN sprawdzany jeden raz – przy błędzie przerywamy, żeby demon nie zużył kolejnych prób i nie zablokował karty
            timeout 20 gammu -c "$TMPRC" entersecuritycode PIN "$SMSGUI_PIN" >/dev/null 2>&1 || { rm -f "$TMPRC"; die "Nieprawidłowy PIN – przerwano, żeby nie zablokować karty"; }
            SETUP_ARGS+=(--pin="$SMSGUI_PIN")
        fi
        rm -f "$TMPRC"
        SETUP_ARGS+=(--device="$SMSGUI_MODEM")
    fi
    SETUP_ARGS+=(--phoneid="$SMSGUI_PHONEID")
    if [ -n "$CUR_SERVICE" ] && [ "$CUR_SERVICE" != sql ]; then
        warn "gammu-smsdrc używa service = $CUR_SERVICE – przestawiam na sql (wiadomości z katalogów kolejek nie są przenoszone)"
        SETUP_ARGS+=(--force-sql)
    fi
fi
[ -f "$CONF" ] || touch "$CONF"
php "$APP/bin/smsgui" setup gammu "${SETUP_ARGS[@]}"
chown root:www-data "$CONF" && chmod 0660 "$CONF"
touch "$GAMMU_LOG" && chown root:www-data "$GAMMU_LOG" && chmod 0640 "$GAMMU_LOG"
install -m 0644 "$APP/deploy/logrotate-gammu-smsd" /etc/logrotate.d/gammu-smsd-smsgui
systemctl enable gammu-smsd >/dev/null 2>&1 || true
systemctl restart gammu-smsd || warn "Gammu nie wystartował – sprawdź: journalctl -u gammu-smsd i $GAMMU_LOG"

# ---------- 6. Panel: baza, proces w tle, nginx, konto ----------
say "Tabele panelu i proces w tle"
sudo -u www-data php "$APP/bin/smsgui" setup db
mariadb -e "GRANT INSERT (phone, raw_number, modem, received_at) ON smsgui.calls TO 'smsgui_hook'@'localhost'; FLUSH PRIVILEGES;"
sed "s#__ROOT__#$APP#g" "$APP/deploy/smsgui-worker.service" >/etc/systemd/system/smsgui-worker.service
systemctl daemon-reload
systemctl enable --now smsgui-worker >/dev/null
systemctl restart smsgui-worker

say "Serwer WWW"
ask SMSGUI_HOST "Nazwa hosta panelu" "$(hostname -f 2>/dev/null || hostname)"
ask SMSGUI_HTTPS "HTTPS (none / self-signed)" "none"
sed -e "s#__ROOT__#$APP#g" -e "s#__FPM__#$FPM_SOCK#g" -e "s#__HOST__#$SMSGUI_HOST#g" "$APP/deploy/nginx-smsgui.conf" >/etc/nginx/sites-available/smsgui
if [ "$SMSGUI_HTTPS" = self-signed ]; then
    if [ ! -f /etc/ssl/private/smsgui.key ]; then
        openssl req -x509 -nodes -newkey rsa:2048 -days 3650 -subj "/CN=$SMSGUI_HOST" \
            -keyout /etc/ssl/private/smsgui.key -out /etc/ssl/certs/smsgui.crt >/dev/null 2>&1
    fi
    sed -i -e 's#listen 80;#listen 443 ssl;\n    ssl_certificate /etc/ssl/certs/smsgui.crt;\n    ssl_certificate_key /etc/ssl/private/smsgui.key;#' \
        -e 's#listen \[::\]:80;#listen [::]:443 ssl;#' /etc/nginx/sites-available/smsgui
    printf 'server { listen 80; listen [::]:80; server_name %s; return 301 https://$host$request_uri; }\n' "$SMSGUI_HOST" >>/etc/nginx/sites-available/smsgui
fi
ln -sf /etc/nginx/sites-available/smsgui /etc/nginx/sites-enabled/smsgui
rm -f /etc/nginx/sites-enabled/default
nginx -t -q && systemctl reload nginx
if [ -d /etc/fail2ban ]; then
    install -m 0644 "$APP/deploy/fail2ban/filter.d/smsgui.conf" /etc/fail2ban/filter.d/smsgui.conf
    [ -f /etc/fail2ban/jail.d/smsgui.conf ] || install -m 0644 "$APP/deploy/fail2ban/jail.d/smsgui.conf" /etc/fail2ban/jail.d/smsgui.conf
fi

if [ "$(mariadb -N smsgui -e 'SELECT COUNT(*) FROM users')" = 0 ]; then
    say "Konto administratora panelu"
    ask SMSGUI_USER "Login" "admin"
    while [ -z "${SMSGUI_PASSWORD:-}" ] || [ ${#SMSGUI_PASSWORD} -lt 10 ]; do
        SMSGUI_PASSWORD=''
        ask SMSGUI_PASSWORD "Hasło (co najmniej 10 znaków)" "" hidden
        [ -r /dev/tty ] || break
    done
    SMSGUI_USER="$SMSGUI_USER" SMSGUI_PASSWORD="$SMSGUI_PASSWORD" sudo -E -u www-data php "$APP/bin/smsgui" passwd "$SMSGUI_USER"
fi

say "Diagnostyka"
sleep 5
sudo -u www-data php "$APP/bin/smsgui" check || warn "Diagnostyka zgłosiła problemy – podpowiedzi powyżej"

# ---------- 7. Testowy SMS ----------
if [ "${SMSGUI_MODEM:-}" != skip ]; then
    ask SMSGUI_TEST_NUMBER "Numer do testowego SMS (Enter = pomiń)" ""
    if [ -n "$SMSGUI_TEST_NUMBER" ]; then
        sudo -u www-data php "$APP/bin/smsgui" send "$SMSGUI_TEST_NUMBER" "Test bramki SMS – Gammu SMSD Web UI działa." --wait=90 || warn "Testowy SMS nie został wysłany – zobacz log Gammu"
    fi
fi

# ---------- 8. Podsumowanie ----------
IP=$(hostname -I 2>/dev/null | awk '{print $1}')
SCHEME=http; [ "$SMSGUI_HTTPS" = self-signed ] && SCHEME=https
say "Gotowe"
info "Panel: $SCHEME://$SMSGUI_HOST/  (lub $SCHEME://$IP/) – dostęp tylko z sieci lokalnej"
info "Zapomniane hasło: sudo -u www-data php $APP/bin/smsgui passwd"
info "Hasła kont bazy: $SECRETS (tylko root)"
