# 9. Plan implementacji

Rozpisanie etapów z [07-plan-realizacji.md](07-plan-realizacji.md) na konkretne zadania, pliki i testy.
Plik 07 pozostaje przeglądem (zakres + kryterium odbioru), ten dokument jest listą roboczą.
Odhaczane zadania oznaczamy `[x]`.

## 9.1. Zasady pracy

| Temat | Ustalenie |
|-------|-----------|
| Środowisko (etapy 1–5) | **lokalnie na Macu**: PHP z Homebrew, wbudowany serwer `php -S`, symulator smsd na katalogach w `var/dev/`. Ubuntu dopiero w etapie 6 |
| Git | commity bezpośrednio na gałęzi `dev`, jeden commit na zadanie (lub kilka małych); `main` – tylko wydania (`v1.0` po etapie 6) |
| Tryb | etap realizowany w całości (kod + testy), potem **przegląd i akceptacja** przed kolejnym etapem |
| Definicja „gotowe” | `php tests/run.php` przechodzi, scenariusz odbioru etapu wykonany ręcznie, dokumentacja zgodna z kodem |
| Zależności | bez Composera i npm; jedyne pliki zewnętrzne: htmx, Pico.css, ikony Solar, czcionki IBM Plex (etap 1) |

### Wersja PHP
Docelowo i lokalnie **PHP 8.5** – bez wstecznej zgodności ze starszymi wersjami. Można swobodnie
używać nowości z 8.4 i 8.5, gdy realnie upraszczają kod (np. `array_find()`, property hooks,
`#[\NoDiscard]`), ale bez sztuki dla sztuki – czytelność ważniejsza.

### Pułapki wynikające z CSP (`default-src 'self'`)
- **Żadnego JS ani CSS inline** – także atrybutów `style="…"` i `onclick`. Cały styl w `app.css`,
  cały skrypt w plikach `.js`.
- htmx: konfiguracja w `<meta name="htmx-config" content='{"includeIndicatorStyles":false,"allowEval":false}'>`
  (inaczej htmx wstrzykuje `<style>` i używa `eval`); nie używamy `hx-on:*`.
- Okna potwierdzenia: element `<dialog>` + kilka linii w `app.js` (bez `hx-confirm` z natywnym `confirm()`,
  który nie pasuje do wyglądu makiet).

### Konwencje kodu
- Każde wyjście przez `e()`; SQL tylko z parametrami.
- Strona = plik `src/pages/<nazwa>.php` + widok `views/<nazwa>.php`. Ta sama strona zwraca
  **fragment** (bez układu), gdy żądanie ma nagłówek `HX-Request` – jedna logika dla całej strony i htmx.
- Teksty interfejsu przez `t('klucz')` z pliku `resources/lang/pl.php` od początku (D10 – wersja angielska
  w etapie 8 to wtedy tylko drugi plik).
- Daty w bazie: tekst `RRRR-MM-DD GG:MM:SS`, czas lokalny.
- Kodowanie plików UTF-8, końce linii LF (`.gitattributes`).

## 9.2. Środowisko deweloperskie (Mac)

```
var/dev/                      ← poza repozytorium (.gitignore)
├── smsd.conf                 ← przykładowa konfiguracja (z sent/failed, incoming_utf8)
├── smsd.log                  ← log pisany przez symulator
├── spool/{outgoing,checked,incoming,sent,failed}/
├── smsgui.sqlite             ← baza
└── backups/                  ← kopie smsd.conf
```

```bash
php tests/sim/smsd-sim.php init        # tworzy var/dev/ i config/config.php wskazujący na var/dev
php bin/smsgui passwd admin
php tests/sim/smsd-sim.php run         # symulator smsd (osobny terminal)
php -S 127.0.0.1:8080 -t public        # panel: http://127.0.0.1:8080
php tests/run.php                      # testy
```

**Symulator smsd** (`tests/sim/smsd-sim.php`, powstaje w etapach 1–3):

| Polecenie | Działanie |
|-----------|-----------|
| `init` | tworzy `var/dev/`, przykładowy `smsd.conf`, `config/config.php` dla środowiska dev |
| `run` | pętla co 2 s: `outgoing` → `checked` → `sent` (dopisuje `Modem`, `Sent`, `IMSI`, `Message_id` gdy `Report: yes`) lub `failed` (`Fail_reason`) dla numerów kończących się na `000`; po kilku sekundach raport doręczenia w `incoming` (kod 0, dla numerów na `999` – kod 70). Po każdej zmianie uruchamia `bin/smsgui sync` – tak jak jednostka `.path` na serwerze. Pisze linie do `smsd.log` |
| `receive <numer> <treść> [--alphabet=UTF-8\|ISO\|UCS2] [--incomplete]` | „odebrany SMS” w `incoming` w wybranym kodowaniu |
| `external <numer> <treść>` | plik w `sent` bez prefiksu `gui_` – wiadomość „zewnętrzna” |
| `status` / `restart` | udają `systemctl is-active` / `restart` (stan z pliku PID pętli `run`); używane jako `service.status_cmd` / `restart_cmd` w konfiguracji dev |

Prawdziwe pliki z modemu (ISO, UCS2, binarne, raporty, failed) dołączymy do `tests/fixtures/`
po dostarczeniu modemu (etap 0).

## 9.3. Etap 1 – Szkielet

| # | Zadanie | Pliki |
|---|---------|-------|
| [x] 1.1 | Struktura katalogów, `.gitignore` (`config/config.php`, `var/`), konfiguracja domyślna (wszystkie klucze z rozdz. 1.6) | `config/config.example.php` |
| [x] 1.2 | Bootstrap: autoloader `src/*.php`, wczytanie i nałożenie konfiguracji, strefa czasowa, obsługa błędów → log aplikacji | `src/bootstrap.php` |
| [x] 1.3 | Funkcje pomocnicze: `e()`, `url()`, `t()`, `icon()`, `csrf_field()`/`csrf_check()`, `flash()`, `render()`, `redirect()`, `is_htmx()` | `src/helpers.php`, `resources/lang/pl.php` |
| [x] 1.4 | Baza: PDO + pragmy (WAL, foreign_keys, busy_timeout), migracje przez `user_version`; migracja 1 = wszystkie tabele podstawowe z rozdz. 4.2 (bez tabel rozszerzeń) | `src/Db.php` |
| [x] 1.5 | Logowanie: sesja (HttpOnly, SameSite, Secure przy HTTPS), `session_regenerate_id`, blokada 5 prób / 15 min (`login_attempts`), wygasanie bezczynności, „Zapamiętaj mnie” (ciasteczko podpisane HMAC, klucz w bazie), wylogowanie, wpis dla fail2ban w logu | `src/Auth.php` |
| [x] 1.6 | Front controller: nagłówki bezpieczeństwa (rozdz. 5.4), routing `?p=`, wymuszenie logowania, centralne sprawdzanie CSRF dla POST, strona 404 | `public/index.php` |
| [x] 1.7 | Biblioteki i ikony: htmx 2.x i Pico.css 2.x pobrane raz do repozytorium (z licencjami), ok. 25 ikon Solar *linear* jako SVG + `LICENSE` | `public/assets/vendor/`, `resources/icons/` |
| [x] 1.8 | Układ strony wg makiet: menu boczne (zwijane na telefonie), miejsce na licznik nieprzeczytanych i wskaźnik usługi, komunikaty flash, stopka z atrybucją ikon; jasny/ciemny motyw z Pico | `views/layout.php`, `public/assets/app.css`, `public/assets/app.js` |
| [x] 1.9 | Ekrany: logowanie, pusty pulpit, zmiana hasła (i loginu) | `src/pages/`, `views/` |
| [x] 1.10 | Odczyt `smsd.conf` (tylko parsowanie: sekcje, parametry, ścieżki kolejek) – potrzebny już dla `check` | `src/SmsdConf.php` (część odczytu) |
| [x] 1.11 | Stan usługi (`status_cmd`) | `src/Service.php` (część odczytu) |
| [x] 1.12 | CLI: `passwd [login]` (hasło z ukrytym wpisywaniem), `check [--quiet]` – lista ✔/✘: wersja PHP i rozszerzenia, konfiguracja, zapis do bazy, odczyt/zapis `smsd.conf`, katalogi kolejek i uprawnienia, `sent`/`failed` ustawione, log czytelny, stan usługi; kod wyjścia ≠ 0 przy błędzie; sprawdzenia w `src/Health.php` (wspólne z pulpitem w etapie 5) | `bin/smsgui`, `src/Health.php` |
| [x] 1.13 | Runner testów bez zależności (`test()`, `assert_same()`, raport, kod wyjścia) + testy `Db` (migracje na pustej bazie), `Auth` (blokada), `SmsdConf` (odczyt) | `tests/run.php`, `tests/*Test.php` |
| [x] 1.14 | Symulator: polecenia `init`, `status`, `restart` | `tests/sim/smsd-sim.php` |

**Status etapu 1:** zrealizowany 2026-10-03, czeka na przegląd.

**Odbiór:** logowanie działa (także blokada po 5 próbach i wylogowanie); POST bez tokenu CSRF → 403;
`php bin/smsgui check` na środowisku dev pokazuje same ✔, a po usunięciu `var/dev/spool/sent` – ✘ z podpowiedzią;
układ zgodny z makietami na komputerze i przy 360 px; konsola przeglądarki bez błędów CSP.

## 9.4. Etap 2 – Rdzeń SMS

| # | Zadanie | Pliki |
|---|---------|-------|
| [x] 2.1 | `SmsText`: alfabet GSM (podstawowy + rozszerzony, bez `¤`), wybór GSM/UCS2, liczenie jednostek i części (160/153, 70/67, emoji = 2), transliteracja, lista znaków wymuszających Unicode, kodowanie UTF-16BE | `src/SmsText.php` |
| [x] 2.2 | Ta sama logika w JS dla licznika na żywo + **wspólny plik przypadków testowych** dla PHP i JS; test JS uruchamiany w przeglądarce (strona `tests/js/index.html`, serwowana `php -S -t tests/js`) | `public/assets/sms-text.js`, `tests/cases/smstext.json` |
| [x] 2.3 | `Phone`: normalizacja, numery skrócone, nadawcy alfanumeryczni, formatowanie do wyświetlania | `src/Phone.php` |
| [x] 2.4 | `Spool` – zapis: nazwa `gui_<id>_<hex>`, zapis do pliku z kropką, `chmod 0660`, `rename()`; nagłówki `To`, `Report`, `Flash`, `Alphabet` | `src/Spool.php` |
| [x] 2.5 | `Spool` – parsowanie: nagłówki/treść, kodowania (UTF-8, UCS2, ISO, GSM, binary), daty w kilku formatach, `Incomplete`, `Original_filename`, `Message_id` (oba warianty `messageids`), pomijanie plików z kropką, `.LOCK` i świeżych (< 2 s) | `src/Spool.php` |
| [x] 2.6 | `Outbox::send()` – jeden odbiorca: rekord w bazie → plik w `outgoing` → `queued` (lub `scheduled`) | `src/Outbox.php` |
| [x] 2.7 | Synchronizacja (rozdz. 3.6 bez raportów): blokada `flock`, wysyłka zaplanowanych, `sent`/`failed` (dopasowanie po nazwie i `Original_filename`, wiadomości zewnętrzne), `incoming` → odebrane, tryby `keep`/`delete`, `processed_files`, osobna transakcja na plik, wynik w `settings` | `src/Sync.php`* |
| [x] 2.8 | `bin/smsgui sync` + synchronizacja przy odświeżeniu strony (nie częściej niż co 10 s) | `bin/smsgui`, `public/index.php` |
| [x] 2.9 | Symulator: `run`, `receive`, `external` | `tests/sim/smsd-sim.php` |
| [x] 2.10 | Ekran **Nowa wiadomość** (jeden odbiorca): licznik z wyjaśnieniem kodowania, „Zamień polskie znaki”, raport, flash, walidacja | `src/pages/compose.php`, `views/compose.php` |
| [x] 2.11 | Ekran **Odebrane**: tabela, filtry (tekst, daty, nieprzeczytane), stronicowanie 50, oznacz przeczytane/nieprzeczytane, usuń | `src/pages/inbox.php` |
| [x] 2.12 | Ekran **Wysłane**: tabela, statusy z etykietami, filtry (status, tekst, daty, źródło), usuń, kopiuj | `src/pages/sent.php` |
| [x] 2.13 | Testy: `SmsText` (wszystkie przypadki z rozdz. 7.1), `Phone` (tabela 3.9), `Spool::parse` (pliki z `smstools3/examples` skopiowane do `tests/fixtures/`), synchronizacja na katalogach tymczasowych (keep/delete, uszkodzony plik, zewnętrzna) | `tests/` |

\* `Sync.php` – wydzielony ze `Spool.php`, żeby oba pliki były krótkie (`Spool` = format plików,
`Sync` = algorytm). Do dopisania w strukturze katalogów w rozdz. 1.5.

**Status etapu 2:** zrealizowany 2026-10-03. Test JS: `node tests/js/run.mjs` lub strona `tests/js/index.html`.

**Odbiór (symulator):** SMS z panelu → `W kolejce` → `Wysłana` w ciągu kilku sekund; numer `…000` →
`Błąd` z przyczyną; `smsd-sim receive` w trzech kodowaniach → poprawne polskie znaki i emoji w „Odebrane”;
`smsd-sim external` → wiadomość „zewnętrzna” w „Wysłane”; „Mój kot” wysłane jako UCS2.

## 9.5. Etap 3 – Rozmowy i raporty

| # | Zadanie |
|---|---------|
| [x] 3.1 | Raporty doręczenia w synchronizacji: rozpoznanie raportu, dopasowanie po `Message_id` + numer (najnowsza wiadomość, lista ID przy `messageids = 3`), kody 0–31 / 32–63 / 64–127; raporty nie trafiają do „Odebrane” |
| [x] 3.2 | Lista rozmów (`MAX(id) GROUP BY phone`), wyszukiwarka, licznik nieprzeczytanych |
| [x] 3.3 | Widok rozmowy: dymki, statusy, oznaczanie jako przeczytane, nadawcy alfanumeryczni bez pola odpowiedzi, usuń rozmowę |
| [x] 3.4 | Odpowiedź przez htmx bez przeładowania; odświeżanie fragmentu co 10 s tylko gdy coś się zmieniło (`updated_at`), bez utraty wpisywanego tekstu |
| [x] 3.5 | Licznik nieprzeczytanych w menu (htmx co 15 s) i wskaźnik stanu usługi |
| [x] 3.6 | Ponów (failed/undelivered → nowy plik, `queued`), Anuluj (scheduled zawsze; queued tylko gdy plik jest jeszcze w `outgoing`) |
| [x] 3.7 | Wysyłka planowana: pole „Wyślij później” na ekranie nowej wiadomości |
| [x] 3.8 | Symulator: raporty doręczenia (kod 0, kod 70 dla `…999`), opcja `--messageids=3` |
| [x] 3.9 | Testy: dopasowanie raportów (powtarzające się ID, lista ID), przejścia statusów, anulowanie |

**Status etapu 3:** zrealizowany 2026-10-03 (obsługa raportów powstała razem z synchronizacją w etapie 2).

**Odbiór:** pełny cykl na symulatorze: zaplanuj → `Zaplanowana` → `W kolejce` → `Wysłana` → `Doręczona`;
numer `…999` → `Niedoręczona` → Ponów; rozmowa odświeża się sama, gdy symulator „odbierze” odpowiedź.

## 9.6. Etap 4 – Książka telefoniczna i wysyłka do wielu

| # | Zadanie |
|---|---------|
| [x] 4.1 | Kontakty: lista, wyszukiwanie podczas pisania (htmx), filtr po grupie, stronicowanie, dodaj/edytuj/usuń, ekran kontaktu z ostatnimi wiadomościami |
| [x] 4.2 | Grupy: lista z liczbą członków, dodaj/zmień nazwę/usuń, „Pokaż członków”, „Wyślij do grupy” |
| [x] 4.3 | Akcje zbiorcze na kontaktach: dodaj do / usuń z grupy, usuń, wyślij SMS do zaznaczonych |
| [x] 4.4 | Nazwa kontaktu zamiast numeru w całym panelu (jedno miejsce: funkcja pomocnicza z pamięcią podręczną na żądanie) |
| [x] 4.5 | Nowa wiadomość – wybór odbiorców: grupy + kontakty + numery ręcznie, suma bez duplikatów, licznik „Odbiorców: 37 (2 duplikaty pominięte)”, lista błędnych numerów, `max_recipients`; otwieranie z gotowym wyborem (kontakt, grupa, zaznaczone, rozmowa, przekaż dalej) |
| [x] 4.6 | Szablony: CRUD + wybór na ekranie nowej wiadomości |
| [x] 4.7 | Personalizacja `{nazwa}`, `{imie}`: podgląd, licznik wg najdłuższego wariantu, ostrzeżenie dla odbiorców spoza książki |
| [x] 4.8 | Dławienie: rozłożenie `scheduled_at` wg N SMS/min, szacowany czas wysyłki |
| [x] 4.9 | Cisza nocna: okno „Wyślij teraz” / „Zaplanuj na 8:00”; okno potwierdzenia przy > 1 odbiorcy |
| [x] 4.10 | Raport wysyłki (`batch_id`): liczniki, pasek postępu (htmx), „Ponów nieudane”, „Anuluj pozostałe” |
| [x] 4.11 | Import CSV z podglądem (nowe / aktualizacje / błędne wiersze; separator `;`/`,`, BOM) i eksport CSV z BOM |
| [x] 4.12 | Testy: łączenie odbiorców i duplikaty, personalizacja, rozkład dławienia, parsowanie CSV |

**Status etapu 4:** zrealizowany 2026-10-03.

**Odbiór:** wysyłka do grupy 3+ numerów + 1 duplikat + 1 błędny numer, z `{imie}` i dławieniem 2/min;
raport wysyłki pokazuje każdy status; import pliku CSV z Excela z polskimi znakami.

## 9.7. Etap 5 – Konfiguracja, log, ustawienia, pulpit

| # | Zadanie |
|---|---------|
| [x] 5.1 | `SmsdConf` – zapis: zmiana/dodanie/usunięcie parametru, nowa sekcja, parametry wielokrotne (`regular_run_cmd`), `#` w środku linii jako wartość; zapis w miejscu z blokadą (zmiana planu – patrz docs/03, rozdz. 3.7) |
| [x] 5.2 | Kopie zapasowe: kopia przed każdym zapisem, limit z ustawień (domyślnie 30), podgląd, porównanie, przywrócenie; walidacja nazw plików |
| [x] 5.3 | Różnice linia po linii (prosty algorytm LCS w PHP) do okna potwierdzenia |
| [x] 5.4 | Zakładka **Ustawienia** (formularz z rozdz. 2.10a, maskowany PIN, sekcje modemów wg `devices`) |
| [x] 5.5 | Zakładka **Edytor** z walidacją (sekcje dla `devices`, istniejące ścieżki, nierozpoznane linie) |
| [x] 5.6 | Okno potwierdzenia zapisu (D4): ostrzeżenie, różnice, ostrzeżenia walidacji, „Zrestartuj po zapisie” |
| [x] 5.7 | Zakładka **Usługa**: restart z potwierdzeniem, wynik polecenia, ostatnie 20 linii logu |
| [x] 5.8 | Ekran **Log smsd**: N ostatnich linii (odczyt od końca pliku), filtr, podświetlanie poziomów, auto-odświeżanie |
| [x] 5.9 | **Ustawienia panelu** (tabela `settings`) + podgląd ustawień systemowych z `config.php` (tylko odczyt) |
| [x] 5.10 | **Pulpit**: kafelki, stan usługi i ostatniej synchronizacji, kontrola zdrowia (wspólna z `check` – jedna klasa `Health`), 10 ostatnich wiadomości |
| [x] 5.11 | CLI `cleanup [--days=N]` |
| [x] 5.12 | Testy `SmsdConf`: „wczytaj i zapisz” = plik bez zmian, każda operacja zmienia tylko swoją linię, `regular_run_cmd`, `*101#`; kopie i walidacja nazw |

**Status etapu 5:** zrealizowany 2026-10-03.

**Odbiór:** zmiana `loglevel` z formularza → okno z różnicą jednej linii → zapis → plik zmieniony tylko
w tej linii, kopia na liście → „restart” symulatora → przywrócenie kopii. Pulpit pokazuje ostrzeżenie
po usunięciu `sent =` z `var/dev/smsd.conf`.

## 9.8. Etap 6 – Wdrożenie (wersja 1)

Warunek wstępny: modem dostarczony, punkty U1–U6 (rozdz. 3.10) sprawdzone na Ubuntu, wyniki wpisane
do dokumentacji.

| # | Zadanie |
|---|---------|
| 6.1 | Weryfikacja U1–U6, zebranie prawdziwych plików z modemu do `tests/fixtures/`, poprawki parsera, jeśli coś odbiega |
| [x] 6.2 | `deploy/nginx-smsgui.conf`, `deploy/sudoers-smsgui`, `deploy/smsgui-sync.{service,timer,path}`, `deploy/fail2ban/` (filtr + jail) |
| [x] 6.3 | `deploy/install.sh`: idempotentny, kroki 2–7 z rozdz. 6.2, wykrywa użytkownika smsd, ścieżki z `smsd.conf` i wersję PHP-FPM; pyta o hosta, HTTPS (bez / samopodpisany), login i hasło; na końcu `check` |
| [x] 6.3a | `install.sh` jako pełny instalator (rozdz. 6.3): pobieranie przez `curl … \| sudo bash`, instalacja smstools, kreator modemu (lista portów z odpowiedzią na AT, sprawdzenie PIN), dopisanie `sent`/`failed`/`incoming_utf8` (`bin/smsgui smsd-setup`), testowy SMS (`bin/smsgui send --wait`) |
| [x] 6.4 | Instrukcja instalacji, aktualizacji i odinstalowania w README |
| 6.5 | Test instalacji na czystej maszynie wirtualnej (Multipass na Macu): Ubuntu 26.04; tam też `php tests/run.php` |
| 6.6 | Lista kontrolna z rozdz. 7.1 na prawdziwym modemie |
| 6.7 | Wydanie: scalenie `dev` → `main`, tag `v1.0` |

**Status etapu 6 (2026-10-03):** przygotowane pliki wdrożenia (6.2–6.4) – sprawdzona składnia skryptów i podstawianie
szablonów; **bez testu na Ubuntu** (na Macu brak maszyny wirtualnej). Do zrobienia po dostarczeniu modemu: 6.1, 6.5, 6.6, 6.7.

## 9.9. Etapy 7–8 – Rozszerzenia

**Etap 7 – zrealizowany 2026-10-03:** migracja 2 (tabele rozszerzeń), PHPMailer (sendmail/SMTP, D17), kanały e-mail
i webhook (HMAC), reguły przekazywania z kolejką ponowień (do 5 prób), powiadomienia administracyjne (usługa, kolejka,
błędy; e-mail/webhook/SMS, raz na godzinę), HTTP API z tokenami i listą IP, ekran „Automatyzacje”. Sprawdzone: webhook
z podpisem, e-mail przez SMTP (testowy serwer), `curl` → `/api/send` → doręczona.

**Etap 8 – zrealizowany 2026-10-03:** migracja 3; autoodpowiedzi (słowo kluczowe / poza godzinami pracy, blokada pętli
60 min); stan modemu z `regular_run_statfile` (sygnał, operator, saldo USSD) z przyciskiem „Włącz monitorowanie modemu”
(przez okno potwierdzenia) i powiadomieniem o niskim saldzie; wybór modemu przy wysyłce przez kolejki smsd (`Queue:`,
lista z sekcji `[queues]` – widoczna tylko, gdy kolejki są skonfigurowane; także parametr `queue` w API); statystyki
(wykresy SVG po stronie serwera, tabela danych, odsetek doręczeń, najaktywniejsze numery); automatyczne czyszczenie
historii raz na dobę; wersja angielska (`resources/lang/en.php`, wybór w ustawieniach; komunikaty CLI i API pozostają po polsku).
Poprawka przy okazji: osobna kolumna `messages.queue` – wcześniej ponowienie mogło dodać `Queue:` z nazwy modemu.

Pierwotna uwaga: rozpisane na zadania po zakończeniu wersji 1 (kolejność i szczegóły mogą się zmienić po używaniu panelu).
Zakres wg [07](07-plan-realizacji.md) i specyfikacji 2.14. Każde rozszerzenie dodaje własną migrację bazy.
Otwarta decyzja na etap 7: e-mail przez systemowe `sendmail` czy przez SMTP z panelu (2.14.1).

## 9.10. Zmiany w dokumentacji wynikające z planu

- rozdz. 1.5: dopisane `src/Sync.php`, `src/Health.php`, `resources/lang/`, `tests/sim/`, katalog `var/` (dev),
- rozdz. 1.6: nowe klucze `log_path`, `session_path`, `backup_dir`, `smsd_log`, `debug`,
- rozdz. 4.2: `users.remember_secret` zamiast `remember_token_hash` (ciasteczko podpisane HMAC – działa na wielu urządzeniach),
- rozdz. 5.4: `img-src 'self' data:` w CSP (ikony formularzy w Pico.css),
- rozdz. 6.2: katalog `/var/lib/smsgui/sessions`,
- rozdz. 3.7 i 5.2: `smsd.conf` zapisywany w miejscu zamiast przez plik tymczasowy i `rename()` (brak prawa zapisu do `/etc`).
