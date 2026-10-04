#!/usr/bin/env bash
# Gammu SMSD Web UI – odinstalowanie. Gammu SMSD i baza gammu działają dalej.
#   sudo deploy/uninstall.sh            – usuwa panel, zostawia dane (/var/lib/smsgui, baza smsgui)
#   sudo deploy/uninstall.sh --purge    – także dane panelu, bazę smsgui i konta smsgui / smsgui_hook
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root" >&2; exit 1; }
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PURGE=0; [ "${1:-}" = --purge ] && PURGE=1

systemctl disable --now smsgui-worker 2>/dev/null || true
rm -f /etc/systemd/system/smsgui-worker.service /etc/systemd/system/gammu-smsd.service.d/smsgui.conf
systemctl daemon-reload
rm -f /etc/nginx/sites-enabled/smsgui /etc/nginx/sites-available/smsgui
nginx -t -q 2>/dev/null && systemctl reload nginx || true
rm -f /etc/sudoers.d/smsgui /etc/logrotate.d/gammu-smsd-smsgui /etc/fail2ban/filter.d/smsgui.conf /etc/fail2ban/jail.d/smsgui.conf

# Usunięcie z gammu-smsdrc tylko ustawień dodanych przez panel (hook połączeń, czarna lista panelu) – parserem panelu,
# z kopią 0600; wartości ustawione przez kogoś innego zostają
if [ -f /etc/gammu-smsdrc ]; then
    BEFORE=$(md5sum /etc/gammu-smsdrc)
    php "$APP/bin/smsgui" setup unhook || echo "Nie udało się zmienić /etc/gammu-smsdrc – sprawdź go ręcznie" >&2
    [ "$BEFORE" = "$(md5sum /etc/gammu-smsdrc)" ] || systemctl restart gammu-smsd || true
fi

if [ $PURGE -eq 1 ]; then
    mariadb -e "DROP DATABASE IF EXISTS smsgui; DROP USER IF EXISTS 'smsgui'@'localhost'; DROP USER IF EXISTS 'smsgui_hook'@'localhost';"
    rm -rf /var/lib/smsgui /etc/smsgui
fi
rm -rf "$APP"
echo "Odinstalowano.$([ $PURGE -eq 1 ] && echo ' Dane panelu usunięte.') Kopie /etc/gammu-smsdrc.smsgui-* zostały."
