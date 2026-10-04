# 9. Plan implementacji

Rozpisanie etapów z [07-plan-realizacji.md](07-plan-realizacji.md) na konkretne zadania, pliki i testy.
Plik 07 pozostaje przeglądem (zakres + kryterium odbioru), ten dokument jest listą roboczą.
Odhaczane zadania oznaczamy `[x]`. Projekt powstaje **od zera** – kod poprzedniego panelu dla smstools3
nie jest przenoszony (rozwiązania sprawdzone w tamtym projekcie są opisane w tej dokumentacji).

## 9.1. Zasady pracy

| Temat | Ustalenie |
|-------|-----------|
| Środowisko (etapy 1–5) | **lokalnie na Macu**: PHP i MariaDB z Homebrew, wbudowany serwer `php -S`, symulator Gammu SMSD pracujący na bazie `gammu_dev`. Ubuntu dopiero w etapie 6 |
| Git | commity bezpośrednio na gałęzi `dev`, jeden commit na zadanie (lub kilka małych); `main` – tylko wydania (`v1.0` po etapie 6) |
| Tryb | etap realizowany w całości (kod + testy), potem **przegląd i akceptacja** przed kolejnym etapem |
| Definicja „gotowe” | `php tests/run.php` przechodzi, scenariusz odbioru etapu wykonany ręcznie, dokumentacja zgodna z kodem |
| Zależności | bez Composera i npm; jedyne pliki zewnętrzne: htmx, Pico.css, ikony Solar, czcionki IBM Plex, PHPMailer (etap 7) |

### Wersja PHP
Docelowo i lokalnie **PHP 8.5** – bez wstecznej zgodności ze starszymi wersjami. Można swobodnie
używać nowości z 8.4 i 8.5, gdy realnie upraszczają kod (np. `array_find()`, property hooks,
`#[\NoDiscard]`), ale bez sztuki dla sztuki – czytelność ważniejsza.

### Pułapki wynikające z CSP (`default-src 'self'`)
- **Żadnego JS ani CSS inline** – także atrybutów `style="…"` i `onclick`. Cały styl w `app.css`,
  cały skrypt w plikach `.js`.
- htmx: konfiguracja w `<meta name="htmx-config" content='{"includeIndicatorStyles":false,"allowEval":false}'>`
  (inaczej htmx wstrzykuje `<style>` i używa `eval`); nie używamy `hx-on:*`.
- Okna potwierdzenia: element `<dialog>` + kilka linii w `app.js` (bez `hx-confirm` z natywnym `confirm()`).

### Pułapki MariaDB
- DDL zatwierdza transakcję automatycznie – migracje idempotentne ([04, rozdz. 4.3](04-model-danych.md#43-migracje)).
- Transakcje obejmujące `smsgui` i `gammu` – jedno połączenie PDO, nazwy tabel z prefiksem bazy (`gammu.outbox`);
  nazwa bazy Gammu z konfiguracji (`gammu_db`), w testach `gammu_test`.
- `utf8mb4` w połączeniu (`charset=utf8mb4` w DSN) – inaczej emoji zamieniają się w `?`.
- Kolumny Gammu `Status`/`Coding` to `ENUM` – wartości tylko z listy (stałe w `GammuDb`).

### Konwencje kodu
- Każde wyjście przez `e()`; SQL tylko z parametrami.
- Strona = plik `src/pages/<nazwa>.php` + widok `views/<nazwa>.php`. Ta sama strona zwraca
  **fragment** (bez układu), gdy żądanie ma nagłówek `HX-Request` – jedna logika dla całej strony i htmx.
- Teksty interfejsu przez `t('klucz')` z pliku `resources/lang/pl.php` od początku (D10).
- Daty w bazie: `DATETIME`, czas lokalny.
- Kodowanie plików UTF-8, końce linii LF (`.gitattributes`).

## 9.2. Środowisko deweloperskie (Mac)

```
var/dev/                      ← poza repozytorium (.gitignore)
├── gammu-smsdrc              ← przykładowa konfiguracja (service = sql, phoneid = GSM1…)
├── smsd.log                  ← log pisany przez symulator
├── exclude-numbers.txt       ← czarna lista
├── sim.pid                   ← stan „usługi” symulatora
└── backups/                  ← kopie gammu-smsdrc
bazy MariaDB: smsgui_dev, gammu_dev (+ smsgui_test, gammu_test dla testów)
```

```bash
brew install php mariadb && brew services start mariadb
php tests/sim/gammu-sim.php init       # tworzy var/dev/, bazy dev z mysql.sql (InnoDB), config/config.php
php bin/smsgui setup db && php bin/smsgui passwd admin
php tests/sim/gammu-sim.php run        # symulator Gammu SMSD (osobny terminal)
php bin/smsgui worker                  # proces w tle (osobny terminal)
php -S 127.0.0.1:8080 -t public        # panel: http://127.0.0.1:8080
php tests/run.php                      # testy
```

Skrypt `mysql.sql` dla schematu 17 jest dołączony do repozytorium (`deploy/sql/gammu-mysql-17.sql`, licencja GPL-2
jak Gammu, z informacją o źródle) – na Macu nie ma paczki Gammu.

**Symulator Gammu SMSD** (`tests/sim/gammu-sim.php`, powstaje w etapach 1–5) – zachowuje się jak Gammu wg rozdz. 3:

| Polecenie | Działanie |
|-----------|-----------|
| `init` | tworzy `var/dev/`, bazy `smsgui_dev`/`gammu_dev`, przykładową konfigurację, `config/config.php` dla dev |
| `run` | pętla co 2 s: wiersz `phones` (sygnał losowo 40–90 %, `NetName = Symulator`); pobiera z `outbox` wiersze wg tych samych warunków co Gammu (czas, okno, `SenderID`, priorytet), blokuje (`SendingTimeOut`), zapisuje części do `sentitems` (`TPMR` 0–255), usuwa z `outbox`. Numery kończące się na `000` – nieudana próba (`Retries`, `StatusCode = 500`), po `MaxRetries` – `SendingError`. Po kilku sekundach raport: `DeliveryOK` (dla `…999` – `DeliveryFailed`, `StatusError = 70`). Wiersze `Class = 127` → odpowiedź USSD w `inbox` („Twoje saldo: 12,34 zł”; kod `*100#` → menu ze `Status = 3`). Pisze linie do `smsd.log` |
| `receive <numer> <treść> [--parts=N] [--incomplete] [--flash]` | odebrany SMS w `inbox` – dla wielu części kolejne wiersze z UDH i sklejonym tekstem w pierwszym (jak Gammu ≥ 1.40); numer z pliku czarnej listy – pominięty z wpisem w logu |
| `external <numer> <treść>` | wiadomość w `outbox` z `CreatorID = inject` – po wysłaniu „zewnętrzna” |
| `call <numer>` | uruchamia `bin/smsgui hook call --phone=GSM1 <numer>` tak jak Gammu (`sh -c`), jeśli w konfiguracji jest `HangupCalls = yes` |
| `status` / `reload` / `restart` | udają `systemctl is-active` / `reload` / `restart` (stan z pliku PID pętli `run`); używane jako `service.*_cmd` w konfiguracji dev |

Prawdziwe wiersze z tabel Gammu (zrzut po testach na modemie) dołączymy do `tests/fixtures/` w etapie 0/6.

## 9.3. Etap 1 – Szkielet

| # | Zadanie | Pliki |
|---|---------|-------|
| [ ] 1.1 | Struktura katalogów, `.gitignore` (`config/config.php`, `var/`), konfiguracja domyślna (wszystkie klucze z rozdz. 1.6) | `config/config.example.php` |
| [ ] 1.2 | Bootstrap: autoloader `src/*.php`, wczytanie i nałożenie konfiguracji, strefa czasowa, obsługa błędów → log aplikacji | `src/bootstrap.php` |
| [ ] 1.3 | Funkcje pomocnicze: `e()`, `url()`, `t()`, `icon()`, `csrf_field()`/`csrf_check()`, `flash()`, `render()`, `redirect()`, `is_htmx()` | `src/helpers.php`, `resources/lang/pl.php` |
| [ ] 1.4 | Baza: PDO MariaDB (utf8mb4, `time_zone`, `sql_mode`), migracje przez `schema_version` z blokadą `GET_LOCK`; migracja 1 = wszystkie tabele podstawowe z rozdz. 4.2 (bez tabel rozszerzeń) | `src/Db.php` |
| [ ] 1.5 | Logowanie: sesja (HttpOnly, SameSite, Secure przy HTTPS), `session_regenerate_id`, blokada 5 prób / 15 min, wygasanie bezczynności, „Zapamiętaj mnie” (ciasteczko podpisane HMAC), wylogowanie, wpis dla fail2ban w logu | `src/Auth.php` |
| [ ] 1.6 | Front controller: nagłówki bezpieczeństwa (rozdz. 5.4), routing `?p=`, wymuszenie logowania, centralne sprawdzanie CSRF dla POST, strona 404 | `public/index.php` |
| [ ] 1.7 | Biblioteki i ikony: htmx 2.x i Pico.css 2.x pobrane raz do repozytorium (z licencjami), ok. 30 ikon Solar *linear* jako SVG + `LICENSE`, czcionki IBM Plex | `public/assets/vendor/`, `resources/icons/`, `public/assets/fonts/` |
| [ ] 1.8 | Układ strony: menu boczne (zwijane na telefonie), licznik nieprzeczytanych, wskaźnik usługi i modemu, komunikaty flash, stopka z atrybucją ikon; jasny/ciemny motyw | `views/layout.php`, `public/assets/app.css`, `public/assets/app.js` |
| [ ] 1.9 | Ekrany: logowanie, pusty pulpit, zmiana hasła (i loginu) | `src/pages/`, `views/` |
| [ ] 1.10 | Odczyt `gammu-smsdrc` (parsowanie: sekcje, parametry bez wielkości liter, komentarze `#`/`;`) – potrzebny już dla `check` | `src/GammuConf.php` (część odczytu) |
| [ ] 1.11 | Stan usługi (`status_cmd`) | `src/Service.php` (część odczytu) |
| [ ] 1.12 | CLI: `passwd [login]`, `setup db`, `check [--quiet]` – lista ✔/✘: PHP i rozszerzenia, konfiguracja, połączenie z bazą, baza `gammu` i `Version = 17`, silnik InnoDB, strefa czasowa bazy, odczyt/zapis `gammu-smsdrc`, `service = sql`, log czytelny, stan usługi; kod wyjścia ≠ 0 przy błędzie; sprawdzenia w `src/Health.php` | `bin/smsgui`, `src/Health.php` |
| [ ] 1.13 | Runner testów bez zależności (`test()`, `assert_same()`, raport, kod wyjścia), baza testowa tworzona i czyszczona przez runner + testy `Db` (migracje na pustej bazie, ponowne uruchomienie), `Auth` (blokada), `GammuConf` (odczyt) | `tests/run.php`, `tests/*Test.php` |
| [ ] 1.14 | Symulator: polecenia `init`, `status`, `reload`, `restart`; skrypt `deploy/sql/gammu-mysql-17.sql` | `tests/sim/gammu-sim.php` |

**Odbiór:** logowanie działa (także blokada po 5 próbach i wylogowanie); POST bez tokenu CSRF → 403;
`php bin/smsgui check` na środowisku dev pokazuje same ✔, a po zamianie tabeli `gammu.outbox` na MyISAM – ✘ z podpowiedzią;
układ działa na komputerze i przy 360 px; konsola przeglądarki bez błędów CSP.

## 9.4. Etap 2 – Rdzeń SMS

| # | Zadanie | Pliki |
|---|---------|-------|
| [ ] 2.1 | `SmsText`: alfabet GSM wg Gammu (podstawowy + rozszerzony, z `¤`, bez `¹`), wybór GSM/UCS2, liczenie jednostek i części (160/153, 70/67, emoji = 2), transliteracja, lista znaków wymuszających Unicode | `src/SmsText.php` |
| [ ] 2.2 | Ta sama logika w JS dla licznika na żywo + **wspólny plik przypadków testowych** dla PHP i JS (`node tests/js/run.mjs` lub strona `tests/js/index.html`) | `public/assets/sms-text.js`, `tests/cases/smstext.json` |
| [ ] 2.3 | `SmsSplit`: podział na części bez rozdzielania znaków rozszerzonych i par zastępczych, UDH `050003RRNNPP`, licznik referencyjny w `settings`, limit 10 części | `src/SmsSplit.php` |
| [ ] 2.4 | `Phone`: normalizacja, numery skrócone, nadawcy alfanumeryczni, format dla Gammu (`+`), formatowanie do wyświetlania | `src/Phone.php` |
| [ ] 2.5 | `GammuDb` – zapis: `outbox` + `outbox_multipart` (kolumny z rozdz. 3.3), zwrot `ID`; odczyt stanu wiadomości (`outbox`, `sentitems`) i łączenie statusów części | `src/GammuDb.php` |
| [ ] 2.6 | `GammuDb` – odczyt `inbox`: okno 2 s, grupowanie części po UDH, sklejanie tekstu, niekompletne, 8bit, flash, `Class = 127` odkładane dla USSD | `src/GammuDb.php` |
| [ ] 2.7 | `Outbox::send()` – jeden odbiorca: rekord w `messages` + wiersze Gammu w jednej transakcji → `queued` (lub `scheduled`) | `src/Outbox.php` |
| [ ] 2.8 | Synchronizacja (rozdz. 3.7, kroki 1–3 i 7 bez automatyzacji): blokada `GET_LOCK`, statusy wysłanych, wiadomości zewnętrzne, import `inbox` z trybami `keep`/`delete`, osobna transakcja na wiadomość, wynik w `settings` | `src/Sync.php` |
| [ ] 2.9 | `bin/smsgui worker` (pętla, zakończenie po 1 h lub zmianie plików) i `sync`; synchronizacja przy odświeżeniu strony (nie częściej niż co 10 s) | `bin/smsgui`, `public/index.php` |
| [ ] 2.10 | Symulator: `run` (wysyłka, ponowienia, `phones`), `receive`, `external` | `tests/sim/gammu-sim.php` |
| [ ] 2.11 | Ekran **Nowa wiadomość** (jeden odbiorca): licznik z wyjaśnieniem kodowania, „Zamień polskie znaki”, raport, flash, walidacja | `src/pages/compose.php`, `views/compose.php` |
| [ ] 2.12 | Ekran **Odebrane**: tabela, filtry (tekst, daty, nieprzeczytane), stronicowanie 50, oznacz przeczytane/nieprzeczytane, usuń | `src/pages/inbox.php` |
| [ ] 2.13 | Ekran **Wysłane**: tabela, statusy z etykietami (także „ponawiana”), filtry (status, tekst, daty, źródło), usuń, kopiuj | `src/pages/sent.php` |
| [ ] 2.14 | Testy: `SmsText` (wszystkie przypadki z rozdz. 7.1), `SmsSplit`, `Phone` (tabela 3.13), `GammuDb` na bazie testowej (zapis wieloczęściowy, statusy części, grupowanie odebranych, zewnętrzne, keep/delete) | `tests/` |

**Odbiór (symulator):** SMS z panelu → `W kolejce` → `Wysłana` w ciągu kilku sekund; 500 znaków z polskimi znakami →
8 wierszy (1 w `outbox` + 7 w `outbox_multipart`) z poprawnym UDH; numer `…000` → `ponawiana` → `Błąd` z opisem;
`gammu-sim receive --parts=3` → jedna wiadomość w „Odebrane”; `gammu-sim external` → wiadomość „zewnętrzna” w „Wysłane”;
„Mój kot” wysłane jako `Unicode_No_Compression`.

## 9.5. Etap 3 – Rozmowy i raporty

| # | Zadanie |
|---|---------|
| [ ] 3.1 | Statusy z raportów doręczenia (`DeliveryOK`/`Failed`/`Pending`/`Unknown` dla wszystkich części), opisy kodów `StatusError` i `StatusCode`, okno 7 dni |
| [ ] 3.2 | Lista rozmów (`MAX(id) GROUP BY phone`), wyszukiwarka, licznik nieprzeczytanych |
| [ ] 3.3 | Widok rozmowy: dymki, statusy, oznaczanie jako przeczytane, nadawcy alfanumeryczni bez pola odpowiedzi, usuń rozmowę |
| [ ] 3.4 | Odpowiedź przez htmx bez przeładowania; odświeżanie fragmentu co 10 s tylko gdy coś się zmieniło (`updated_at`), bez utraty wpisywanego tekstu |
| [ ] 3.5 | Licznik nieprzeczytanych w menu (htmx co 15 s), wskaźnik stanu usługi i modemu |
| [ ] 3.6 | Ponów (nowy wiersz `outbox`, nowy `gammu_id`), Anuluj (`DELETE … WHERE SendingTimeOut < NOW()`, komunikat „jest już wysyłana”) |
| [ ] 3.7 | Wysyłka planowana (`SendingDateTime`) i opcja „Wyślij priorytetowo”; priorytety wg rozdz. 3.6 |
| [ ] 3.8 | Symulator: raporty doręczenia (kod 0, `…999` – `DeliveryFailed`), raporty dla każdej części |
| [ ] 3.9 | Testy: łączenie statusów części, anulowanie (wolny / zablokowany wiersz), ponowienie, przejścia statusów |

**Odbiór:** pełny cykl na symulatorze: zaplanuj → `Zaplanowana` → `W kolejce` → `Wysłana` → `Doręczona`;
numer `…999` → `Niedoręczona` → Ponów; rozmowa odświeża się sama, gdy symulator „odbierze” odpowiedź;
pojedynczy SMS z priorytetem wychodzi przed kolejką 20 wiadomości.

## 9.6. Etap 4 – Książka telefoniczna i wysyłka do wielu

| # | Zadanie |
|---|---------|
| [ ] 4.1 | Kontakty: lista, wyszukiwanie podczas pisania (htmx), filtr po grupie, stronicowanie, dodaj/edytuj/usuń, ekran kontaktu z ostatnimi wiadomościami |
| [ ] 4.2 | Grupy: lista z liczbą członków, dodaj/zmień nazwę/usuń, „Pokaż członków”, „Wyślij do grupy” |
| [ ] 4.3 | Akcje zbiorcze na kontaktach: dodaj do / usuń z grupy, usuń, wyślij SMS do zaznaczonych |
| [ ] 4.4 | Nazwa kontaktu zamiast numeru w całym panelu (funkcja pomocnicza z pamięcią podręczną na żądanie) |
| [ ] 4.5 | Nowa wiadomość – wybór odbiorców: grupy + kontakty + numery ręcznie, suma bez duplikatów, licznik, lista błędnych numerów, `max_recipients`; otwieranie z gotowym wyborem |
| [ ] 4.6 | Szablony: CRUD + wybór na ekranie nowej wiadomości |
| [ ] 4.7 | Personalizacja `{nazwa}`, `{imie}`: podgląd, licznik wg najdłuższego wariantu, ostrzeżenie dla odbiorców spoza książki |
| [ ] 4.8 | Dławienie: rozłożenie `SendingDateTime` wg N SMS/min, szacowany czas wysyłki |
| [ ] 4.9 | Okno wysyłki: `SendAfter`/`SendBefore` dla wysyłek do wielu, informacja o opóźnieniu, „Wyślij od razu”, walidacja okna (bez przejścia przez północ); okno potwierdzenia przy > 1 odbiorcy |
| [ ] 4.10 | Raport wysyłki (`batch_id`): liczniki, pasek postępu (htmx), „Ponów nieudane”, „Anuluj pozostałe” |
| [ ] 4.11 | Import CSV z podglądem i eksport CSV z BOM |
| [ ] 4.12 | Testy: łączenie odbiorców i duplikaty, personalizacja, rozkład dławienia, okno wysyłki, parsowanie CSV |

**Odbiór:** wysyłka do grupy 3+ numerów + 1 duplikat + 1 błędny numer, z `{imie}` i dławieniem 2/min;
raport wysyłki pokazuje każdy status; wysyłka z oknem kończącym się „za minutę” – część wiadomości czeka;
import pliku CSV z Excela z polskimi znakami.

## 9.7. Etap 5 – Konfiguracja, modem, USSD, połączenia, czarna lista

| # | Zadanie |
|---|---------|
| [ ] 5.1 | `GammuConf` – zapis: zmiana/dodanie/usunięcie parametru, nowa sekcja, nazwy bez wielkości liter, `#` w wartości, zapis w miejscu z blokadą; maskowanie hasła (wyświetlanie, różnice, zachowanie przy zapisie) |
| [ ] 5.2 | Kopie zapasowe: kopia przed każdym zapisem, limit z ustawień (domyślnie 30), podgląd, porównanie, przywrócenie; walidacja nazw plików |
| [ ] 5.3 | Różnice linia po linii (prosty algorytm LCS w PHP) do okna potwierdzenia |
| [ ] 5.4 | Zakładka **Ustawienia** (formularz z rozdz. 2.10a, maskowany PIN, lista wykrytych portów z `/dev/serial/by-id/`) |
| [ ] 5.5 | Zakładka **Edytor** z walidacją (sekcje, `service = sql`, dane bazy, port, parametry zarządzane przez panel, `RunOn…`) |
| [ ] 5.6 | Okno potwierdzenia zapisu (D4): ostrzeżenie, różnice, ostrzeżenia walidacji, wybór: przeładuj / restart / nic |
| [ ] 5.7 | Zakładka **Usługa**: przeładowanie i restart z potwierdzeniem, wynik polecenia, ostatnie 20 linii logu, oczekiwanie na modem w `phones` |
| [ ] 5.8 | Ekran **Log Gammu**: N ostatnich linii (odczyt od końca pliku), filtr, podświetlanie błędów, auto-odświeżanie |
| [ ] 5.9 | **Modem**: synchronizacja `phones` → `modem_status`, ekran stanu (odświeżanie co 15 s), wykrywanie niedostępnego modemu |
| [ ] 5.10 | **USSD**: wysłanie (`Class = 127`, priorytet 20), jedno żądanie na modem, oczekiwanie na odpowiedź (htmx, 60 s), odpowiedź w menu (`Status = 3`), historia, szybkie kody |
| [ ] 5.11 | **Czarna lista**: tabela, ekran, „Zablokuj” w rozmowie/odebranych/kontakcie, generowanie pliku z wariantami numerów (zapis atomowy + kopia), przeładowanie Gammu, pierwsze włączenie dopisuje `ExcludeNumbersFile` przez okno potwierdzenia |
| [ ] 5.12 | **Połączenia**: `bin/smsgui hook call` (walidacja, konto `smsgui_hook`), przełącznik w ustawieniach (`HangupCalls`, `RunOnIncomingCall` przez okno potwierdzenia), ekran listy, wpisy w rozmowie, licznik na pulpicie |
| [ ] 5.13 | **Ustawienia panelu** (tabela `settings`) + podgląd ustawień systemowych z `config.php` (bez haseł) |
| [ ] 5.14 | **Pulpit**: kafelki, stan usługi, modemu i ostatniej synchronizacji, kontrola zdrowia (pełna lista z rozdz. 2.2, wspólna z `check`), 10 ostatnich wiadomości |
| [ ] 5.15 | CLI `cleanup [--days=N]` |
| [ ] 5.16 | Symulator: USSD, `call`, czarna lista w `receive`, `reload` |
| [ ] 5.17 | Testy `GammuConf` („wczytaj i zapisz” = plik bez zmian, każda operacja zmienia tylko swoją linię, maskowanie), `Blocklist`, hook (walidacja numeru), USSD (dopasowanie odpowiedzi, przekroczenie czasu) |

**Odbiór:** zmiana `deliveryreportdelay` z formularza → okno z różnicą jednej linii → zapis → plik zmieniony tylko
w tej linii, kopia na liście → „przeładowanie” symulatora → przywrócenie kopii. `*101#` → odpowiedź w panelu;
`*100#` → menu → odpowiedź `1`. Zablokowanie numeru → `gammu-sim receive` z tego numeru nie pojawia się w panelu.
`gammu-sim call` → wpis na liście połączeń i w rozmowie. Pulpit pokazuje ostrzeżenie po ustawieniu `deliveryreportdelay = 600`.

## 9.8. Etap 6 – Wdrożenie (wersja 1)

Warunek wstępny: modem dostarczony, punkty U1–U10 (rozdz. 3.14) sprawdzone na Ubuntu, wyniki wpisane
do dokumentacji.

| # | Zadanie |
|---|---------|
| [ ] 6.1 | Weryfikacja U1–U10, zebranie prawdziwych wierszy z tabel Gammu do `tests/fixtures/`, poprawki, jeśli coś odbiega |
| [ ] 6.2 | `deploy/nginx-smsgui.conf`, `deploy/sudoers-smsgui`, `deploy/smsgui-worker.service`, `deploy/logrotate-gammu-smsd`, `deploy/fail2ban/` (filtr + jail) |
| [ ] 6.3 | `deploy/install.sh` wg rozdz. 6.3: pobieranie przez `curl … \| sudo bash`, pakiety, bazy i konta (hasła losowe), schemat Gammu + InnoDB, kreator modemu (`gammu identify` na portach, PIN sprawdzany raz), `gammu-smsdrc` przez `bin/smsgui setup gammu`, proces w tle, nginx, konto, `check`, testowy SMS (`bin/smsgui send --wait`) |
| [ ] 6.4 | `deploy/uninstall.sh` (z `--purge`) |
| [ ] 6.5 | Instrukcja instalacji, aktualizacji i odinstalowania w README |
| [ ] 6.6 | Test instalacji na czystej maszynie wirtualnej (Multipass na Macu): Ubuntu 26.04; tam też `php tests/run.php` |
| [ ] 6.7 | Lista kontrolna z rozdz. 7.1 na prawdziwym modemie |
| [ ] 6.8 | Wydanie: scalenie `dev` → `main`, tag `v1.0` |

## 9.9. Etapy 7–8 – Rozszerzenia

Rozpisane na zadania po zakończeniu wersji 1 (kolejność i szczegóły mogą się zmienić po używaniu panelu).
Zakres wg [07](07-plan-realizacji.md) i specyfikacji 2.17. Każde rozszerzenie dodaje własną migrację bazy.

- **Etap 7:** migracja tabel rozszerzeń, PHPMailer (sendmail/SMTP, D17), kanały e-mail i webhook (HMAC),
  reguły przekazywania SMS i połączeń z kolejką ponowień (do 5 prób), powiadomienia administracyjne
  (usługa, modem, kolejka, błędy, proces w tle), HTTP API z tokenami i listą IP, ekran „Automatyzacje”.
- **Etap 8:** autoodpowiedzi (słowo kluczowe / godziny / połączenie, blokada pętli 60 min); cykliczne saldo USSD
  z wykresem i progiem; kilka modemów (`gammu-smsd@.service`, „Dodaj modem”, `SenderID`, wybór modemu w formularzach);
  statystyki (wykresy SVG po stronie serwera); automatyczne czyszczenie historii; wersja angielska.
