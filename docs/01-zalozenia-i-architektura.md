# 1. Założenia i architektura

## 1.1. Cel

Panel WWW, który pozwala **wyłącznie przez przeglądarkę** – bez logowania się na serwer przez SSH (decyzja D20):

- wysyłać SMS do jednego lub wielu odbiorców,
- przeglądać wiadomości odebrane i wysłane (również w formie rozmów),
- prowadzić książkę telefoniczną z grupami,
- zmieniać konfigurację Gammu SMSD i restartować usługę,
- podglądać stan usługi, stan modemu i log,
- wysyłać kody USSD, przeglądać odrzucone połączenia i zarządzać czarną listą numerów.

## 1.2. Zakres

**W zakresie:** wszystko z listy powyżej, logowanie (jedno konto), instalator dla Ubuntu,
dokumentacja wdrożenia; w etapie końcowym rozszerzenia z [08-pomysly.md](08-pomysly.md#82-przyjęte--etap-końcowy-rozszerzenia)
(m.in. przekazywanie SMS, proste HTTP API, autoodpowiedzi, statystyki, kilka modemów).

**Poza zakresem:** wielu użytkowników i role, obsługa MMS, sterowanie modemem dowolnymi komendami AT
z poziomu panelu, odbieranie i prowadzenie rozmów głosowych, PWA, funkcje RODO.

Konsola serwera potrzebna jest tylko do **instalacji** (jedno polecenie) i w sytuacjach awaryjnych
(zapomniane hasło, panel nie działa). Wszystkie czynności codzienne i administracyjne są dostępne w panelu.

## 1.3. Zasada działania

Gammu SMSD (`gammu-smsd`) to demon obsługujący modem. W konfiguracji z backendem **SQL** (decyzja D18)
komunikuje się ze światem wyłącznie przez **tabele w bazie MariaDB**:

| Tabela Gammu | Kto pisze | Kto czyta | Rola |
|--------------|-----------|-----------|------|
| `outbox`, `outbox_multipart` | panel | Gammu | wiadomości (i kody USSD) do wysłania; Gammu usuwa wiersz po wysyłce |
| `sentitems` | Gammu | panel | wynik wysyłki każdej części, później wynik raportu doręczenia |
| `inbox` | Gammu | panel | odebrane SMS i odpowiedzi USSD |
| `phones` | Gammu | panel | stan modemu: sygnał, operator, IMEI, IMSI, liczniki |

Panel nie rozmawia z modemem ani z procesem Gammu bezpośrednio. Robi tylko dwie rzeczy:

1. **Wysyłka** – zapisuje wiadomość (podzieloną na części) do `outbox` / `outbox_multipart`.
2. **Synchronizacja** – proces w tle (`smsgui-worker`) co kilka sekund przenosi nowe wiersze z `inbox`
   oraz zmiany w `sentitems` i `phones` do tabel panelu.

Dzięki temu panel jest niezależny od Gammu: awaria panelu nie zatrzymuje bramki (wiadomości czekają
w tabelach), a restart Gammu nie psuje panelu.

```
                    ┌──────────────────────────── serwer Ubuntu ─────────────────────────────┐
                    │                                                                         │
 przeglądarka ──────┼─► nginx ─► PHP-FPM (www-data)                                           │
   (HTTPS)          │              │                                                          │
                    │              ├─► MariaDB ─┬─ baza smsgui (dane panelu)                   │
                    │              │            └─ baza gammu  (outbox, inbox, sentitems,     │
                    │              │                  ▲        phones)                        │
                    │              │                  │ SQL                                   │
                    │              │            gammu-smsd ◄──► modem GSM                      │
                    │              │                  │                                       │
                    │              │                  └─ RunOnIncomingCall ─► bin/smsgui hook  │
                    │              │                                                          │
                    │              ├─► odczyt/zapis /etc/gammu-smsdrc (+ kopie zapasowe)       │
                    │              ├─► odczyt/zapis lista zablokowanych numerów               │
                    │              └─► sudo systemctl reload|restart gammu-smsd (tylko to)    │
                    │                                                                         │
                    │  smsgui-worker.service ─► php bin/smsgui worker (pętla co 3 s, www-data) │
                    └─────────────────────────────────────────────────────────────────────────┘
```

Synchronizacja (szczegóły w [03, rozdz. 3.7](03-integracja-gammu-smsd.md#37-synchronizacja--proces-w-tle)):

- **proces w tle** `smsgui-worker` (usługa systemd, `Restart=always`) – pętla co 3 s,
- **przy odświeżeniu strony** w panelu, nie częściej niż co 10 s – zabezpieczenie, gdy proces w tle nie działa.

Oba wywołania używają tej samej blokady (`GET_LOCK()` w MariaDB), więc nigdy nie działają równolegle.

Dlaczego nie skrypty `RunOnReceive` / `RunOnSent` Gammu: raporty doręczenia nie mają własnego
zdarzenia (Gammu tylko aktualizuje `sentitems`), więc i tak trzeba by odpytywać bazę.
Zapytania po indeksach co 3 s są pomijalnym obciążeniem, a jeden mechanizm jest prostszy niż dwa.
Wyjątek: połączenia przychodzące – Gammu nie zapisuje ich w bazie, jedyną drogą jest `RunOnIncomingCall`
([03, rozdz. 3.11](03-integracja-gammu-smsd.md#311-połączenia-przychodzące)).

## 1.4. Stos technologiczny

| Warstwa | Wybór | Uzasadnienie |
|---------|-------|--------------|
| System | Ubuntu Server LTS (aktualna: 26.04) | wymóg |
| Obsługa modemu | Gammu SMSD z pakietu `gammu-smsd` (w Ubuntu 26.04: **1.42.0**, schemat bazy w wersji 17) | decyzja D19; aktualizacje bezpieczeństwa z repozytorium systemu |
| Baza | **MariaDB** z repozytorium Ubuntu, silnik InnoDB; dwie bazy: `gammu` i `smsgui` | decyzja D18; najlepiej sprawdzony backend Gammu, bezpieczne jednoczesne zapisy demona i panelu, transakcje |
| Serwer WWW | nginx + PHP-FPM | lekki, standardowy (decyzja D3) |
| Backend | PHP ≥ 8.5 (bez wstecznej zgodności), bez frameworka i bez Composera | pakiety tylko z repozytorium Ubuntu: `php-fpm`, `php-mysql`, `php-mbstring`, `php-cli`; łatwe utrzymanie |
| Frontend | HTML renderowany po stronie serwera + **htmx** + **Pico.css** + trochę własnego JS (licznik znaków) | decyzja D6; pliki bibliotek w repozytorium (`public/assets/vendor/`), bez CDN – działa w sieci bez Internetu |
| Czcionki | IBM Plex Sans (zmienna) i IBM Plex Mono 400, licencja OFL | pliki woff2 w `public/assets/fonts/`, bez Google Fonts – działa bez Internetu i zgodnie z CSP |
| Ikony | [Solar Icon Set](https://icon-sets.iconify.design/solar/), styl *linear* (CC BY 4.0, autor: 480 Design) | ok. 30 ikon zapisanych lokalnie jako SVG, wstawianych inline funkcją `icon('nazwa')`. Atrybucja w README i w stopce panelu |

### Czy PHP i JS to dobry wybór?

Tak – dla tej aplikacji to rozsądny wybór. Panel to w praktyce formularze i listy nad bazą danych
plus jedna pętla synchronizacji. Najtrudniejsze części projektu to kodowanie SMS, dzielenie na części,
format konfiguracji Gammu i uprawnienia, a nie wydajność ani skomplikowany interfejs.

| Kryterium | PHP 8.5 + htmx (wybór) | Python (FastAPI/Django) | Go | Node.js |
|-----------|------------------------|-------------------------|----|---------|
| Instalacja na Ubuntu | wszystko z `apt`, bez budowania | `apt` + virtualenv/pip | jeden plik binarny, ale trzeba go zbudować i publikować wydania | npm, zależności z Internetu |
| Aktualizacje bezpieczeństwa | razem z systemem (`apt upgrade`) | częściowo z pip | przebudowa przy każdej poprawce biblioteki | częste aktualizacje npm |
| Hosting panelu | nginx + PHP-FPM – standard | potrzebny serwer aplikacji (gunicorn/uvicorn) | wbudowany serwer | wbudowany serwer |
| Proces w tle | PHP CLI w pętli – wystarczy | naturalny | naturalny | naturalny |
| Nakład kodu | mały (htmx zamiast frameworka JS) | podobny | większy (szablony, migracje ręcznie) | podobny, ale więcej zależności |

Alternatywą wartą uwagi byłby **Go** (jeden plik, wbudowany serwer i proces w tle), ale kosztem
budowania i publikowania wydań oraz ręcznych aktualizacji. Python dawałby dostęp do `python-gammu`,
ale panel celowo nie rozmawia z modemem – robi to demon. JavaScript zostaje tam, gdzie jest
niezbędny: licznik znaków na żywo i drobne interakcje; resztę dynamiki zapewnia htmx bez pisania JS.

### Czy framework się opłaca?

**Backend – nie.** Laravel/Symfony przy kilkunastu ekranach dodają setki zależności z Composera,
które trzeba aktualizować, a nie rozwiązują trudnych części tego projektu (kodowanie i dzielenie SMS,
format tabel Gammu, uprawnienia). Wystarczy kilka prostych klas i jeden plik `index.php`
przekierowujący do stron.

**Frontend – dwie małe biblioteki, każda w jednym pliku, bez npm i bez budowania (decyzja D6):**

| Biblioteka | Rozmiar | Co upraszcza |
|------------|---------|--------------|
| [htmx](https://htmx.org) | ~16 kB | dynamika bez pisania JavaScriptu: odświeżanie rozmowy co kilka sekund, wysłanie odpowiedzi bez przeładowania strony, wyszukiwanie kontaktów podczas pisania, oczekiwanie na odpowiedź USSD. Serwer zwraca gotowe fragmenty HTML – ta sama logika PHP co dla całych stron |
| [Pico.css](https://picocss.com) | ~80 kB | estetyczny, responsywny wygląd zwykłych znaczników HTML (formularze, tabele, okna dialogowe, tryb ciemny) bez pisania własnych klas CSS |

## 1.5. Struktura katalogów (docelowa)

```
gammu-smsd-web-ui/
├── public/                 ← jedyny katalog widoczny z WWW (document root)
│   ├── index.php           ← front controller (routing ?p=…)
│   └── assets/             ← app.css, app.js, sms-text.js, vendor/ (htmx, Pico.css), fonts/
├── resources/
│   ├── icons/              ← ikony Solar (SVG) + LICENSE (CC BY 4.0)
│   └── lang/pl.php, en.php ← teksty interfejsu (polski, angielski)
├── src/
│   ├── bootstrap.php       ← autoload, konfiguracja, strefa czasowa
│   ├── helpers.php         ← e(), url(), t(), icon(), csrf, flash, render…
│   ├── Db.php              ← połączenie PDO (MariaDB) + migracje bazy panelu
│   ├── Auth.php            ← logowanie, sesje
│   ├── Phone.php           ← normalizacja i formatowanie numerów
│   ├── SmsText.php         ← GSM-7 / UCS-2, liczenie części, transliteracja
│   ├── SmsSplit.php        ← podział na części z nagłówkiem UDH (rozdz. 3.3)
│   ├── GammuDb.php         ← zapis do outbox/outbox_multipart, odczyt inbox/sentitems/phones
│   ├── Sync.php            ← algorytm synchronizacji (rozdz. 3.7)
│   ├── Outbox.php          ← tworzenie wiadomości (pojedyncze, masowe, planowane), ponów, anuluj
│   ├── Batch.php           ← raport wysyłki do wielu odbiorców
│   ├── Contacts.php        ← książka telefoniczna, grupy, import/eksport CSV
│   ├── Recipients.php      ← odbiorcy wysyłki, personalizacja, dławienie, okno wysyłki
│   ├── GammuConf.php       ← parser/edytor gammu-smsdrc z zachowaniem komentarzy, kopie zapasowe
│   ├── GammuConfForm.php   ← pola formularza konfiguracji
│   ├── Blocklist.php       ← czarna lista numerów (plik ExcludeNumbersFile)
│   ├── Ussd.php            ← kody USSD na żądanie i cykliczne sprawdzanie salda
│   ├── Calls.php           ← połączenia przychodzące (zapis z hooka, lista)
│   ├── Diff.php            ← różnice między wersjami pliku
│   ├── Messages.php        ← czyszczenie historii
│   ├── Mailer.php, Channels.php, Forwarding.php, Notifications.php, Api.php   ← rozszerzenia (etap 7)
│   ├── AutoReplies.php, Stats.php, Modems.php                                 ← rozszerzenia (etap 8)
│   ├── Service.php         ← status, przeładowanie i restart usługi, odczyt logu
│   ├── Health.php          ← kontrola zdrowia (pulpit i `check`)
│   └── pages/              ← logika poszczególnych ekranów
├── views/                  ← szablony HTML (PHP)
├── lib/PHPMailer/          ← PHPMailer (e-mail, LGPL 2.1) – jedyna biblioteka PHP z zewnątrz
├── bin/smsgui              ← narzędzie CLI: worker, sync, passwd, check, cleanup, hook, setup
├── config/
│   ├── config.example.php  ← wartości domyślne (w repozytorium)
│   └── config.php          ← lokalne nadpisania (poza repozytorium)
├── deploy/                 ← install.sh, nginx, sudoers, unity systemd, logrotate, fail2ban, SQL
├── tests/                  ← testy (php tests/run.php) + przykładowe wiersze tabel Gammu
│   └── sim/                ← symulator gammu-smsd do pracy lokalnej
├── prototype/              ← statyczny prototyp HTML interfejsu (etap P, decyzja D23) – wzór dla views/
├── var/                    ← dane środowiska deweloperskiego (poza repozytorium)
└── docs/                   ← ta dokumentacja
```

Dane panelu leżą w bazie MariaDB, a kopie konfiguracji i pliki pomocnicze **poza katalogiem aplikacji**:
`/var/lib/smsgui/`. Dzięki temu aktualizacja aplikacji = podmiana katalogu z kodem.

## 1.6. Konfiguracja aplikacji

Plik `config/config.php` (tablica PHP), nakładany na `config.example.php`. Najważniejsze klucze:

| Klucz | Domyślnie | Opis |
|-------|-----------|------|
| `db.dsn` | `mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=smsgui;charset=utf8mb4` | baza panelu |
| `db.user`, `db.password` | `smsgui`, — | konto panelu w MariaDB (hasło generuje instalator) |
| `gammu_db` | `gammu` | nazwa bazy Gammu (ten sam serwer, to samo konto panelu) |
| `log_path` | `/var/lib/smsgui/smsgui.log` | log aplikacji (błędy synchronizacji, nieudane logowania dla fail2ban) |
| `backup_dir` | `/var/lib/smsgui/backups` | kopie zapasowe `gammu-smsdrc` i listy zablokowanych |
| `session_path` | `/var/lib/smsgui/sessions` | własny katalog sesji – systemowe czyszczenie sesji w Ubuntu usuwa je po 24 min |
| `gammu_conf` | `/etc/gammu-smsdrc` | konfiguracja Gammu SMSD |
| `blocklist_file` | `/var/lib/smsgui/exclude-numbers.txt` | plik `ExcludeNumbersFile` (czarna lista) |
| `gammu_log` | `null` | log Gammu; `null` = parametr `logfile` z `gammu-smsdrc` |
| `creator_id` | `smsgui` | wartość `CreatorID` wiadomości z panelu (odróżnia je od „zewnętrznych”) |
| `gammu_rows` | `keep` | `keep` / `delete` – co robić z wierszami `inbox`/`sentitems` po imporcie |
| `worker_interval` | `3` | co ile sekund proces w tle synchronizuje dane |
| `default_country_code` | `48` | prefiks dodawany do numerów krajowych |
| `national_number_length` | `9` | długość numeru krajowego |
| `max_recipients` | `500` | limit odbiorców jednej wysyłki |
| `service.status_cmd` | `systemctl is-active gammu-smsd` | sprawdzenie stanu usługi |
| `service.reload_cmd` | `sudo -n /usr/bin/systemctl reload gammu-smsd` | przeładowanie konfiguracji (SIGHUP) |
| `service.restart_cmd` | `sudo -n /usr/bin/systemctl restart gammu-smsd` | restart usługi |
| `timezone` | `Europe/Warsaw` | strefa czasowa – **musi być zgodna ze strefą serwera**, bo Gammu zapisuje czas lokalny (`NOW()`) |
| `debug` | `false` | szczegóły błędów w przeglądarce (tylko środowisko deweloperskie) |

Ścieżki, dane dostępowe do bazy i polecenia systemowe są **tylko w pliku** – nie da się ich zmienić z panelu
(inaczej panel stałby się furtką do wykonania dowolnego polecenia na serwerze). Panel wyświetla je do odczytu
(bez haseł). Ustawienia „biznesowe” (np. stopka SMS, retencja, kod USSD salda) są w tabeli `settings`
i edytowalne z WWW.
