# 1. Założenia i architektura

## 1.1. Cel

Panel WWW, który pozwala bez logowania się na serwer przez SSH:

- wysyłać SMS do jednego lub wielu odbiorców,
- przeglądać wiadomości odebrane i wysłane (również w formie rozmów),
- prowadzić książkę telefoniczną z grupami,
- zmieniać konfigurację smstools3 i restartować usługę,
- podglądać stan usługi i log.

## 1.2. Zakres

**W zakresie:** wszystko z listy powyżej, logowanie (jedno konto), instalator dla Ubuntu,
dokumentacja wdrożenia; w etapie końcowym rozszerzenia z [08-pomysly.md](08-pomysly.md#82-przyjęte--etap-końcowy-rozszerzenia)
(m.in. przekazywanie SMS, proste HTTP API, autoodpowiedzi, stan modemu).

**Poza zakresem:** wielu użytkowników i role, obsługa MMS, sterowanie modemem dowolnymi komendami AT
z poziomu panelu, PWA, funkcje RODO.

## 1.3. Zasada działania

smstools3 (`smsd`) nie ma API – komunikuje się wyłącznie przez **pliki tekstowe w katalogach
kolejek**. Panel nie rozmawia z modemem ani z procesem `smsd` bezpośrednio; robi tylko dwie rzeczy:

1. **Wysyłka** – zapisuje plik z wiadomością do katalogu `outgoing`.
2. **Synchronizacja** – czyta pliki z katalogów `incoming`, `sent` i `failed`
   i przenosi ich treść oraz status do bazy SQLite.

Dzięki temu panel jest niezależny od smsd: awaria panelu nie zatrzymuje bramki,
a restart smsd nie psuje panelu.

```
                    ┌──────────────────────────── serwer Ubuntu ────────────────────────────┐
                    │                                                                        │
 przeglądarka ──────┼─► nginx ─► PHP-FPM (www-data)                                          │
   (HTTPS)          │              │   │                                                     │
                    │              │   ├─► SQLite  /var/lib/smsgui/smsgui.sqlite             │
                    │              │   │                                                     │
                    │              │   ├─► zapis  ──► /var/spool/sms/outgoing ──┐            │
                    │              │   │                                        ▼            │
                    │              │   │                                  smsd (smstools)    │
                    │              │   │                                     │      ▲        │
                    │              │   ├─◄ odczyt ── /var/spool/sms/incoming ◄┘      │        │
                    │              │   ├─◄ odczyt ── /var/spool/sms/sent  ◄─────────┤        │
                    │              │   ├─◄ odczyt ── /var/spool/sms/failed ◄────────┘        │
                    │              │   │                                       modem GSM     │
                    │              │   ├─► odczyt/zapis /etc/smsd.conf (+ kopie zapasowe)    │
                    │              │   └─► sudo systemctl restart smstools (tylko to)        │
                    │                                                                        │
                    │  systemd .path + timer (30 s) ─► php bin/smsgui sync  (jako www-data)  │
                    └────────────────────────────────────────────────────────────────────────┘
```

Synchronizacja uruchamia się na trzy sposoby:

- **natychmiast po zmianie w katalogach** `incoming`/`sent`/`failed` – jednostka systemd `.path`,
- **timer systemd** co 30 s – wysyłka planowana/dławiona i zabezpieczenie,
- **przy odświeżeniu strony** w panelu, nie częściej niż co 10 s.

Wszystkie ścieżki używają tej samej blokady pliku (`flock`), więc nigdy nie działają równolegle.

## 1.4. Stos technologiczny

| Warstwa | Wybór | Uzasadnienie |
|---------|-------|--------------|
| System | Ubuntu Server LTS (aktualna: 26.04) | wymóg |
| Serwer WWW | nginx + PHP-FPM | lekki, standardowy (decyzja D3) |
| Backend | PHP ≥ 8.5 (bez wstecznej zgodności ze starszymi wersjami), bez frameworka i bez Composera | pakiety tylko z repozytorium Ubuntu: `php-fpm`, `php-sqlite3`, `php-mbstring`, `php-cli`; łatwe utrzymanie |
| Baza | SQLite 3 (PDO), tryb WAL | brak osobnego serwera bazy; wystarczy dla dziesiątek tysięcy wiadomości |
| Frontend | HTML renderowany po stronie serwera + **htmx** + **Pico.css** + trochę własnego JS (licznik znaków) | decyzja D6; pliki bibliotek w repozytorium (`public/assets/vendor/`), bez CDN – działa w sieci bez Internetu |
| Czcionki | IBM Plex Sans (zmienna, wszystkie grubości) i IBM Plex Mono 400, licencja OFL | zgodnie z makietami; pliki woff2 w `public/assets/fonts/` (ok. 105 kB), bez Google Fonts – działa bez Internetu i zgodnie z CSP |
| Ikony | [Solar Icon Set](https://icon-sets.iconify.design/solar/), styl *linear* (CC BY 4.0, autor: 480 Design) | spójny, czytelny zestaw; potrzebne ikony (ok. 25) zapisane lokalnie jako SVG i wstawiane inline funkcją `icon('nazwa')` – kolor dziedziczony z tekstu, działa bez Internetu. Atrybucja w README i w stopce panelu |
| SMS | smstools3 z pakietu `smstools` | wymóg |

### Czy framework się opłaca?

**Backend – nie.** Laravel/Symfony przy kilkunastu ekranach dodają setki zależności z Composera,
które trzeba aktualizować, a nie rozwiązują trudnych części tego projektu (format plików smsd,
kodowanie SMS, uprawnienia). Wystarczy kilka prostych klas i jeden plik `index.php` przekierowujący
do stron (patrz niżej).

**Frontend – dwie małe biblioteki, każda w jednym pliku, bez npm i bez budowania (decyzja D6):**

| Biblioteka | Rozmiar | Co upraszcza |
|------------|---------|--------------|
| [htmx](https://htmx.org) | ~16 kB | dynamika bez pisania JavaScriptu: odświeżanie rozmowy co kilka sekund, wysłanie odpowiedzi bez przeładowania strony, wyszukiwanie kontaktów podczas pisania, odświeżanie licznika nieprzeczytanych. Serwer zwraca gotowe fragmenty HTML – ta sama logika PHP co dla całych stron |
| [Pico.css](https://picocss.com) | ~80 kB | estetyczny, responsywny wygląd zwykłych znaczników HTML (formularze, tabele, okna dialogowe, tryb ciemny) bez pisania własnych klas CSS – kilkadziesiąt linii własnego stylu zamiast kilkuset |

Bez nich da się zrobić to samo czystym JS i własnym CSS – kosztem ok. 300–500 linii więcej kodu do
utrzymania. Własny JS zostaje tylko tam, gdzie liczy się natychmiastowa reakcja przy pisaniu
(licznik znaków i części SMS).

## 1.5. Struktura katalogów (docelowa)

```
smstools3-web-gui/
├── public/                 ← jedyny katalog widoczny z WWW (document root)
│   ├── index.php           ← front controller (routing ?p=…)
│   └── assets/             ← app.css, app.js, vendor/ (htmx, Pico.css)
├── resources/
│   ├── icons/              ← ikony Solar (SVG) + LICENSE (CC BY 4.0)
│   └── lang/pl.php, en.php ← teksty interfejsu (polski, angielski)
├── src/
│   ├── bootstrap.php       ← autoload, konfiguracja, strefa czasowa
│   ├── helpers.php         ← e(), url(), csrf, flash, render…
│   ├── Db.php              ← połączenie PDO + migracje
│   ├── Auth.php            ← logowanie, sesje
│   ├── Phone.php           ← normalizacja i formatowanie numerów
│   ├── SmsText.php         ← GSM-7 / UCS-2, liczenie części, transliteracja
│   ├── Spool.php           ← zapis do outgoing, parsowanie plików smsd
│   ├── Sync.php            ← algorytm synchronizacji (rozdz. 3.6)
│   ├── Outbox.php          ← tworzenie wiadomości (pojedyncze, masowe, planowane)
│   ├── Batch.php           ← raport wysyłki do wielu odbiorców
│   ├── Contacts.php        ← książka telefoniczna, grupy, import/eksport CSV
│   ├── Recipients.php      ← odbiorcy wysyłki, personalizacja, dławienie, cisza nocna
│   ├── SmsdConf.php        ← parser/edytor smsd.conf z zachowaniem komentarzy, kopie zapasowe
│   ├── SmsdConfForm.php    ← pola formularza konfiguracji
│   ├── Diff.php            ← różnice między wersjami pliku
│   ├── Messages.php        ← czyszczenie historii
│   ├── Mailer.php, Channels.php, Forwarding.php, Notifications.php, Api.php   ← rozszerzenia (etap 7)
│   ├── AutoReplies.php, ModemStatus.php, Stats.php                            ← rozszerzenia (etap 8)
│   ├── Service.php         ← status i restart usługi, odczyt logu
│   ├── Health.php          ← kontrola zdrowia (pulpit i `check`)
│   └── pages/              ← logika poszczególnych ekranów
├── views/                  ← szablony HTML (PHP)
├── lib/PHPMailer/          ← PHPMailer (e-mail, LGPL 2.1) – jedyna biblioteka PHP z zewnątrz
├── bin/smsgui              ← narzędzie CLI: sync, passwd, check, cleanup
├── config/
│   ├── config.example.php  ← wartości domyślne (w repozytorium)
│   └── config.php          ← lokalne nadpisania (poza repozytorium)
├── deploy/                 ← install.sh, konfiguracja nginx, sudoers, unity systemd
├── tests/                  ← testy (php tests/run.php) + przykładowe pliki smsd
│   └── sim/                ← symulator smsd do pracy lokalnej
├── var/                    ← dane środowiska deweloperskiego (poza repozytorium)
└── docs/                   ← ta dokumentacja
```

Dane (baza, kopie konfiguracji) leżą **poza katalogiem aplikacji**: `/var/lib/smsgui/`.
Dzięki temu aktualizacja aplikacji = podmiana katalogu z kodem.

## 1.6. Konfiguracja aplikacji

Plik `config/config.php` (tablica PHP), nakładany na `config.example.php`. Najważniejsze klucze:

| Klucz | Domyślnie | Opis |
|-------|-----------|------|
| `db_path` | `/var/lib/smsgui/smsgui.sqlite` | ścieżka bazy |
| `log_path` | `/var/lib/smsgui/smsgui.log` | log aplikacji (błędy synchronizacji, nieudane logowania dla fail2ban) |
| `backup_dir` | `/var/lib/smsgui/backups` | kopie zapasowe `smsd.conf` |
| `session_path` | `/var/lib/smsgui/sessions` | własny katalog sesji – systemowe czyszczenie sesji w Ubuntu usuwa je po 24 min, niezależnie od ustawienia wygasania w panelu |
| `smsd_conf` | `/etc/smsd.conf` | konfiguracja smstools |
| `spool.*` | `null` | ścieżki katalogów kolejek; `null` = odczytaj z `smsd.conf` |
| `smsd_log` | `null` | log smsd; `null` = parametr `logfile` z `smsd.conf` |
| `processed_files` | `keep` | `keep` / `delete` – co robić z plikami po imporcie |
| `default_country_code` | `48` | prefiks dodawany do numerów krajowych |
| `national_number_length` | `9` | długość numeru krajowego |
| `max_recipients` | `500` | limit odbiorców jednej wysyłki |
| `service.status_cmd` | `systemctl is-active smstools` | sprawdzenie stanu usługi |
| `service.restart_cmd` | `sudo -n /usr/bin/systemctl restart smstools` | restart usługi |
| `timezone` | `Europe/Warsaw` | strefa czasowa |
| `debug` | `false` | szczegóły błędów w przeglądarce (tylko środowisko deweloperskie) |

Ścieżki i polecenia systemowe są **tylko w pliku** – nie da się ich zmienić z panelu
(inaczej panel stałby się furtką do wykonania dowolnego polecenia na serwerze).
Ustawienia „biznesowe” (np. stopka SMS, retencja) mogą być w tabeli `settings` i edytowalne z WWW.
