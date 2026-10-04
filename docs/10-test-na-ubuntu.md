# 10. Test na serwerze Ubuntu (zadania 6.1, 6.6, 6.7)

Kolejność: diagnostyka przed instalacją → instalacja → diagnostyka po instalacji → panel w przeglądarce → testy
automatyczne → (po podłączeniu modemu) U7–U9, wiersze do `tests/fixtures/`, lista kontrolna 7.1.
Wyniki z plików `~/*.txt` trafiają do rozdz. 3.14 (U1–U10) i do poprawek instalatora.

Docelowo Ubuntu Server 26.04, czysta maszyna (bez wcześniejszego Gammu, MariaDB i nginx).

## 10.1. Kod

Instalator pobiera domyślnie gałąź `main`; przed wydaniem v1.0 testujemy `dev`. Najpierw wypchnij `dev`
z Maca, potem na serwerze:

```bash
sudo apt-get update && sudo apt-get install -y git
sudo git clone -b dev https://github.com/ookris/Gammu-SMSD-Web-UI.git /opt/smsgui
```

Aktualizacja po poprawkach: `sudo git -C /opt/smsgui pull --ff-only` i ponowne uruchomienie instalatora
(jest idempotentny).

## 10.2. Diagnostyka przed instalacją

```bash
sudo bash /opt/smsgui/deploy/collect-info.sh > ~/info-przed.txt 2>&1
```

Skrypt tylko czyta (hasła i PIN maskuje). Przed instalacją pokaże głównie wersje pakietów dostępnych w Ubuntu
(m.in. czy jest PHP 8.5 – instalator przerwie pracę przy starszym).

## 10.3. Instalacja

```bash
sudo /opt/smsgui/deploy/install.sh 2>&1 | tee ~/install.txt
```

Bez modemu: na pytanie o port odpowiedz `skip` (albo uruchom z `SMSGUI_MODEM=skip`). Pozostałe pytania:
nazwa modemu (Enter = `GSM1`), host panelu, HTTPS (`none` lub `self-signed`), login i hasło administratora.
Po podłączeniu modemu uruchom instalator jeszcze raz – wtedy wykryje port i zapyta o PIN.

## 10.4. Diagnostyka po instalacji i panel

```bash
sudo bash /opt/smsgui/deploy/collect-info.sh > ~/info-po.txt 2>&1
```

W przeglądarce: `http://<adres-serwera>/` – logowanie, pulpit (kontrola zdrowia), „Konfiguracja Gammu” (odczyt
i zapis `gammu-smsdrc` przez okno potwierdzenia, zakładka „Usługa” – przeładowanie i restart), „Log Gammu”,
„Ustawienia panelu” (także zmiana języka). Bez modemu oczekiwane uwagi: modem się nie zgłosił, usługa Gammu może
nie działać (brak portu).

## 10.5. Testy automatyczne

Na osobnych bazach `smsgui_test` i `gammu_test` (czyszczone przez testy), jako root MariaDB przez gniazdo –
produkcyjne bazy `smsgui` i `gammu` nie są używane:

```bash
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS gammu_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE DATABASE IF NOT EXISTS smsgui_test CHARACTER SET utf8mb4 COLLATE utf8mb4_polish_ci"
sudo mariadb gammu_test < /opt/smsgui/deploy/sql/gammu-mysql-17.sql
echo "<?php return ['db' => ['user' => 'root', 'password' => '']];" | sudo tee /root/smsgui-test.php >/dev/null
sudo SMSGUI_CONFIG=/root/smsgui-test.php php /opt/smsgui/tests/run.php 2>&1 | tee ~/testy.txt
```

Opcjonalnie test licznika SMS w JS (wymaga Node.js): `sudo apt-get install -y nodejs && node /opt/smsgui/tests/js/run.mjs`.

Sprzątanie po testach: `sudo mariadb -e "DROP DATABASE gammu_test; DROP DATABASE smsgui_test"`,
`sudo rm -rf /opt/smsgui/var/test /root/smsgui-test.php`.

## 10.6. Z modemem

1. `sudo /opt/smsgui/deploy/install.sh` – wykrycie portu, PIN, testowy SMS na podany numer.
2. U7–U9 (rozdz. 3.14): numer na czarnej liście i przeładowanie, `*101#` i odrzucenie połączenia testowego,
   format numeru nadawcy w odebranym SMS.
3. Ponownie `collect-info.sh > ~/info-modem.txt`.
4. Lista kontrolna z rozdz. 7.1.
5. Wiersze z tabel Gammu do `tests/fixtures/` (zadanie 6.1) – sposób zrzutu ustalimy po pierwszych wysyłkach.
