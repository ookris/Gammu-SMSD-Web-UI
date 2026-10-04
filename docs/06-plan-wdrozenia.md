# 6. Plan wdrożenia (Ubuntu Server LTS)

Cel: instalacja **jednym poleceniem** na czystym serwerze – także przez osobę, która nie zna Gammu, MariaDB
ani nginx. Skrypt `deploy/install.sh` pobiera aplikację, instaluje Gammu SMSD i MariaDB, pomaga skonfigurować
modem i panel, a na końcu wysyła testowy SMS. Po instalacji wszystko obsługuje się z przeglądarki (D20).
Każdy krok jest opisany poniżej tak, żeby dało się go wykonać ręcznie i zrozumieć.

```bash
curl -fsSL https://raw.githubusercontent.com/ookris/Gammu-SMSD-Web-UI/main/deploy/install.sh | sudo bash
```

## 6.1. Wymagania

| Element | Wymaganie |
|---------|-----------|
| System | Ubuntu Server LTS 26.04 (PHP ≥ 8.5 i Gammu 1.42.0 z repozytorium systemu; starsze wydania Ubuntu nie są wspierane) |
| Sprzęt | modem GSM USB/szeregowy z aktywną kartą SIM (PIN wyłączony lub znany) |
| Pakiety | `gammu`, `gammu-smsd`, `mariadb-server`, `nginx`, `php-fpm`, `php-cli`, `php-mysql`, `php-mbstring`, `git` (instaluje je skrypt) |
| Opcjonalnie | w rozszerzeniach: `msmtp-mta` (przekazywanie na e-mail, jeśli nie przez SMTP z panelu) |

## 6.2. Kroki instalacji

### Krok 1 – modem i Gammu
```bash
sudo apt update
sudo apt install gammu gammu-smsd
ls -l /dev/serial/by-id/              # stała nazwa modemu (lepsza niż /dev/ttyUSB0)
gammu-detect                          # porty, które Gammu rozpoznaje
```
Test modemu bez demona (plik tymczasowy `~/.gammurc` z `device = /dev/serial/by-id/…`, `connection = at`):
```bash
gammu identify                        # producent, model, IMEI
gammu getsecuritystatus               # czy karta wymaga PIN
gammu networkinfo                     # operator, sieć
```
Modem USB ma zwykle kilka portów – właściwy to ten, na którym `gammu identify` się udaje.

### Krok 2 – baza danych
```bash
sudo apt install mariadb-server
sudo mariadb <<'SQL'
CREATE DATABASE gammu  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE smsgui CHARACTER SET utf8mb4 COLLATE utf8mb4_polish_ci;
CREATE USER 'gammu'@'localhost'  IDENTIFIED BY '<hasło1>';
CREATE USER 'smsgui'@'localhost' IDENTIFIED BY '<hasło2>';
CREATE USER 'smsgui_hook'@'localhost' IDENTIFIED BY '<hasło3>';
GRANT SELECT, INSERT, UPDATE, DELETE ON gammu.* TO 'gammu'@'localhost';
GRANT ALL ON smsgui.* TO 'smsgui'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON gammu.* TO 'smsgui'@'localhost';
SQL
zcat /usr/share/doc/gammu-smsd/examples/mysql.sql.gz | sudo mariadb gammu   # ⚠ U4 – ścieżka do potwierdzenia
for t in gammu inbox outbox outbox_multipart phones sentitems; do
  sudo mariadb gammu -e "ALTER TABLE $t ENGINE=InnoDB"
done
```
Uprawnienie `smsgui_hook` do tabeli `smsgui.calls` nadaje instalator (kontem `root` MariaDB) po utworzeniu
tabel panelu (krok 7) – konto `smsgui` nie ma prawa `GRANT`.
Hasła generuje instalator (losowe, 32 znaki); trafiają tylko do `gammu-smsdrc`, `config.php` i `hook.cnf`.

### Krok 3 – konfiguracja Gammu SMSD
`/etc/gammu-smsdrc` (minimum potrzebne panelowi):
```ini
[gammu]
device = /dev/serial/by-id/usb-…-if00-port0
connection = at

[smsd]
service = sql
driver = native_mysql
host = localhost
user = gammu
password = <hasło1>
database = gammu
phoneid = GSM1
pin = 1234
logfile = /var/log/gammu-smsd/smsd.log
debuglevel = 1
deliveryreportdelay = 172800
maxretries = 2
retrytimeout = 120
```
```bash
sudo install -d -m 0750 -g www-data /var/log/gammu-smsd
sudo chown root:www-data /etc/gammu-smsdrc && sudo chmod 0660 /etc/gammu-smsdrc
sudo systemctl enable --now gammu-smsd
tail -f /var/log/gammu-smsd/smsd.log
```
Test bez panelu:
```bash
sudo gammu-smsd-inject TEXT +48601234567 -text 'Test'     # zapis do outbox
sudo mariadb gammu -e 'SELECT ID, Status FROM sentitems ORDER BY ID DESC LIMIT 1'
sudo mariadb gammu -e 'SELECT ID, Signal, NetName FROM phones'
```
**Zasada:** najpierw działa Gammu, dopiero potem panel. Oszczędza to szukania błędów modemu „w panelu”.
Instalator wykonuje kroki 1–3 sam (rozdz. 6.3) – ręcznie trzeba je robić tylko przy nietypowym sprzęcie.

Log: reguła `deploy/logrotate-gammu-smsd` (`copytruncate`, bo Gammu trzyma plik otwarty; ⚠ U5) z `create 0640 root www-data`.

### Krok 4 – pakiety WWW
```bash
sudo apt install nginx php-fpm php-cli php-mysql php-mbstring
```

### Krok 5 – kod aplikacji
```bash
sudo git clone <repozytorium> /opt/smsgui        # lub rozpakowanie archiwum wydania
sudo cp /opt/smsgui/config/config.example.php /opt/smsgui/config/config.php   # + hasło konta smsgui
sudo chown root:www-data /opt/smsgui/config/config.php && sudo chmod 0640 /opt/smsgui/config/config.php
sudo install -d -o www-data -g www-data -m 0750 /var/lib/smsgui /var/lib/smsgui/backups /var/lib/smsgui/sessions
echo 'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload gammu-smsd, /usr/bin/systemctl restart gammu-smsd' \
  | sudo tee /etc/sudoers.d/smsgui && sudo chmod 0440 /etc/sudoers.d/smsgui
sudo visudo -c
```
Kod należy do `root` (tylko do odczytu dla `www-data`), zapisywalne są wyłącznie dane w `/var/lib/smsgui`.

### Krok 6 – serwer WWW
`deploy/nginx-smsgui.conf` (kopiowany do `/etc/nginx/sites-available/`):
```nginx
server {
    listen 80;
    server_name sms.example.lan;
    root /opt/smsgui/public;
    index index.php;

    # Tylko sieć lokalna (decyzja D2). Wystawienie na zewnątrz – patrz rozdz. 5.3.
    allow 127.0.0.1;  allow ::1;
    allow 10.0.0.0/8; allow 172.16.0.0/12; allow 192.168.0.0/16; allow fc00::/7;
    deny all;

    location / { try_files $uri /index.php?$query_string; }
    location = /index.php {                      # wykonywany jest tylko front controller
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```
HTTPS w sieci lokalnej jest opcjonalny (instalator może wygenerować certyfikat samopodpisany).
Przy wystawieniu na zewnątrz – `sudo certbot --nginx` lub VPN.

### Krok 7 – proces w tle, konto i weryfikacja
```ini
# smsgui-worker.service
[Unit]
Description=Gammu SMSD Web UI – synchronizacja
After=mariadb.service
[Service]
User=www-data
ExecStart=/usr/bin/php /opt/smsgui/bin/smsgui worker
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
```
```bash
sudo cp /opt/smsgui/deploy/smsgui-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo -u www-data php /opt/smsgui/bin/smsgui setup db          # tabele panelu (migracje)
sudo mariadb -e "GRANT INSERT (phone, raw_number, modem, received_at) ON smsgui.calls TO 'smsgui_hook'@'localhost'"
sudo systemctl enable --now smsgui-worker
sudo -u www-data php /opt/smsgui/bin/smsgui passwd admin     # pyta o hasło
sudo -u www-data php /opt/smsgui/bin/smsgui check
```
`check` sprawdza wszystkie powyższe punkty i wypisuje listę ✔/✘ z podpowiedziami.

Hook połączeń (`/etc/smsgui/hook.cnf` z hasłem `smsgui_hook`) instalator przygotowuje od razu, ale
`HangupCalls`/`RunOnIncomingCall` włącza się dopiero w panelu (2.13).

## 6.3. Skrypt `install.sh`

Wykonuje kroki 1–7 automatycznie i jest **idempotentny** – można go uruchomić ponownie (aktualizacja,
podłączenie modemu później, naprawa uprawnień). Na początku pyta o tryb (`SMSGUI_MODE`):

- **`full`** (domyślny) – instaluje brakujące pakiety i konfiguruje całość;
- **`app`** – nie instaluje pakietów (np. PHP z innego repozytorium): sprawdza, czy są potrzebne programy
  (PHP ≥ 8.5 z `pdo_mysql` i `mbstring`, PHP-FPM w tej samej wersji co CLI, MariaDB z dostępem root przez gniazdo, Gammu i usługa
  `gammu-smsd`, nginx, OpenSSL; przy pobieraniu także `git`), przy braku przerywa z poleceniem `apt install`
  do wykonania; dalsze kroki jak w `full`.

Kolejność:

1. **Pobranie aplikacji** – uruchomiony przez `curl … | sudo bash` (bez repozytorium obok) instaluje `git`,
   klonuje repozytorium do `/opt/smsgui` (albo robi `git pull`, jeśli już tam jest) i uruchamia pobraną
   wersję siebie. Pytania czyta wtedy z terminala (`/dev/tty`), nie z potoku. Uruchomiony z repozytorium
   (`sudo ./deploy/install.sh`) – używa katalogu, w którym leży.
2. **Pakiety** – lista wymaganych pakietów z oznaczeniem zainstalowanych (✔) i brakujących (→); instaluje tylko
   brakujące (już zainstalowanych nie aktualizuje) z paskiem postępu, pełne wyjście `apt-get` zapisuje
   w `/var/log/smsgui-install.log` (przy błędzie pokazuje jego koniec). Sprawdzenie PHP ≥ 8.5
   i wersji Gammu (≥ 1.42, schemat 17).
3. **Baza** (krok 2) – tworzy bazy i konta tylko, jeśli ich nie ma; hasła zapisuje do plików konfiguracyjnych;
   tabele Gammu tworzy z `mysql.sql` z paczki i zamienia na InnoDB (także w istniejącej bazie, po potwierdzeniu).
4. **Modem i `gammu-smsdrc`** (kreator). Modem uznaje się za skonfigurowany, gdy `device` wskazuje urządzenie
   z `/dev/serial/by-id/` i `service = sql`.
   - *nieskonfigurowany:* zatrzymuje `gammu-smsd`, wyświetla listę portów z `/dev/serial/by-id/` z oznaczeniem tych,
     na których `gammu identify` się udaje (z producentem i modelem), i pozwala wybrać jeden (domyślnie pierwszy działający).
     Stan karty sprawdza przez `gammu getsecuritystatus`: PIN wymagany → pyta o niego i **sprawdza go raz**
     (`gammu entersecuritycode PIN …`; przy błędzie przerywa, żeby demon nie zużył kolejnych prób i nie zablokował karty);
     PUK → przerywa z podpowiedzią. Brak modemu → ostrzeżenie, reszta panelu instaluje się normalnie.
   - *istniejąca konfiguracja z backendem `files`* (domyślna z paczki, ⚠ U2) → przestawia na `sql` po potwierdzeniu
     (wiadomości z katalogów kolejek Gammu nie są przenoszone – informacja na ekranie).
   - *skonfigurowany:* modemu nie zmienia.
   - Brakujące ustawienia zalecane dla panelu – `logfile`, `deliveryreportdelay = 172800`, `phoneid` – dopisuje.
   - Przed każdą zmianą kopia `/etc/gammu-smsdrc.smsgui-RRRRMMDD-GGMMSS`. Zmiany robi
     `bin/smsgui setup gammu` – ten sam parser co edytor w panelu, zmienia tylko potrzebne linie.
5. **Kod, dane i uprawnienia** (krok 5), potem restart Gammu, jeśli coś zmieniono albo usługa nie działa.
6. **nginx, proces w tle, logrotate, fail2ban, konto** (kroki 6–7) i diagnostyka `bin/smsgui check`.
7. **Testowy SMS** (opcjonalny) – pyta o numer (Enter pomija), wysyła wiadomość przez panel
   (`bin/smsgui send … --wait=90`, widoczna potem w „Wysłanych”) i czeka na wynik z `sentitems`.
8. Podsumowanie z adresem panelu (nazwa hosta i adres IP).

Pytania mają wartości domyślne (Enter je akceptuje). Bez pytań – zmienne środowiskowe:
`SMSGUI_MODE` (`full`/`app`), `SMSGUI_MODEM` (ścieżka lub `skip`), `SMSGUI_PIN`, `SMSGUI_PHONEID` (domyślnie `GSM1`), `SMSGUI_HOST`,
`SMSGUI_HTTPS` (`none`/`self-signed`), `SMSGUI_USER`, `SMSGUI_PASSWORD`, `SMSGUI_TEST_NUMBER`;
pobieranie: `SMSGUI_DIR` (domyślnie `/opt/smsgui`), `SMSGUI_REPO`, `SMSGUI_BRANCH` (domyślnie `main`).

Ograniczenie do sieci lokalnej jest włączone zawsze – zmienia się je ręcznie w konfiguracji nginx.
Wartości zależne od paczki wykrywa zamiast je zakładać: nazwę usługi i użytkownika demona (⚠ U1),
położenie `mysql.sql` (⚠ U4), gniazdo PHP-FPM (tylko w wersji PHP CLI; nie działa → uruchamia usługę, a gdy jej nie ma – przerywa). Dodatkowo: wyłącza domyślną stronę nginx (`sites-enabled/default`),
instaluje filtr fail2ban (jail wyłączony).
Szablony konfiguracji: `deploy/nginx-smsgui.conf`, `deploy/smsgui-worker.service`, `deploy/gammu-smsd@.service` (R),
`deploy/logrotate-gammu-smsd`, `deploy/fail2ban/`.

## 6.4. Aktualizacja aplikacji
```bash
cd /opt/smsgui && sudo git pull          # lub rozpakowanie nowej wersji
sudo systemctl restart smsgui-worker     # proces w tle i tak restartuje się sam po zmianie plików
sudo -u www-data php bin/smsgui check   # migracje bazy wykonają się automatycznie
```
Albo ponownie polecenie instalacyjne z `curl` – zrobi `git pull` i sprawdzi konfigurację (bez ponownych pytań
o modem i konto, jeśli już są). Dane i `config.php` nie są nadpisywane.

Aktualizacja Gammu przez `apt` w ramach Ubuntu 26.04 nie zmienia schematu bazy. Przejście na Gammu 1.44+
(schemat 18) wymaga migracji tabel `gammu` – poza zakresem wersji 1 (D19).

## 6.5. Kopie zapasowe
- Bazy: `mariadb-dump --single-transaction --databases smsgui gammu | gzip > /backup/smsgui-$(date +%F).sql.gz`
  (bezpieczne przy działającej aplikacji) – codziennie z timera systemd (`deploy/smsgui-backup.*`, opcjonalnie), np. 14 kopii.
- Do kopii dołączyć: `/etc/gammu-smsdrc`, `/opt/smsgui/config/config.php`, `/etc/smsgui/`, `/var/lib/smsgui/`.
- Odtworzenie: zatrzymanie `smsgui-worker` i `gammu-smsd`, import zrzutu, uruchomienie usług.

## 6.6. Monitorowanie
- `bin/smsgui check --quiet` zwraca kod ≠ 0 przy problemie – można podpiąć pod dowolny monitoring.
- Opcjonalnie: powiadomienia (e-mail/SMS do admina), gdy usługa nie działa, modem jest niedostępny lub kolejka stoi
  (2.17.5).

## 6.7. Odinstalowanie
`sudo deploy/uninstall.sh` (z `--purge` – także dane). Wyłączenie procesu w tle, usunięcie konfiguracji nginx,
wpisu sudoers i reguły logrotate, wyłączenie hooka połączeń w `gammu-smsdrc`, usunięcie `/opt/smsgui`
i (z `--purge`) bazy `smsgui`, kont `smsgui`/`smsgui_hook` oraz `/var/lib/smsgui`.
Gammu SMSD i baza `gammu` działają dalej bez zmian (kopie `/etc/gammu-smsdrc.smsgui-*` sprzed zmian instalatora zostają).
