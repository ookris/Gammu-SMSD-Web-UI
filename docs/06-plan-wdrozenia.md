# 6. Plan wdrożenia (Ubuntu Server LTS)

Cel: instalacja **jednym poleceniem** na czystym serwerze – także przez osobę, która nie zna smstools ani
nginx. Skrypt `deploy/install.sh` pobiera aplikację, instaluje smstools3, pomaga skonfigurować modem
i panel, a na końcu wysyła testowy SMS. Każdy krok jest opisany poniżej tak, żeby dało się go wykonać
ręcznie i zrozumieć.

```bash
curl -fsSL https://raw.githubusercontent.com/ookris/smstools3-Web-GUI/main/deploy/install.sh | sudo bash
```

## 6.1. Wymagania

| Element | Wymaganie |
|---------|-----------|
| System | Ubuntu Server LTS 26.04 (wymagane PHP ≥ 8.5 z repozytorium systemu; starsze wydania Ubuntu nie są wspierane) |
| Sprzęt | modem GSM USB/szeregowy z aktywną kartą SIM (PIN wyłączony lub znany) |
| Pakiety | `smstools`, `nginx`, `php-fpm`, `php-cli`, `php-sqlite3`, `php-mbstring`, `git` (instaluje je skrypt) |
| Opcjonalnie | `sqlite3` (podgląd bazy); w rozszerzeniach: `msmtp-mta` (przekazywanie na e-mail, jeśli nie przez SMTP z panelu) |

## 6.2. Kroki instalacji

### Krok 1 – smstools3 i modem
```bash
sudo apt update
sudo apt install smstools
ls -l /dev/serial/by-id/          # stała nazwa modemu (lepsza niż /dev/ttyUSB0)
```
W `/etc/smsd.conf` ustawić minimum: `devices`, sekcję modemu z `device` i `incoming = yes`,
**katalogi `sent` i `failed`** (domyślnie ich nie ma – bez nich panel nie pozna wyniku wysyłki)
oraz zalecane `incoming_utf8 = yes`:
```ini
sent = /var/spool/sms/sent
failed = /var/spool/sms/failed
incoming_utf8 = yes
```
```bash
sudo install -d -o smsd -g smsd -m 2770 /var/spool/sms/sent /var/spool/sms/failed
``` Test bez panelu:
```bash
sudo systemctl restart smstools
sudo -u smsd smssend 48601234567 'Test'   # lub ręczny plik w /var/spool/sms/outgoing
tail -f /var/log/smstools/smsd.log
```
**Zasada:** najpierw działa smstools, dopiero potem panel. Oszczędza to szukania
błędów modemu „w panelu”. Instalator wykonuje ten krok sam (rozdz. 6.3) i kończy się testowym SMS-em –
ręcznie trzeba go robić tylko przy nietypowym sprzęcie (np. kilka modemów).

### Krok 2 – pakiety WWW
```bash
sudo apt install nginx php-fpm php-cli php-sqlite3 php-mbstring
```

### Krok 3 – kod aplikacji
```bash
sudo git clone <repozytorium> /opt/smsgui        # lub rozpakowanie archiwum wydania
sudo cp /opt/smsgui/config/config.example.php /opt/smsgui/config/config.php
sudo install -d -o www-data -g www-data -m 0750 /var/lib/smsgui /var/lib/smsgui/backups /var/lib/smsgui/sessions
```
Kod należy do `root` (tylko do odczytu dla `www-data`), zapisywalne są wyłącznie dane w `/var/lib/smsgui`.

### Krok 4 – uprawnienia do smstools
```bash
sudo usermod -aG smsd www-data
sudo chgrp smsd /var/spool/sms/{outgoing,incoming,sent,failed,checked}
sudo chmod 2770 /var/spool/sms/{outgoing,incoming,sent,failed,checked}
sudo chgrp smsd /etc/smsd.conf && sudo chmod 0664 /etc/smsd.conf
sudo chgrp -R smsd /var/log/smstools && sudo chmod -R g+r /var/log/smstools
echo 'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart smstools' \
  | sudo tee /etc/sudoers.d/smsgui && sudo chmod 0440 /etc/sudoers.d/smsgui
sudo visudo -c
sudo systemctl restart php*-fpm                   # żeby www-data „zobaczył” nową grupę
```
⚠ Instalator najpierw sprawdzi rzeczywistego właściciela katalogów i użytkownika, jako którego działa smsd
(`user =` w smsd.conf / domyślnie `smsd`), i dopasuje polecenia.
Dodatkowo sprawdzi regułę `/etc/logrotate.d/smstools`, żeby po rotacji log dalej był czytelny dla grupy.

### Krok 5 – serwer WWW
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

### Krok 6 – synchronizacja w tle
Trzy jednostki systemd: usługa wykonująca synchronizację, timer (co 30 s) i obserwacja katalogów
(`.path` – synchronizacja od razu, gdy smsd zapisze plik):
```ini
# smsgui-sync.service
[Unit]
StartLimitIntervalSec=0      # obserwacja katalogów może uruchamiać usługę wiele razy na sekundę
[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/php /opt/smsgui/bin/smsgui sync

# smsgui-sync.timer
[Timer]
OnBootSec=30s
OnUnitActiveSec=30s
AccuracySec=1s
[Install]
WantedBy=timers.target

# smsgui-sync.path
[Path]
PathChanged=/var/spool/sms/incoming
PathChanged=/var/spool/sms/sent
PathChanged=/var/spool/sms/failed
Unit=smsgui-sync.service
[Install]
WantedBy=paths.target
```
```bash
sudo cp /opt/smsgui/deploy/smsgui-sync.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now smsgui-sync.timer smsgui-sync.path
```
Instalator wpisuje do `.path` rzeczywiste ścieżki z `smsd.conf`.
Synchronizacja działa jako `www-data` – ten sam użytkownik co panel, więc nie ma konfliktów
uprawnień do pliku bazy.

### Krok 7 – konto i weryfikacja
```bash
sudo -u www-data php /opt/smsgui/bin/smsgui passwd admin     # pyta o hasło
sudo -u www-data php /opt/smsgui/bin/smsgui check
```
`check` sprawdza wszystkie powyższe punkty i wypisuje listę ✔/✘ z podpowiedziami.

## 6.3. Skrypt `install.sh`

Wykonuje kroki 1–7 automatycznie i jest **idempotentny** – można go uruchomić ponownie (aktualizacja,
podłączenie modemu później, naprawa uprawnień). Kolejność:

1. **Pobranie aplikacji** – uruchomiony przez `curl … | sudo bash` (bez repozytorium obok) instaluje `git`,
   klonuje repozytorium do `/opt/smsgui` (albo robi `git pull`, jeśli już tam jest) i uruchamia pobraną
   wersję siebie. Pytania czyta wtedy z terminala (`/dev/tty`), nie z potoku. Uruchomiony z repozytorium
   (`sudo ./deploy/install.sh`) – używa katalogu, w którym leży.
2. **Pakiety** – `apt-get update`, instalacja `smstools`, nginx i PHP; sprawdzenie PHP ≥ 8.5.
3. **Modem i `smsd.conf`** (kreator). Modem uznaje się za skonfigurowany, gdy `device` pierwszego modemu
   wskazuje urządzenie z `/dev/serial/by-id/` (domyślne `/dev/ttyS0` z paczki to port szeregowy płyty, nie modem).
   - *nieskonfigurowany:* zatrzymuje smstools, wyświetla listę portów z `/dev/serial/by-id/` z oznaczeniem tych,
     które odpowiadają na `AT` (modem USB ma zwykle kilka portów), i pozwala wybrać jeden (domyślnie pierwszy
     odpowiadający). Stan karty sprawdza przez `AT+CPIN?`: PIN wymagany → pyta o niego i **sprawdza go raz**
     (`AT+CPIN=…`; przy błędzie przerywa, żeby smsd nie zużył kolejnych prób i nie zablokował karty);
     PUK → przerywa z podpowiedzią. Brak modemu → ostrzeżenie, reszta panelu instaluje się normalnie.
   - *skonfigurowany:* modemu nie zmienia.
   - Brakujące ustawienia zalecane dla panelu – `sent`, `failed`, `incoming_utf8 = yes` – dopisuje
     (przy już skonfigurowanym pliku po potwierdzeniu).
   - Przed każdą zmianą kopia `/etc/smsd.conf.smsgui-RRRRMMDD-GGMMSS`. Zmiany robi
     `bin/smsgui smsd-setup` – ten sam parser co edytor w panelu, zmienia tylko potrzebne linie
     (pierwszy modem z `devices`: `device`, `pin`, `incoming = yes`).
4. **Kod i dane, uprawnienia** (kroki 3–4), potem restart smstools, jeśli coś zmieniono albo usługa nie działa.
5. **nginx, synchronizacja, fail2ban, konto** (kroki 5–7) i diagnostyka `bin/smsgui check`.
6. **Testowy SMS** (opcjonalny) – pyta o numer (Enter pomija), wysyła wiadomość przez panel
   (`bin/smsgui send … --wait=90`, widoczna potem w „Wysłanych”) i czeka na wynik z `sent`/`failed`.
7. Podsumowanie z adresem panelu (nazwa hosta i adres IP).

Pytania mają wartości domyślne (Enter je akceptuje). Bez pytań – zmienne środowiskowe:
`SMSGUI_MODEM` (ścieżka lub `skip`), `SMSGUI_PIN`, `SMSGUI_HOST`, `SMSGUI_HTTPS` (`none`/`self-signed`),
`SMSGUI_USER`, `SMSGUI_PASSWORD`, `SMSGUI_TEST_NUMBER`; pobieranie: `SMSGUI_DIR` (domyślnie `/opt/smsgui`),
`SMSGUI_REPO`, `SMSGUI_BRANCH` (domyślnie `main`).

Ograniczenie do sieci lokalnej jest włączone zawsze – zmienia się je ręcznie w konfiguracji nginx.
Wartości zależne od paczki wykrywa zamiast je zakładać: użytkownik smsd (`user =` w `smsd.conf`, domyślnie `smsd`),
grupa (właściciel katalogu `outgoing`), ścieżki kolejek i logu (`bin/smsgui paths` – ten sam parser co panel),
wersja PHP-FPM. Dodatkowo: wyłącza domyślną stronę nginx (`sites-enabled/default`), ostrzega o regule logrotate
odbierającej grupie prawo odczytu logu, instaluje filtr fail2ban (jail wyłączony).
Szablony konfiguracji: `deploy/nginx-smsgui.conf`, `deploy/smsgui-sync.*`, `deploy/fail2ban/`.

## 6.4. Aktualizacja aplikacji
```bash
cd /opt/smsgui && sudo git pull          # lub rozpakowanie nowej wersji
sudo -u www-data php bin/smsgui check   # migracje bazy wykonają się automatycznie
```
Albo ponownie polecenie instalacyjne z `curl` – zrobi `git pull` i sprawdzi konfigurację (bez ponownych pytań
o modem i konto, jeśli już są).
Dane i `config.php` nie są nadpisywane.

## 6.5. Kopie zapasowe
- Baza: `sqlite3 /var/lib/smsgui/smsgui.sqlite ".backup '/backup/smsgui-$(date +%F).sqlite'"`
  (bezpieczne przy działającej aplikacji) – codziennie z crona/timera, np. 14 kopii.
- Do kopii dołączyć: `/etc/smsd.conf`, `/opt/smsgui/config/config.php`, `/var/lib/smsgui/backups/`.
- Odtworzenie: podmiana pliku bazy przy zatrzymanym timerze synchronizacji.

## 6.6. Monitorowanie
- `bin/smsgui check --quiet` zwraca kod ≠ 0 przy problemie – można podpiąć pod dowolny monitoring.
- Opcjonalnie: powiadomienie (e-mail/SMS do admina), gdy usługa nie działa lub kolejka stoi
  (patrz [08-pomysly.md](08-pomysly.md)).

## 6.7. Odinstalowanie
`sudo deploy/uninstall.sh` (z `--purge` – także dane). Wyłączenie timera, usunięcie konfiguracji nginx i wpisu sudoers, usunięcie `www-data` z grupy `smsd`,
usunięcie `/opt/smsgui` i (opcjonalnie) `/var/lib/smsgui`. smstools3 działa dalej bez zmian (kopie
`/etc/smsd.conf.smsgui-*` sprzed zmian instalatora zostają).
