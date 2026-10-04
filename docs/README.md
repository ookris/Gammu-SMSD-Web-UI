# Dokumentacja projektu – Gammu SMSD Web UI

Status: **projekt – dokumentacja gotowa, etap P (projekt interfejsu) zakończony, następny: etap 1 – szkielet** (etap 0 – weryfikacja w kodzie źródłowym zakończona)
Data: 2026-10-04

Prosty interfejs WWW (PHP + JS + MariaDB) do obsługi bramki SMS opartej na
[Gammu SMSD](https://docs.gammu.org/smsd/), uruchamiany na Ubuntu Server LTS. Wszystkie funkcje są dostępne
z przeglądarki. Projekt zastępuje wcześniejszy panel dla smstools3 – zakres funkcji jest ten sam, rozszerzony
o możliwości Gammu (USSD, połączenia przychodzące, czarna lista, okna wysyłki, priorytety).

## Spis dokumentów

| # | Dokument | Zawartość |
|---|----------|-----------|
| 1 | [Założenia i architektura](01-zalozenia-i-architektura.md) | cel, zakres, stos technologiczny (także: czy PHP i JS to dobry wybór), struktura katalogów, przepływ danych |
| 2 | [Specyfikacja funkcjonalna](02-specyfikacja-funkcjonalna.md) | wszystkie ekrany i funkcje: wysyłka, odbiór, rozmowy, książka telefoniczna, grupy, modem i USSD, połączenia, czarna lista, konfiguracja, log, rozszerzenia |
| 3 | [Integracja z Gammu SMSD](03-integracja-gammu-smsd.md) | tabele Gammu, zapis do `outbox`, kodowanie i dzielenie SMS, odbiór, raporty doręczenia, statusy, synchronizacja, USSD, połączenia, czarna lista |
| 4 | [Model danych](04-model-danych.md) | bazy MariaDB `gammu` i `smsgui`, tabele panelu, indeksy, migracje |
| 5 | [Bezpieczeństwo](05-bezpieczenstwo.md) | logowanie, uprawnienia w systemie i bazie, CSRF, hook połączeń, dostęp do plików systemowych |
| 6 | [Plan wdrożenia](06-plan-wdrozenia.md) | instalacja na Ubuntu (Gammu, MariaDB, nginx), usługi systemd, aktualizacje, kopie zapasowe |
| 7 | [Plan realizacji i testów](07-plan-realizacji.md) | etapy prac, kryteria odbioru, testy |
| 8 | [Pomysły i propozycje](08-pomysly.md) | propozycje funkcji i ich status (przyjęte / odrzucone) |
| 9 | [Plan implementacji](09-plan-implementacji.md) | zasady pracy, środowisko deweloperskie z symulatorem Gammu, zadania i pliki dla każdego etapu |

**Makiety interfejsu:** https://claude.ai/artifact/1GcJxMSqGRWsxjPYG9mXG8 – pierwotnie przygotowane dla poprzedniego
projektu (smstools3), w etapie P uzupełnione o wszystkie ekrany i stany wersji 1 z rozdz. 2 (21 artboardów, decyzja D23) – **zaakceptowane 2026-10-04**.
**Prototyp HTML:** katalog [`prototype/`](../prototype/README.md) – 27 statycznych stron w docelowej technologii (zgodnych z CSP), z których powstają widoki panelu. Uruchomienie: `php -S 127.0.0.1:8081 -t prototype prototype/router.php`.

## Podjęte decyzje

| # | Temat | Decyzja |
|---|-------|---------|
| D1 | Użytkownicy | **Jedno konto** z dostępem do wszystkich funkcji. Bez ról i bez ekranu zarządzania użytkownikami; hasło zmieniane w panelu, awaryjnie z konsoli |
| D2 | Dostęp sieciowy | **Domyślnie tylko sieć lokalna** (ograniczenie w nginx). Wystawienie panelu do Internetu zależy od użytkownika i jest opisane jako zalecenia |
| D3 | Serwer WWW | **nginx + PHP-FPM** (Apache poza zakresem) |
| D4 | Zapis `/etc/gammu-smsdrc` | **Panel zapisuje plik bezpośrednio**, po potwierdzeniu w oknie z ostrzeżeniem i różnicami; przed zapisem zawsze powstaje kopia zapasowa; hasło do bazy maskowane |
| D5 | Zakres funkcji | Pomysły z rozdz. 8.1 – w wersji 1; z rozdz. 8.2 – jako **ostatni etap**. Odrzucone: PWA, funkcje związane z RODO (lista „STOP”, prawo do usunięcia danych), logowanie dwuskładnikowe |
| D6 | Frameworki | **Bez frameworka PHP i bez procesu budowania.** Frontend: htmx + Pico.css dołączone lokalnie |
| D7 | Wiele modemów | Wersja 1 – jeden modem. Kilka modemów w etapie rozszerzeń: **osobny proces Gammu na modem** (własna konfiguracja i PhoneID, wspólna baza), wybór przez `outbox.SenderID` |
| D8 | Wiersze Gammu po imporcie | Zostają (tryb `keep`, `inbox.Processed = 'true'`), opcjonalnie tryb `delete` |
| D9 | „Obce” wiadomości | Panel pokazuje też SMS wysłane innymi drogami (`gammu-smsd-inject`, inne programy piszące do bazy Gammu) |
| D10 | Język interfejsu | Polski; teksty w jednym pliku, wersja angielska w etapie rozszerzeń |
| D11 | Historia | Bezterminowo; opcjonalne automatyczne czyszczenie starszych niż N dni |
| D12 | Kanały przekazywania | **E-mail i webhook** (SMS i połączenia). Telegram i inne kanały – w przyszłości |
| D13 | Kodowanie i dzielenie SMS | **Panel sam wybiera kodowanie** (GSM / Unicode) **i sam dzieli wiadomość na części z UDH** – Gammu nie dzieli wiadomości z bazy i przy kodowaniu GSM po cichu zamienia ą→a, ł→l, ó→o, ż→z ([03, rozdz. 3.3](03-integracja-gammu-smsd.md#33-wysyłka--zapis-do-outbox)) |
| D14 | Synchronizacja | **Proces w tle** (`smsgui-worker`, usługa systemd) odpytujący bazę co 3 s + przy odświeżeniu strony. Bez `RunOnReceive`/`RunOnSent` – raporty doręczenia i tak nie mają zdarzenia |
| D15 | Ikony | [Solar Icon Set](https://icon-sets.iconify.design/solar/) (480 Design), styl *linear*. Licencja **CC BY 4.0** – wymaga podania autora (README i stopka panelu). Ikony zapisane w repozytorium jako SVG |
| D16 | Kod źródłowy Gammu | Pobierany lokalnie jako materiał referencyjny (`git clone --branch 1.42.0 https://github.com/gammu/gammu.git`), wyłączony z repozytorium (`.gitignore`) |
| D17 | Wysyłka e-mail | **PHPMailer** dołączony lokalnie w `lib/PHPMailer/` (bez Composera). W panelu do wyboru: systemowy sendmail albo serwer SMTP |
| D18 | Backend Gammu i baza | **Backend SQL, MariaDB** (decyzja 2026-10-04). Dwie bazy na jednym serwerze: `gammu` (tabele Gammu) i `smsgui` (dane panelu); wszystkie tabele InnoDB. Panel nie używa już SQLite |
| D19 | Wersja Gammu | **Pakiet Ubuntu 26.04 – Gammu 1.42.0, schemat bazy 17** (decyzja 2026-10-04). Aktualizacje z `apt`; znane ograniczenia 1.42 obsłużone w panelu (rozdz. 3). Gammu 1.44+ – w przyszłości (08, rozdz. 8.3) |
| D20 | Obsługa | **Wszystko przez przeglądarkę** (decyzja 2026-10-04). Konsola tylko do instalacji jednym poleceniem i w sytuacjach awaryjnych (zapomniane hasło) |
| D21 | Nowe funkcje z Gammu | Przyjęte do wersji 1 (decyzja 2026-10-04): **USSD na żądanie**, **połączenia przychodzące** (odrzucanie + lista), **czarna lista numerów**, **okna wysyłki i priorytet**; stan modemu z tabeli `phones` |
| D22 | Projekt | **Nowy projekt od zera** (decyzja 2026-10-04) – kod panelu dla smstools3 nie jest przenoszony |
| D23 | Interfejs najpierw | **Najpierw projekt interfejsu, potem kod** (decyzja 2026-10-04). Etap P przed etapem 1: (1) makiety wszystkich ekranów i stanów do akceptacji, (2) statyczny prototyp HTML w docelowej technologii (Pico.css, `app.css`, ikony Solar, IBM Plex, zgodny z CSP) w `prototype/`. Widoki `views/*.php` powstają z prototypu przez podstawienie danych |

## Weryfikacja w kodzie źródłowym Gammu

Ustalenia z rozdziału 3 sprawdzono w źródłach Gammu **1.42.0** (`smsd/core.c`, `smsd/services/sql.c`,
`docs/sql/mysql.sql`, `libgammu/misc/coding/coding.c`, `libgammu/misc/cfg.c`). Najważniejsze wnioski:

- ⚠ **Gammu nie dzieli długich wiadomości zapisanych w bazie** – części z UDH tworzy panel (D13).
- ⚠ **Ciche zamiany przy kodowaniu GSM**: `ą`→`a`, `ł`→`l`, `ó`→`o`, `ż`→`z` itd., pozostałe znaki → `?` – panel sam wybiera Unicode.
- ⚠ **Tabele z oficjalnego skryptu są w MyISAM** – bez transakcji Gammu mógłby wysłać niepełną wiadomość
  wieloczęściową; instalator zamienia je na InnoDB.
- ⚠ **`DeliveryReportDelay` domyślnie 600 s** – raport od telefonu wyłączonego dłużej niż 10 min nie zostałby dopasowany;
  panel zaleca 2 dni.
- ⚠ `RunOnIncomingCall` w 1.42.0 nie cytuje argumentu (poprawione w 1.43.3) – hook jest odporny na nietypowe dane.
- ✔ Raporty doręczenia dopasowuje sam Gammu (`sentitems.Status`) – panel tylko odczytuje wynik.
- ✔ Wysyłka planowana (`SendingDateTime`), okna godzinowe (`SendAfter`/`SendBefore`) i priorytet (`Priority`)
  działają po stronie Gammu.
- ✔ Odebrana wiadomość wieloczęściowa: osobne wiersze, pełny tekst w pierwszym (od 1.40).
- ✔ USSD (`Class = 127`) w `outbox` i `inbox`; połączenia tylko przy `HangupCalls = yes`; czarna lista przez
  `ExcludeNumbersFile`, odczytywana przy starcie i przeładowaniu (`SIGHUP`).
- ✔ Konfiguracja: plik INI, komentarze tylko w całych liniach (`#`, `;`), nazwy kluczy bez wielkości liter.

## Otwarte kwestie

| # | Kwestia |
|---|---------|
| O1 | Sprawdzenia specyficzne dla paczki Ubuntu i modemu – lista U1–U10 w [03, rozdz. 3.14](03-integracja-gammu-smsd.md#314-do-weryfikacji-na-ubuntu-etap-0). **Odłożone do dostarczenia modemu.** Nie blokują etapów 1–5: wartości zależne od paczki (ścieżki, nazwa usługi) są w `config.php`, a instalator (etap 6) powstanie po weryfikacji |
| O2 | ✔ Adres repozytorium: `ookris/Gammu-SMSD-Web-UI` – potwierdzony 2026-10-04 |
| O3 | Makiety nowych ekranów (Modem/USSD, Połączenia, Zablokowane numery, konfiguracja Gammu) – ✔ dorysowane i zaakceptowane w etapie P (2026-10-04) |
