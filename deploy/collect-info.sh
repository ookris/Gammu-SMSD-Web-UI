#!/usr/bin/env bash
# Zbieranie informacji o systemie do weryfikacji U1–U10 (rozdz. 3.14) i testu instalatora (zadania 6.1, 6.6).
# Tylko odczyt – niczego nie zmienia. Hasła i PIN są maskowane. Uruchom przed instalacją i po niej:
#
#   sudo bash deploy/collect-info.sh > info-przed.txt 2>&1
#   sudo bash deploy/collect-info.sh > info-po.txt 2>&1
set -uo pipefail

section() { printf '\n===== %s =====\n' "$*"; }
run() { printf '$ %s\n' "$*"; "$@" 2>&1; printf '[kod %s]\n' "$?"; }
have() { command -v "$1" >/dev/null 2>&1; }
# Maskowanie: „password = …” / „pin = …” (gammu-smsdrc, hook.cnf) oraz 'password' => '…' (config.php)
mask() {
    sed -E -e 's/^([[:space:]]*([Pp][Aa][Ss][Ss][Ww][Oo][Rr][Dd]|[Pp][Aa][Ss][Ss]|[Pp][Ii][Nn]|[Pp][Ww][Dd])[[:space:]]*=[[:space:]]*).+$/\1****/' \
        -e "s/('(password|pin)'[[:space:]]*=>[[:space:]]*)'[^']*'/\1'****'/g"
}
show() { if [ -e "$1" ]; then printf -- '--- %s\n' "$1"; mask <"$1"; else printf -- '--- %s: brak pliku\n' "$1"; fi; }

[ "$(id -u)" -eq 0 ] || echo "⚠ Bez roota część informacji będzie niepełna – uruchom przez sudo."
printf 'Gammu SMSD Web UI – collect-info, %s\n' "$(date '+%F %T %z')"

section "System"
run cat /etc/os-release
run uname -a
run timedatectl
run hostname -f

section "Pakiety (dostępne wersje i zainstalowane)"
run apt-cache policy gammu gammu-smsd libgammu-i18n mariadb-server nginx php php-fpm php-cli php-mysql php-mbstring fail2ban
dpkg -l 'gammu*' 'libgammu*' 'libgsmsd*' 'php8*' 'mariadb-server*' 'nginx*' 2>/dev/null | grep -E '^ii' || echo "(nic z tych pakietów nie jest zainstalowane)"

section "U6: PHP"
if have php; then run php -v; php -m | grep -iE '^(pdo_mysql|mbstring|json|openssl)$'; else echo "php: brak"; fi
run ls -l /run/php/

section "U1: usługa gammu-smsd (nazwa, plik, użytkownik, przeładowanie)"
run systemctl list-unit-files 'gammu*'
run systemctl cat gammu-smsd
run systemctl show -p User -p Group -p CanReload -p ExecReload -p Type -p ExecStart -p ActiveState -p UnitFileState gammu-smsd
run systemctl cat 'gammu-smsd@'

section "U2: /etc/gammu-smsdrc (hasło i PIN zamaskowane)"
show /etc/gammu-smsdrc
run ls -l /etc/gammu-smsdrc /etc/gammurc

section "U3: sterowniki SQL wkompilowane w Gammu (native_mysql)"
for f in "$(command -v gammu-smsd 2>/dev/null)" /usr/lib/*/libgsmsd.so* /usr/lib/*/libGammu.so*; do
    [ -n "$f" ] && [ -e "$f" ] || continue
    printf -- '--- %s\n' "$f"
    ldd "$f" 2>/dev/null | grep -iE 'mysql|maria|pq|odbc|dbi' || echo "(brak bibliotek SQL w ldd)"
done
have gammu-smsd && run gammu-smsd --version

section "U4: schemat mysql.sql w paczce"
dpkg -L gammu-smsd 2>/dev/null | grep -iE 'sql|\.gz$' || echo "(paczka gammu-smsd niezainstalowana albo bez plików SQL)"
run ls /usr/share/doc/gammu-smsd/ /usr/share/doc/gammu/

section "U5: log Gammu i logrotate"
run ls -la /var/log/gammu-smsd/
run ls -l /var/log/gammu* /var/log/smsd*
for f in /etc/logrotate.d/gammu*; do [ -e "$f" ] && show "$f"; done
grep -iE '^[[:space:]]*logfile' /etc/gammu-smsdrc 2>/dev/null || echo "(brak logfile w /etc/gammu-smsdrc)"

section "U10: czas i strefa MariaDB"
if have mariadb; then
    run mariadb -N -e "SELECT NOW(), @@global.time_zone, @@system_time_zone, VERSION()"
    run date '+%F %T %Z'
    run mariadb -N -e "SELECT TABLE_SCHEMA, TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ('gammu','smsgui','gammu_test','smsgui_test') ORDER BY 1, 2"
    run mariadb -N gammu -e "SELECT Version FROM gammu"
else
    echo "mariadb: brak"
fi

section "Modem: porty szeregowe i USB"
run ls -l /dev/serial/by-id/
run ls -l /dev/ttyUSB* /dev/ttyACM*
have lsusb && run lsusb
run getent group dialout

section "Panel (po instalacji)"
for d in /opt/smsgui /etc/smsgui /var/lib/smsgui /var/log/gammu-smsd; do run ls -la "$d"; done
show /opt/smsgui/config/config.php
run ls -l /etc/sudoers.d/smsgui /etc/nginx/sites-enabled/ /etc/logrotate.d/gammu-smsd-smsgui /etc/fail2ban/jail.d/smsgui.conf
run cat /etc/systemd/system/gammu-smsd.service.d/smsgui.conf
run systemctl show -p ActiveState -p SubState smsgui-worker gammu-smsd nginx mariadb
run id www-data
if [ -f /opt/smsgui/bin/smsgui ] && have php; then
    run sudo -u www-data php /opt/smsgui/bin/smsgui check
    run git -C /opt/smsgui log --oneline -1
fi

section "Dzienniki (ostatnie linie)"
have journalctl && run journalctl -u gammu-smsd -n 40 --no-pager
have journalctl && run journalctl -u smsgui-worker -n 20 --no-pager
run tail -n 40 /var/log/gammu-smsd/smsd.log
run tail -n 20 /var/lib/smsgui/smsgui.log
run tail -n 20 /var/log/nginx/error.log

printf '\nKoniec.\n'
