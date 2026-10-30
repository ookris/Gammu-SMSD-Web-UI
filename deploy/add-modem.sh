#!/usr/bin/env bash
# Gammu SMSD Web UI – dodanie drugiego (kolejnego) modemu.
#   sudo deploy/add-modem.sh <PhoneID> </dev/serial/by-id/…> [--pin=1234]
# Tworzy /etc/gammu-smsdrc-<PhoneID> na podstawie /etc/gammu-smsdrc (inne urządzenie, PhoneID i log), instaluje szablon
# usługi gammu-smsd@.service i uruchamia gammu-smsd@<PhoneID>. Wszystkie modemy zapisują do tej samej bazy gammu,
# a panel pokazuje wybór modemu w formularzach, gdy zgłosi się ich więcej niż jeden.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root" >&2; exit 1; }
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ID="${1:-}"; DEVICE="${2:-}"; shift 2 2>/dev/null || true
[[ "$ID" =~ ^[A-Za-z0-9_-]{1,20}$ && -n "$DEVICE" ]] || { echo "Użycie: sudo $0 <PhoneID> <urządzenie> [--pin=…]" >&2; exit 1; }
[ -e "$DEVICE" ] || { echo "Brak urządzenia $DEVICE" >&2; exit 1; }
[ -f /etc/gammu-smsdrc ] || { echo "Najpierw zainstaluj panel (brak /etc/gammu-smsdrc)" >&2; exit 1; }

# kod 2 = konfiguracja tego modemu już istnieje (ponowne uruchomienie skryptu) – reszta kroków jest idempotentna
RC=0; php "$APP/bin/smsgui" setup modem --phoneid="$ID" --device="$DEVICE" "$@" || RC=$?
[ "$RC" -eq 0 ] || [ "$RC" -eq 2 ] || exit "$RC"
install -m 0644 "$APP/deploy/gammu-smsd@.service" /etc/systemd/system/gammu-smsd@.service
GAMMU_USER=$(systemctl show -p User --value gammu-smsd 2>/dev/null || true)
[ -z "$GAMMU_USER" ] || [ "$GAMMU_USER" = root ] || chgrp www-data "/etc/gammu-smsdrc-$ID"
systemctl daemon-reload
systemctl enable --now "gammu-smsd@$ID"
echo "Gotowe. Stan: systemctl status gammu-smsd@$ID · log: /var/log/gammu-smsd/$ID.log"
echo "Modem pojawi się w panelu po kilkunastu sekundach (tabela phones)."
