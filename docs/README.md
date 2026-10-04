# Dokumentacja projektu – smstools3 Web GUI

Status: **etapy 1–5 i 7–8 zrealizowane; etap 6 (wdrożenie) przygotowany, czeka na test na Ubuntu z modemem**
Data: 2026-10-03

Prosty interfejs WWW (PHP + JS + SQLite) do obsługi bramki SMS opartej na
[smstools3](http://smstools3.kekekasvi.com/), uruchamiany na Ubuntu Server LTS.

## Spis dokumentów

| # | Dokument | Zawartość |
|---|----------|-----------|
| 1 | [Założenia i architektura](01-zalozenia-i-architektura.md) | cel, zakres, stos technologiczny, struktura katalogów, przepływ danych |
| 2 | [Specyfikacja funkcjonalna](02-specyfikacja-funkcjonalna.md) | wszystkie ekrany i funkcje: wysyłka, odbiór, rozmowy, książka telefoniczna, grupy, konfiguracja, log, rozszerzenia |
| 3 | [Integracja ze smstools3](03-integracja-smstools3.md) | format plików, kodowanie znaków, statusy, raporty doręczenia, synchronizacja |
| 4 | [Model danych](04-model-danych.md) | schemat bazy SQLite, indeksy, migracje |
| 5 | [Bezpieczeństwo](05-bezpieczenstwo.md) | logowanie, uprawnienia, CSRF, dostęp do plików systemowych |
| 6 | [Plan wdrożenia](06-plan-wdrozenia.md) | instalacja na Ubuntu, uprawnienia, nginx, timer systemd, aktualizacje, kopie zapasowe |
| 7 | [Plan realizacji i testów](07-plan-realizacji.md) | etapy prac, kryteria odbioru, testy |
| 8 | [Pomysły i propozycje](08-pomysly.md) | propozycje funkcji i ich status (przyjęte / odrzucone) |
| 9 | [Plan implementacji](09-plan-implementacji.md) | zasady pracy, środowisko deweloperskie z symulatorem smsd, zadania i pliki dla każdego etapu |

**Makiety interfejsu:** https://claude.ai/artifact/1GcJxMSqGRWsxjPYG9mXG8 (pulpit, nowa wiadomość z działającym licznikiem, rozmowy, wysłane, kontakty, konfiguracja z oknem potwierdzenia, logowanie, widok na telefonie).

## Podjęte decyzje

| # | Temat | Decyzja |
|---|-------|---------|
| D1 | Użytkownicy | **Jedno konto** z dostępem do wszystkich funkcji. Bez ról i bez ekranu zarządzania użytkownikami; hasło zmieniane w panelu, awaryjnie z konsoli |
| D2 | Dostęp sieciowy | **Domyślnie tylko sieć lokalna** (ograniczenie w nginx). Wystawienie panelu do Internetu zależy od użytkownika i jest opisane jako zalecenia |
| D3 | Serwer WWW | **nginx + PHP-FPM** (Apache poza zakresem) |
| D4 | Zapis `/etc/smsd.conf` | **Panel zapisuje plik bezpośrednio**, po potwierdzeniu w oknie z ostrzeżeniem; przed zapisem zawsze powstaje kopia zapasowa |
| D5 | Zakres funkcji | Pomysły z rozdz. 8.1 – w wersji 1; z rozdz. 8.2 – jako **ostatni etap**. Odrzucone: PWA, funkcje związane z RODO (lista „STOP”, prawo do usunięcia danych), logowanie dwuskładnikowe |
| D6 | Frameworki | **Bez frameworka PHP i bez procesu budowania.** Frontend: htmx + Pico.css dołączone lokalnie (potwierdzone) |
| D7 | Wiele modemów | Obsługa jednego lub kilku modemów; wybór modemu przy wysyłce w etapie rozszerzeń |
| D8 | Pliki smsd po imporcie | Zostają (tryb `keep`), opcjonalnie tryb `delete` |
| D9 | „Obce” wiadomości | Panel pokazuje też SMS wysłane innymi drogami (skrypty, `smssend`) |
| D10 | Język interfejsu | Polski; teksty w jednym pliku, wersja angielska w etapie rozszerzeń |
| D11 | Historia | Bezterminowo; opcjonalne automatyczne czyszczenie starszych niż N dni |
| D12 | Kanały przekazywania SMS | **E-mail i webhook.** Telegram i inne kanały – w przyszłości, po ukończeniu wersji bazowej |
| D13 | Kodowanie wysyłanych SMS | Panel sam wybiera GSM (`Alphabet: UTF`) lub UCS2 – omija cichą zamianę „ó”→„o” w smsd ([03, rozdz. 3.2](03-integracja-smstools3.md#32-wysyłka--plik-w-outgoing)) |
| D14 | Wyzwalanie synchronizacji | Obserwacja katalogów przez systemd (`.path`) + timer co 30 s; bez `eventhandler` w smsd |
| D15 | Ikony | [Solar Icon Set](https://icon-sets.iconify.design/solar/) (480 Design), styl *linear*. Licencja **CC BY 4.0** – wymaga podania autora (README i stopka panelu). Ikony zapisane w repozytorium jako SVG, bez pobierania z Internetu |
| D17 | Wysyłka e-mail | **PHPMailer** (7.1.1, LGPL 2.1) dołączony lokalnie w `lib/PHPMailer/` (bez Composera). W panelu do wyboru: systemowy sendmail albo serwer SMTP (host, port, szyfrowanie, login, hasło) – decyzja 2026-10-03 |
| D16 | Kod źródłowy smstools3 | Folder `smstools3/` zostaje lokalnie jako materiał referencyjny, wyłączony z repozytorium (`.gitignore`) |

## Weryfikacja w kodzie źródłowym smstools3

Ustalenia z rozdziału 3 sprawdzono w źródłach smstools3 **3.1.21** (lokalny katalog `smstools3/`, poza repozytorium). Najważniejsze wnioski:

- ✔ smsd pomija pliki zaczynające się od kropki – zapis atomowy działa zgodnie z planem.
- ✔ Nazwy nagłówków w plikach `sent`, `failed` i w raportach doręczenia potwierdzone.
- ⚠ **Domyślnie smsd nie ma katalogów `sent` i `failed`** – trzeba je ustawić w `smsd.conf`, inaczej panel
  nie zna wyniku wysyłki (kontrola zdrowia to wykrywa).
- ⚠ **Zamiana optyczna** (`cs_convert_optical`, domyślnie włączona) zamienia po cichu `ó`→`o` przy
  `Alphabet: UTF` – stąd decyzja D13.
- ✔ Domyślnie odebrane SMS nie są w UTF-8 (`incoming_utf8 = no`) – panel obsługuje wszystkie kodowania,
  zalecane jest `incoming_utf8 = yes`.
- `Message_id` (potrzebny do raportów) pojawia się tylko, gdy zażądano raportu doręczenia.

## Otwarte kwestie

| # | Kwestia |
|---|---------|
| O1 | Sprawdzenia specyficzne dla paczki Ubuntu (użytkownik, uprawnienia, nazwa usługi, statystyki) – lista U1–U6 w [03, rozdz. 3.10](03-integracja-smstools3.md#310-pozostałe-do-weryfikacji-na-ubuntu-etap-0). **Odłożone do dostarczenia modemu.** Nie blokują etapów 1–5: wartości zależne od paczki (ścieżki, nazwa usługi, grupa) są w `config.php`, a instalator (etap 6) powstanie po weryfikacji |
