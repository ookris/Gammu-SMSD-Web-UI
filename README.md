# Gammu SMSD Web UI

**Polski** | [English](README.en.md)

Panel WWW do bramki SMS opartej na [Gammu SMSD](https://docs.gammu.org/smsd/) i modemie GSM, przeznaczony dla
Ubuntu Server. Wysyłka i odbiór SMS, rozmowy, książka telefoniczna, raporty doręczenia, USSD, odrzucone połączenia,
czarna lista oraz konfiguracja i diagnostyka Gammu – wszystko z przeglądarki, bez pracy w konsoli.

Instalacja jednym poleceniem: skrypt instaluje i konfiguruje Gammu SMSD, MariaDB, nginx i PHP, pomaga wybrać modem
i kończy diagnostyką.

> **Stan projektu:** wersja 1.0.0-beta.1. Panel jest kompletny i przetestowany na symulatorze Gammu oraz na czystym
> Ubuntu Server 26.04.1 (instalacja, diagnostyka, panel, testy automatyczne). Testy z prawdziwym modemem (wysyłka,
> odbiór, USSD, raporty doręczenia) są w toku.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="screenshots/dashboard-dark.png">
  <img src="screenshots/dashboard.png" alt="Pulpit panelu">
</picture>

## Spis treści

- [Jak to działa](#jak-to-działa)
- [Możliwości](#możliwości)
- [Zrzuty ekranu](#zrzuty-ekranu)
- [Technologie](#technologie)
- [Wymagania](#wymagania)
- [Instalacja](#instalacja)
- [Instalacja ręczna](#instalacja-ręczna)
- [Po instalacji](#po-instalacji)
- [Konfiguracja](#konfiguracja)
- [Bezpieczeństwo](#bezpieczeństwo)
- [Aktualizacja, kopie zapasowe, odinstalowanie](#aktualizacja-kopie-zapasowe-odinstalowanie)
- [Narzędzie konsolowe](#narzędzie-konsolowe)
- [Rozwiązywanie problemów](#rozwiązywanie-problemów)
- [Rozwój i testy](#rozwój-i-testy)
- [Licencje](#licencje)

## Jak to działa

```
przeglądarka ──► nginx + PHP-FPM (panel) ──► MariaDB ◄── Gammu SMSD ◄──► modem GSM (USB)
                                              │  baza smsgui – dane panelu
                                              │  baza gammu  – kolejka i historia Gammu
                 smsgui-worker (proces w tle) ┘  synchronizacja co 3 s
```

Gammu SMSD obsługuje modem i pracuje na bazie `gammu` (backend SQL). Panel nie rozmawia z modemem bezpośrednio:
wiadomości do wysłania zapisuje do tabel Gammu, a proces w tle `smsgui-worker` przenosi do bazy panelu odebrane SMS,
statusy wysyłki, raporty doręczenia i stan modemu. Dzięki temu panel działa dalej, gdy modem jest chwilowo niedostępny,
a Gammu – gdy panel jest wyłączony.

Panel sam koduje i dzieli wiadomości: wybiera GSM lub Unicode i tworzy części z nagłówkiem UDH. Gammu 1.42 nie dzieli
wiadomości zapisanych w bazie, a przy kodowaniu GSM po cichu zamienia polskie znaki (ą→a, ł→l).

## Możliwości

**Wysyłka**
- do jednego lub wielu odbiorców: grupy, kontakty i numery wpisane ręcznie – bez duplikatów, z listą błędnych numerów
- licznik znaków i części na żywo z wyjaśnieniem kodowania (GSM / Unicode), automatyczny podział do 10 części,
  opcjonalna zamiana polskich znaków (dłuższa wiadomość w jednym SMS)
- raport doręczenia, SMS flash, wysyłka planowana, wysyłka priorytetowa
- szablony i personalizacja (`{nazwa}`, `{imie}`) z podglądem
- dławienie (N SMS na minutę) i okno godzinowe wysyłki do wielu odbiorców
- raport wysyłki z paskiem postępu, „Ponów nieudane”, „Anuluj pozostałe”

**Wiadomości**
- rozmowy w formie wątków (jak w telefonie), odpowiedź bez przeładowania strony, automatyczne odświeżanie
- odebrane i wysłane z filtrami; statusy: w kolejce, ponawiana, wysłana, doręczona, niedoręczona, błąd –
  z opisem kodu błędu sieci
- ponawianie i anulowanie wiadomości z kolejki
- widoczne są też SMS wysłane innymi drogami (np. `gammu-smsd-inject`)
- licznik nieprzeczytanych w menu, automatyczne czyszczenie historii starszej niż N dni (opcjonalnie)

**Kontakty**
- książka telefoniczna z grupami, wyszukiwanie podczas pisania, akcje zbiorcze
- import i eksport CSV (zgodny z Excelem)
- nazwa kontaktu zamiast numeru w całym panelu

**Modem i Gammu**
- stan modemu: sygnał, operator, ostatnia aktualizacja; wykrywanie niedostępnego modemu
- USSD na żądanie (np. stan konta) z obsługą menu operatora i szybkimi kodami
- odrzucanie połączeń przychodzących z listą połączeń i wpisami w rozmowie
- czarna lista numerów (Gammu odrzuca wiadomości od zablokowanych numerów)
- konfiguracja `/etc/gammu-smsdrc`: formularz i edytor z walidacją, okno potwierdzenia z różnicami linia po linii,
  kopie zapasowe z podglądem, porównaniem i przywracaniem, przeładowanie i restart usługi
- podgląd logu Gammu z filtrem, podświetlaniem błędów i odświeżaniem na żywo

**Panel**
- pulpit z kontrolą zdrowia: ostrzeżenia z podpowiedzią, co poprawić (baza, schemat Gammu, usługa, modem, kolejka,
  zgodność czasu, konfiguracja)
- interfejs po polsku i angielsku, jasny i ciemny motyw, działa na telefonie
- jedno konto z blokadą po 5 nieudanych logowaniach, „Zapamiętaj mnie”, wygasanie sesji
- dostęp domyślnie tylko z sieci lokalnej

**Planowane (po wersji 1.0):** przekazywanie SMS i połączeń na e-mail i webhook, HTTP API, autoodpowiedzi,
cykliczne sprawdzanie salda, powiadomienia administracyjne, statystyki, obsługa kilku modemów.

## Zrzuty ekranu

Jasny i ciemny motyw przełączają się według ustawień systemu. Kliknij miniaturę, żeby zobaczyć pełny rozmiar.

<table>
  <tr>
    <td width="50%"><a href="screenshots/threads.png"><img src="screenshots/threads.png" alt="Rozmowy"></a><br><sub>Rozmowy</sub></td>
    <td width="50%"><a href="screenshots/threads-dark.png"><img src="screenshots/threads-dark.png" alt="Rozmowy – ciemny motyw"></a><br><sub>Rozmowy – ciemny motyw</sub></td>
  </tr>
  <tr>
    <td width="50%"><a href="screenshots/compose.png"><img src="screenshots/compose.png" alt="Nowa wiadomość do grup i kontaktów"></a><br><sub>Nowa wiadomość do grup i kontaktów</sub></td>
    <td width="50%"><a href="screenshots/sent.png"><img src="screenshots/sent.png" alt="Wysłane – statusy i raporty doręczenia"></a><br><sub>Wysłane – statusy i raporty doręczenia</sub></td>
  </tr>
  <tr>
    <td width="50%"><a href="screenshots/contacts.png"><img src="screenshots/contacts.png" alt="Kontakty i grupy"></a><br><sub>Kontakty i grupy</sub></td>
    <td width="50%"><a href="screenshots/modem.png"><img src="screenshots/modem.png" alt="Modem i USSD"></a><br><sub>Modem i USSD</sub></td>
  </tr>
  <tr>
    <td width="50%"><a href="screenshots/config.png"><img src="screenshots/config.png" alt="Konfiguracja Gammu"></a><br><sub>Konfiguracja Gammu</sub></td>
    <td width="50%"><a href="screenshots/log.png"><img src="screenshots/log.png" alt="Log Gammu"></a><br><sub>Log Gammu</sub></td>
  </tr>
</table>

## Technologie

| Warstwa | Technologia |
|---------|-------------|
| Bramka SMS | Gammu SMSD 1.42 z backendem SQL (`native_mysql`), schemat bazy w wersji 17 |
| Baza danych | MariaDB (tabele InnoDB – transakcje przy zapisie wiadomości wieloczęściowych) |
| Aplikacja | PHP 8.5 bez frameworka i bez Composera; PDO, migracje bazy wbudowane w panel |
| Interfejs | [htmx](https://htmx.org) 2, [Pico.css](https://picocss.com) 2, czcionki IBM Plex, ikony [Solar](https://icon-sets.iconify.design/solar/) – wszystko dołączone lokalnie, bez npm i bez procesu budowania |
| Serwer | nginx + PHP-FPM, systemd (proces w tle), logrotate, opcjonalnie fail2ban |

Panel działa ze ścisłą polityką CSP (`default-src 'self'`, bez skryptów i stylów inline) i nie pobiera niczego
z zewnętrznych serwerów.

## Wymagania

| Element | Wymaganie |
|---------|-----------|
| System | **Ubuntu Server 26.04 LTS** (sprawdzone na 26.04.1). Starsze wydania nie mają PHP 8.5 w repozytorium |
| Repozytoria | `main` i `universe` (z `universe` pochodzą m.in. `gammu-smsd`, `php-fpm`, `php-mbstring`) |
| Sprzęt | modem GSM USB lub szeregowy obsługiwany przez Gammu (komendy AT), aktywna karta SIM – PIN wyłączony lub znany |
| Pakiety | `gammu`, `gammu-smsd`, `mariadb-server`, `nginx`, `php-fpm`, `php-cli`, `php-mysql`, `php-mbstring`, `openssl`, `git` |
| PHP | 8.5 lub nowsze z rozszerzeniami `pdo_mysql` i `mbstring`; PHP-FPM w tej samej wersji co PHP CLI |
| Uprawnienia | konto z `sudo`; MariaDB z dostępem `root` przez gniazdo (domyślnie w Ubuntu) |
| Sieć | dostęp do repozytoriów Ubuntu i GitHub w czasie instalacji; panel – z sieci lokalnej |

Wersje sprawdzone na Ubuntu 26.04.1: Gammu 1.42.0, MariaDB 11.8.6, nginx 1.28.3, PHP 8.5.4.

## Instalacja

```bash
curl -fsSL https://raw.githubusercontent.com/ookris/Gammu-SMSD-Web-UI/main/deploy/install.sh | sudo bash
```

Można też najpierw pobrać repozytorium i uruchomić skrypt z niego:

```bash
sudo git clone https://github.com/ookris/Gammu-SMSD-Web-UI.git /opt/smsgui
sudo /opt/smsgui/deploy/install.sh
```

### Tryby instalacji

Na początku instalator pyta o tryb:

| | `full` (domyślny) | `app` |
|---|---|---|
| Dla kogo | czysty serwer, typowa instalacja | administrator, który sam zarządza pakietami (np. PHP z innego repozytorium) |
| Pakiety systemowe | pokazuje listę (✔ jest / → do instalacji) i instaluje **tylko brakujące**, z paskiem postępu | **nie instaluje** – sprawdza, czy są potrzebne programy, a przy braku przerywa i podaje polecenie `apt install` |
| Co jest sprawdzane | obecność pakietów | działanie programów: PHP ≥ 8.5 z `pdo_mysql` i `mbstring`, PHP-FPM w tej samej wersji, dostęp do MariaDB, Gammu i usługa `gammu-smsd`, nginx, OpenSSL |
| Bazy, Gammu, nginx, panel | konfiguruje | konfiguruje (tak samo jak `full`) |

Zainstalowanych już pakietów żaden tryb nie aktualizuje. Pełne wyjście `apt-get` trafia do `/var/log/smsgui-install.log`
(przy błędzie instalator pokazuje jego koniec). Osoby, które chcą skonfigurować każdy element samodzielnie, znajdą
opis w sekcji [Instalacja ręczna](#instalacja-ręczna).

### Co robi instalator

1. Pobiera aplikację do `/opt/smsgui` (albo aktualizuje ją przez `git pull`).
2. Instaluje lub sprawdza pakiety (zależnie od trybu); sprawdza PHP ≥ 8.5 i gniazdo PHP-FPM.
3. Tworzy bazy `gammu` i `smsgui` oraz konta `gammu`, `smsgui`, `smsgui_hook` z losowymi hasłami
   (zapisanymi w `/etc/smsgui/secrets.env`, tylko dla roota); tworzy tabele Gammu ze skryptu z paczki
   i zamienia je na InnoDB.
4. Ustawia pliki, katalogi danych i uprawnienia, wpis sudoers (tylko przeładowanie i restart Gammu).
5. Kreator modemu: sprawdza porty z `/dev/serial/by-id/` (`gammu identify`), pozwala wybrać port, sprawdza stan karty
   i **PIN – tylko raz** (przy błędzie przerywa, żeby nie zablokować karty). Konfiguruje `/etc/gammu-smsdrc`
   (backend SQL, log w pliku, raporty doręczenia) – zmienia tylko potrzebne linie, kopię zapisuje jako
   `/etc/gammu-smsdrc.smsgui-RRRRMMDD-GGMMSS`.
6. Tworzy tabele panelu, uruchamia proces w tle `smsgui-worker`, konfiguruje nginx (opcjonalnie HTTPS
   z certyfikatem samopodpisanym), regułę logrotate i filtr fail2ban (wyłączony).
7. Pyta o login i hasło do panelu, uruchamia diagnostykę i opcjonalnie wysyła testowy SMS.

Instalator jest **idempotentny**: ponowne uruchomienie aktualizuje kod, naprawia uprawnienia i nie zmienia
skonfigurowanego modemu ani konta. Bez modemu panel też się zainstaluje (odpowiedz `skip` na pytanie o port) –
po podłączeniu modemu wystarczy uruchomić skrypt jeszcze raz.

### Instalacja bez pytań

Wszystkie odpowiedzi można podać w zmiennych środowiskowych:

| Zmienna | Znaczenie | Domyślnie |
|---------|-----------|-----------|
| `SMSGUI_MODE` | tryb: `full` / `app` | `full` |
| `SMSGUI_MODEM` | ścieżka portu modemu albo `skip` | pierwszy działający port |
| `SMSGUI_PIN` | PIN karty SIM (tylko gdy karta go wymaga) | – |
| `SMSGUI_PHONEID` | nazwa modemu w Gammu (`PhoneID`) | `GSM1` |
| `SMSGUI_HOST` | nazwa hosta panelu w nginx | `hostname -f` |
| `SMSGUI_HTTPS` | `none` / `self-signed` | `none` |
| `SMSGUI_USER`, `SMSGUI_PASSWORD` | login i hasło panelu (hasło ≥ 10 znaków) | `admin` / – |
| `SMSGUI_TEST_NUMBER` | numer do testowego SMS (pusty = bez testu) | – |
| `SMSGUI_DIR`, `SMSGUI_REPO`, `SMSGUI_BRANCH` | katalog, repozytorium i gałąź do pobrania | `/opt/smsgui`, to repozytorium, `main` |

```bash
sudo SMSGUI_MODE=full SMSGUI_MODEM=skip SMSGUI_HTTPS=none SMSGUI_USER=admin SMSGUI_PASSWORD='…' /opt/smsgui/deploy/install.sh
```

## Instalacja ręczna

Dla osób, które wolą skonfigurować wszystko samodzielnie. Polecenia odpowiadają temu, co robi instalator;
hasła `<hasło-gammu>`, `<hasło-smsgui>`, `<hasło-hook>` wygeneruj np. przez `openssl rand -hex 16`.
**Zasada:** najpierw musi działać Gammu, dopiero potem panel.

### 1. Gammu i modem

```bash
sudo apt update
sudo apt install gammu gammu-smsd
ls -l /dev/serial/by-id/          # stała nazwa portu (lepsza niż /dev/ttyUSB0)
```

Modem USB ma zwykle kilka portów – właściwy to ten, na którym odpowiada `gammu identify`:

```bash
printf '[gammu]\ndevice = /dev/serial/by-id/usb-…-port0\nconnection = at\n' > /tmp/gammurc
gammu -c /tmp/gammurc identify             # producent, model, IMEI
gammu -c /tmp/gammurc getsecuritystatus    # czy karta wymaga PIN
```

### 2. Baza danych

```bash
sudo apt install mariadb-server
sudo mariadb <<'SQL'
CREATE DATABASE gammu  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE smsgui CHARACTER SET utf8mb4 COLLATE utf8mb4_polish_ci;
CREATE USER 'gammu'@'localhost'       IDENTIFIED BY '<hasło-gammu>';
CREATE USER 'smsgui'@'localhost'      IDENTIFIED BY '<hasło-smsgui>';
CREATE USER 'smsgui_hook'@'localhost' IDENTIFIED BY '<hasło-hook>';
GRANT SELECT, INSERT, UPDATE, DELETE ON gammu.* TO 'gammu'@'localhost';
GRANT ALL ON smsgui.* TO 'smsgui'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON gammu.* TO 'smsgui'@'localhost';
SQL
sudo mariadb gammu < /usr/share/doc/gammu-smsd/examples/mysql.sql     # w innych wydaniach może być mysql.sql.gz
for t in gammu inbox outbox outbox_multipart phones sentitems; do
    sudo mariadb gammu -e "ALTER TABLE $t ENGINE=InnoDB"               # skrypt z paczki tworzy tabele MyISAM
done
```

Gdy paczka nie zawiera skryptu, można użyć `deploy/sql/gammu-mysql-17.sql` z repozytorium (krok 4).

### 3. Konfiguracja Gammu SMSD

`/etc/gammu-smsdrc` – minimum potrzebne panelowi:

```ini
[gammu]
device = /dev/serial/by-id/usb-…-port0
connection = at

[smsd]
service = sql
driver = native_mysql
host = localhost
user = gammu
password = <hasło-gammu>
database = gammu
phoneid = GSM1
pin = 1234
logfile = /var/log/gammu-smsd/smsd.log
debuglevel = 1
deliveryreportdelay = 172800
```

`pin` – tylko gdy karta go wymaga. `deliveryreportdelay = 172800` (2 dni) pozwala dopasować raport doręczenia od
telefonu, który był długo wyłączony (domyślnie Gammu czeka tylko 10 minut).

```bash
sudo install -d -m 0750 -g www-data /var/log/gammu-smsd
sudo chown root:www-data /etc/gammu-smsdrc && sudo chmod 0660 /etc/gammu-smsdrc   # panel edytuje ten plik
sudo systemctl enable --now gammu-smsd
sudo tail -f /var/log/gammu-smsd/smsd.log
```

Test bez panelu:

```bash
sudo gammu-smsd-inject TEXT +48601234567 -text 'Test'
sudo mariadb gammu -e 'SELECT ID, Status FROM sentitems ORDER BY ID DESC LIMIT 1'
```

### 4. Pakiety WWW i kod aplikacji

```bash
sudo apt install nginx php-fpm php-cli php-mysql php-mbstring git
sudo git clone https://github.com/ookris/Gammu-SMSD-Web-UI.git /opt/smsgui
sudo install -d -o www-data -g www-data -m 0750 /var/lib/smsgui /var/lib/smsgui/backups /var/lib/smsgui/sessions
sudo install -o www-data -g www-data -m 0644 /dev/null /var/lib/smsgui/exclude-numbers.txt
sudo install -o www-data -g www-data -m 0640 /dev/null /var/lib/smsgui/smsgui.log
```

Kod należy do `root` i jest tylko do odczytu dla `www-data`; zapisywalne są wyłącznie dane w `/var/lib/smsgui`.

`/opt/smsgui/config/config.php` (właściciel `root:www-data`, prawa `0640`) – nadpisuje tylko potrzebne wartości
z `config/config.example.php`:

```php
<?php
return [
    'db' => ['password' => '<hasło-smsgui>'],
    'hook' => ['command' => '/usr/bin/php /opt/smsgui/bin/smsgui hook call', 'cnf' => '/etc/smsgui/hook.cnf'],
];
```

`/etc/smsgui/hook.cnf` (katalog `0750`, plik `0640`, oba `root:root`) – dane konta, którym Gammu zapisuje połączenia przychodzące:

```ini
[client]
user = smsgui_hook
password = <hasło-hook>
socket = /run/mysqld/mysqld.sock
database = smsgui
```

Wpis sudoers – panel może tylko przeładować i zrestartować Gammu:

```bash
sudo install -m 0440 /opt/smsgui/deploy/sudoers-smsgui /etc/sudoers.d/smsgui
sudo visudo -c
```

### 5. nginx

```bash
sed -e 's#__ROOT__#/opt/smsgui#g' -e 's#__FPM__#/run/php/php8.5-fpm.sock#g' -e 's#__HOST__#sms.example.lan#g' \
    /opt/smsgui/deploy/nginx-smsgui.conf | sudo tee /etc/nginx/sites-available/smsgui >/dev/null
sudo ln -sf /etc/nginx/sites-available/smsgui /etc/nginx/sites-enabled/smsgui
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Szablon wykonuje tylko `public/index.php`, blokuje pliki ukryte i wpuszcza wyłącznie adresy z sieci lokalnej.
Gniazdo PHP-FPM musi mieć tę samą wersję co `php -v`.

### 6. Tabele panelu, proces w tle i konto

```bash
sudo -u www-data php /opt/smsgui/bin/smsgui setup db       # tabele panelu (migracje)
sudo mariadb -e "GRANT INSERT (phone, raw_number, modem, received_at) ON smsgui.calls TO 'smsgui_hook'@'localhost'"
sed 's#__ROOT__#/opt/smsgui#g' /opt/smsgui/deploy/smsgui-worker.service | sudo tee /etc/systemd/system/smsgui-worker.service >/dev/null
sudo systemctl daemon-reload && sudo systemctl enable --now smsgui-worker
sudo -u www-data php /opt/smsgui/bin/smsgui passwd admin   # pyta o hasło
```

### 7. Log Gammu i fail2ban (opcjonalnie)

```bash
sudo cp /opt/smsgui/deploy/logrotate-gammu-smsd /etc/logrotate.d/gammu-smsd-smsgui
sudo cp /opt/smsgui/deploy/fail2ban/filter.d/smsgui.conf /etc/fail2ban/filter.d/
sudo cp /opt/smsgui/deploy/fail2ban/jail.d/smsgui.conf /etc/fail2ban/jail.d/   # jail wyłączony – zob. Bezpieczeństwo
```

### 8. Sprawdzenie

```bash
sudo -u www-data php /opt/smsgui/bin/smsgui check
```

`check` wypisuje listę ✔/✘ z podpowiedziami – ta sama kontrola jest na pulpicie panelu.

## Po instalacji

Panel jest pod adresem `http://<nazwa-lub-IP-serwera>/`. Po zalogowaniu pulpit pokazuje kontrolę zdrowia – wszystkie
punkty powinny być zielone, gdy modem działa. Dalsza konfiguracja odbywa się w panelu:

- **Konfiguracja Gammu** – parametry modemu i bramki, edytor pliku, kopie zapasowe, przeładowanie i restart usługi;
- **Ustawienia panelu** – język, domyślne opcje wysyłki, dławienie, okno wysyłki, szybkie kody USSD, odrzucanie
  połączeń, czas sesji, czyszczenie historii, liczba kopii konfiguracji;
- **Zablokowane numery** – pierwsze włączenie czarnej listy dopisuje `ExcludeNumbersFile` do konfiguracji Gammu
  (przez okno potwierdzenia).

## Konfiguracja

Ustawienia „biznesowe” zmienia się w panelu (zapisywane w bazie). Ustawienia systemowe – ścieżki, dane bazy
i polecenia – są tylko w `config/config.php` i nie da się ich zmienić z przeglądarki (panel pokazuje je do odczytu,
bez haseł). `config.php` zawiera wyłącznie wartości różne od `config/config.example.php`:

| Klucz | Znaczenie | Domyślnie |
|-------|-----------|-----------|
| `db.dsn`, `db.user`, `db.password` | połączenie z bazą panelu | gniazdo `/run/mysqld/mysqld.sock`, baza i konto `smsgui` |
| `gammu_db` | nazwa bazy Gammu | `gammu` |
| `gammu_conf` | plik konfiguracji Gammu | `/etc/gammu-smsdrc` |
| `gammu_log` | log Gammu (`null` = `logfile` z `gammu-smsdrc`) | `null` |
| `service.*_cmd` | polecenia stanu, przeładowania i restartu usługi | `systemctl` / `sudo -n systemctl … gammu-smsd` |
| `gammu_rows` | wiersze Gammu po imporcie: `keep` (oznaczone jako przetworzone) / `delete` | `keep` |
| `worker_interval` | co ile sekund proces w tle synchronizuje dane | `3` |
| `default_country_code`, `national_number_length` | normalizacja numerów | `48`, `9` |
| `max_recipients` | limit odbiorców jednej wysyłki | `500` |
| `timezone` | strefa czasowa panelu | `Europe/Warsaw` |
| `log_path`, `backup_dir`, `session_path`, `blocklist_file` | pliki i katalogi danych | w `/var/lib/smsgui` |
| `debug` | szczegóły błędów na ekranie (tylko do diagnostyki) | `false` |

## Bezpieczeństwo

- **Dostęp tylko z sieci lokalnej** – reguła `allow`/`deny` w konfiguracji nginx. Do dostępu z zewnątrz zalecany jest
  VPN (WireGuard, Tailscale). Przy wystawieniu panelu bezpośrednio: HTTPS (`sudo certbot --nginx`) i włączony jail
  fail2ban (`enabled = true` w `/etc/fail2ban/jail.d/smsgui.conf`).
- **Logowanie:** blokada po 5 nieudanych próbach na 15 minut, ciasteczka `HttpOnly` i `SameSite`, ochrona CSRF
  wszystkich formularzy, nieudane logowania w `/var/lib/smsgui/smsgui.log` (format dla fail2ban).
- **Uprawnienia systemowe:** panel działa jako `www-data`; przez sudo może wyłącznie przeładować i zrestartować
  `gammu-smsd`. Panel może zapisywać `/etc/gammu-smsdrc` (zawsze po potwierdzeniu i z kopią) – osoba z dostępem
  do panelu ma więc pełną kontrolę nad bramką SMS. Edytor ostrzega przy każdym parametrze `RunOn…`, którego nie ustawił panel.
- **Bazy:** osobne konta z minimalnymi uprawnieniami; konto hooka połączeń może tylko dopisywać wiersze do jednej tabeli.
  Hasła: `/etc/smsgui/secrets.env` (tylko root), `config/config.php` (`0640 root:www-data`).
- Hasła i PIN z `gammu-smsdrc` nie trafiają do przeglądarki (maskowane w formularzu, edytorze i różnicach).

## Aktualizacja, kopie zapasowe, odinstalowanie

**Aktualizacja** – ponownie polecenie instalacyjne (zrobi `git pull` i sprawdzi konfigurację bez ponownych pytań) albo:

```bash
sudo git -C /opt/smsgui pull --ff-only
sudo -u www-data php /opt/smsgui/bin/smsgui check     # migracje bazy wykonują się automatycznie
```

Proces w tle sam się restartuje po zmianie plików. `config.php` i dane nie są nadpisywane.

**Kopie zapasowe:**

```bash
sudo mariadb-dump --single-transaction --databases smsgui gammu | gzip > smsgui-$(date +%F).sql.gz
```

Do kopii warto dołączyć `/etc/gammu-smsdrc`, `/opt/smsgui/config/config.php`, `/etc/smsgui/` i `/var/lib/smsgui/`.

**Odinstalowanie:**

```bash
sudo /opt/smsgui/deploy/uninstall.sh            # usuwa panel, zostawia dane (baza smsgui, /var/lib/smsgui)
sudo /opt/smsgui/deploy/uninstall.sh --purge    # także dane panelu, bazę smsgui i jej konta
```

Gammu SMSD i baza `gammu` działają dalej; z `gammu-smsdrc` usuwane są tylko ustawienia dodane przez panel.

## Narzędzie konsolowe

Uruchamiane jako `www-data`: `sudo -u www-data php /opt/smsgui/bin/smsgui <polecenie>`.

| Polecenie | Działanie |
|-----------|-----------|
| `check [--quiet]` | diagnostyka (baza, schemat Gammu, silnik tabel, konfiguracja, usługa, modem, kolejka); kod ≠ 0 przy błędzie – nadaje się do monitoringu |
| `passwd [login]` | ustawienie loginu i hasła (np. zapomniane hasło) |
| `send <numer> <treść> [--wait=N]` | wysyłka testowa, opcjonalnie z czekaniem na wynik |
| `cleanup --days=N` | usunięcie historii starszej niż N dni |
| `worker` / `sync` | proces w tle / jednorazowa synchronizacja |
| `setup db` / `setup gammu` / `setup unhook` | kroki instalatora i dezinstalatora |
| `hook call --phone=ID [numer]` | zapis połączenia przychodzącego (wywołuje Gammu przez `RunOnIncomingCall`) |

## Rozwiązywanie problemów

| Objaw | Co sprawdzić |
|-------|--------------|
| Ostrzeżenia na pulpicie | każde ma podpowiedź; to samo w konsoli: `sudo -u www-data php /opt/smsgui/bin/smsgui check` |
| Usługa Gammu `failed` | `journalctl -u gammu-smsd -n 50` i `/var/log/gammu-smsd/smsd.log` – najczęściej port modemu lub PIN |
| Modem się nie zgłasza | `ls -l /dev/serial/by-id/`, `gammu identify` na wybranym porcie; po podłączeniu modemu uruchom instalator ponownie |
| Wiadomości stoją w kolejce | czy Gammu działa i modem jest zalogowany do sieci (ekran Modem), log Gammu |
| Błąd 502 w przeglądarce | `systemctl status php8.5-fpm`; gniazdo w `/etc/nginx/sites-available/smsgui` musi mieć wersję jak `php -v` |
| Panel nie pokazuje nowych SMS | `systemctl status smsgui-worker` (proces w tle) |
| Zapomniane hasło | `sudo -u www-data php /opt/smsgui/bin/smsgui passwd` |
| Problem z instalacją | `/var/log/smsgui-install.log` (wyjście apt) |

Do zgłoszenia problemu przydaje się raport `sudo bash /opt/smsgui/deploy/collect-info.sh > info.txt 2>&1` –
skrypt tylko czyta stan systemu, a hasła i PIN maskuje.

Logi: panel – `/var/lib/smsgui/smsgui.log`, Gammu – `/var/log/gammu-smsd/smsd.log`, proces w tle –
`journalctl -u smsgui-worker`, nginx – `/var/log/nginx/error.log`.

## Rozwój i testy

Praca lokalna (macOS) bez modemu – z symulatorem Gammu SMSD, który zachowuje się jak Gammu 1.42 na bazie `gammu_dev`
(wysyłka, ponowienia, raporty doręczenia, odbiór wieloczęściowy, USSD, połączenia, czarna lista).
Wymagane PHP 8.5 i MariaDB z Homebrew (`brew install php mariadb@11.8`); MariaDB działa jako osobna instancja
na porcie 3307 (dane w `~/.local/share/smsgui-mariadb`), więc nie koliduje z innym serwerem MySQL.

```bash
tests/db/mariadb.sh start                           # MariaDB na 127.0.0.1:3307 (stop | status | client [baza])
SIM_DB_PORT=3307 php tests/sim/gammu-sim.php init   # var/dev/, bazy *_dev i *_test, config/config.php
php bin/smsgui setup db && php bin/smsgui passwd admin
php tests/sim/gammu-sim.php run                     # „demon” Gammu (osobny terminal)
php bin/smsgui worker                               # proces w tle (osobny terminal)
php -S 127.0.0.1:8080 -t public                     # panel: http://127.0.0.1:8080
php tests/run.php && node tests/js/run.mjs          # testy PHP i zgodność licznika SMS w JS
```

Symulator: `receive <numer> <treść> [--parts=N] [--incomplete] [--flash]`, `external <numer> <treść>`, `call <numer>`;
numery kończące się na `000` – nieudana wysyłka, na `999` – raport „niedoręczona”; `*100#` – menu operatora.

Struktura katalogów:

| Katalog | Zawartość |
|---------|-----------|
| `public/` | front controller `index.php` i zasoby statyczne (CSS, JS, biblioteki) – jedyny katalog widoczny przez WWW |
| `src/` | logika panelu (klasy) i obsługa ekranów (`src/pages/`) |
| `views/` | szablony HTML |
| `resources/` | teksty interfejsu (`lang/pl.php`, `lang/en.php`) i ikony |
| `bin/smsgui` | narzędzie konsolowe |
| `config/` | `config.example.php` (wartości domyślne) i lokalny `config.php` |
| `deploy/` | instalator, dezinstalator, szablony nginx, systemd, logrotate, sudoers, fail2ban, schemat SQL |
| `tests/` | testy, symulator Gammu, lokalna MariaDB |

## Licencje

Copyright © 2026 Krzysiek Janiczek

Gammu SMSD Web UI jest wolnym oprogramowaniem: możesz je rozpowszechniać i modyfikować na warunkach
[Powszechnej Licencji Publicznej GNU w wersji 3](LICENSE) (GPL-3.0) opublikowanej przez Free Software Foundation.
Program jest rozpowszechniany bez jakiejkolwiek gwarancji – szczegóły w pliku [LICENSE](LICENSE).

Dołączone biblioteki: [htmx](https://htmx.org) (0BSD), [Pico.css](https://picocss.com) (MIT), czcionki IBM Plex (OFL).
Schemat bazy `deploy/sql/gammu-mysql-17.sql` na podstawie Gammu (GPL-2.0).

**Ikony:** [Solar Icon Set](https://icon-sets.iconify.design/solar/) – autor 480 Design, licencja
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) (pliki w `resources/icons/`, atrybucja także w stopce panelu).
