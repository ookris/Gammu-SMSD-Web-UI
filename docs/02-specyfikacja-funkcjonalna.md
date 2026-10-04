# 2. Specyfikacja funkcjonalna

Oznaczenia priorytetów: **M** – musi być w wersji 1, **S** – powinno być w wersji 1,
**R** – rozszerzenie realizowane w etapie końcowym.

Panel ma **jedno konto** z dostępem do wszystkich funkcji (decyzja D1).

## 2.0. Mapa ekranów

```
Logowanie
└── Panel
    ├── Pulpit
    ├── Nowa wiadomość
    ├── Rozmowy ── Rozmowa z numerem
    ├── Odebrane
    ├── Wysłane ── Raport wysyłki do wielu odbiorców
    ├── Kontakty ── Kontakt (dodaj/edytuj)
    ├── Grupy
    ├── Szablony                          (S)
    ├── Konfiguracja smstools
    ├── Log smsd                          (S)
    ├── Ustawienia panelu
    ├── Automatyzacje                     (R: przekazywanie, autoodpowiedzi, API)
    ├── Statystyki                        (R)
    └── Zmiana hasła
```

Wspólne elementy każdej strony: menu boczne (na telefonie zwijane), licznik nieprzeczytanych
przy „Rozmowy/Odebrane” (odświeżany co 15 s), wskaźnik stanu usługi smstools (zielony/czerwony),
komunikaty o wyniku operacji. Interfejs responsywny – ma działać wygodnie na telefonie;
jasny i ciemny motyw zgodnie z ustawieniem systemu.

---

## 2.1. Logowanie (M)

- Formularz: login + hasło. Hasło przechowywane jako `password_hash()`.
- Po 5 nieudanych próbach z jednego IP – blokada na 15 minut.
- Sesja wygasa po 8 h bezczynności (ustawienie), opcja „Zapamiętaj mnie” na 30 dni.
- Konto tworzy instalator (pyta o login i hasło). Brak formularza „pierwszego uruchomienia”.
- Zapomniane hasło: `sudo -u www-data php /opt/smsgui/bin/smsgui passwd`.

## 2.2. Pulpit (M)

- Kafelki: nieprzeczytane odebrane, w kolejce, wysłane dziś, błędy (ostatnie 7 dni), liczba kontaktów.
- Stan usługi: `active` / `inactive` / `failed` + czas ostatniej synchronizacji.
- Stan modemu z pliku statusu smsd (jeśli skonfigurowano `stats`). (S; pełna wersja w 2.14.4)
- **Kontrola zdrowia** – czytelne ostrzeżenia z podpowiedzią, co poprawić:
  - katalogi kolejek nie istnieją lub brak uprawnień,
  - `smsd.conf` nieczytelny / niezapisywalny,
  - brak `sent` lub `failed` w smsd.conf (panel nie pozna wyniku wysyłki – w smsd domyślnie ich nie ma),
  - `incoming_utf8` wyłączone (sugestia włączenia), ustawiony `language_file` (zmienione nazwy nagłówków),
  - `autosplit = 0` (długie SMS będą obcinane),
  - timer synchronizacji nie działa (ostatnia synchronizacja > 2 min temu),
  - wiadomości „w kolejce” dłużej niż 15 min (smsd nie działa lub nie może odczytać plików).
- 10 ostatnich wiadomości (obu kierunków).

## 2.3. Nowa wiadomość – do jednego lub wielu odbiorców (M)

### Wybór odbiorców
Odbiorców można łączyć z trzech źródeł; wynik jest **sumą bez duplikatów**:

1. **Grupy** – pola wyboru z listą grup (z liczbą członków).
2. **Kontakty** – lista z wyszukiwarką (filtrowanie po nazwie/numerze podczas pisania).
3. **Numery ręcznie** – pole tekstowe, numery oddzielone przecinkiem, średnikiem lub nową linią.

Pod polami licznik: „Odbiorców: 37 (2 duplikaty pominięte)”.
Ekran można otworzyć z gotowym wyborem: z kontaktu, z grupy, z zaznaczonych kontaktów, z rozmowy,
z „przekaż dalej” (treść odebranego SMS).

### Treść
- Pole tekstowe z **licznikiem na żywo**: liczba znaków, liczba części SMS, kodowanie
  (GSM-7 / Unicode), np. „182 znaki · 2 SMS · GSM-7”.
- Jeśli tekst wymusza Unicode (np. polskie znaki), licznik pokazuje **które znaki** to powodują
  i ile części oszczędzi zamiana: „ą, ł, ż wymuszają Unicode – 3 SMS zamiast 2”.
- Opcja **„Zamień polskie znaki”** (ą→a, ł→l…) – licznik od razu się przelicza.
  Domyślny stan opcji – w ustawieniach panelu.
- Wybór **szablonu** (S) wstawia treść do pola.
- **Personalizacja** (S): zmienne `{nazwa}` (pełna nazwa kontaktu) i `{imie}` (pierwsze słowo nazwy).
  Odbiorca spoza książki → zmienna zastępowana pustym tekstem (z ostrzeżeniem przed wysyłką).
  Podgląd: „Tak zobaczy to: Jan Kowalski → Cześć Jan, …”. Licznik liczy najdłuższy wariant.
- Podsumowanie: odbiorcy × części, np. „37 × 2 = 74 SMS”.

### Opcje
- **Raport doręczenia** (domyślny stan w ustawieniach panelu).
- **Flash SMS** (wyświetla się od razu na ekranie, nie zapisuje się w telefonie).
- **Wyślij później** – data i godzina.

### Dławienie wysyłki (S)
Przy więcej niż jednym odbiorcy wiadomości nie trafiają do kolejki smsd naraz, tylko w tempie
**N SMS na minutę** (ustawienie, domyślnie 10; 0 = bez limitu). Realizacja: każda wiadomość dostaje
`scheduled_at` rozłożone w czasie i jest wysyłana przez mechanizm wysyłki planowanej.
Formularz pokazuje szacowany czas: „37 wiadomości – wysyłka potrwa ok. 4 min”.
Powód: operatorzy mogą zablokować kartę SIM za nagłe wysłanie wielu SMS.

### Cisza nocna (S)
Jeśli wysyłka do więcej niż jednego odbiorcy wypada w godzinach ciszy nocnej (domyślnie 21:00–8:00,
ustawienie, można wyłączyć) – okno z ostrzeżeniem i wyborem: „Wyślij teraz” / „Zaplanuj na 8:00”.

### Wysłanie
- Przy więcej niż 1 odbiorcy – okno potwierdzenia z liczbą odbiorców i SMS.
- Walidacja: pusta treść, brak odbiorców, nieprawidłowe numery (lista błędnych numerów
  pokazana wraz z formularzem – nic nie jest wysyłane, dopóki ich nie poprawisz lub nie usuniesz),
  przekroczony limit `max_recipients`.
- Każdy odbiorca = osobny rekord i osobny plik w `outgoing`; wszystkie z jednej wysyłki mają wspólny
  `batch_id`. **Raport wysyłki**: ile w kolejce, wysłano, doręczono, błędów; pasek postępu przy
  wysyłce dławionej; przyciski „Ponów nieudane” i „Anuluj pozostałe”.

## 2.4. Rozmowy (M)

- Lista rozmów (jeden wiersz na numer): nazwa kontaktu lub numer, fragment ostatniej wiadomości,
  data, licznik nieprzeczytanych. Wyszukiwarka po nazwie, numerze i treści.
- **Widok rozmowy** – „dymki” jak w komunikatorze: przychodzące po lewej, wychodzące po prawej
  ze statusem (zaplanowana / w kolejce / wysłana / doręczona / błąd + przyczyna).
- Pole odpowiedzi na dole z licznikiem znaków; wysłanie bez przeładowania strony.
- Otwarcie rozmowy oznacza wiadomości jako przeczytane.
- Nowe wiadomości i zmiany statusów pojawiają się same (odświeżanie fragmentu strony co 10 s,
  bez przeładowania i bez utraty wpisywanego tekstu).
- Akcje: „Dodaj do kontaktów” (jeśli numer nieznany), „Usuń rozmowę” (z potwierdzeniem).
- Nadawcy alfanumeryczni (np. „ORLEN”, „InfoPLAY”) – rozmowa bez możliwości odpowiedzi.

## 2.5. Odebrane (M)

- Tabela: data odebrania, nadawca (kontakt/numer), treść, modem, przeczytana.
- Filtry: tekst, zakres dat, tylko nieprzeczytane.
- Akcje zbiorcze: oznacz jako przeczytane/nieprzeczytane, usuń.
- Akcje na wierszu: odpowiedz (→ rozmowa), przekaż dalej (→ nowa wiadomość z treścią).
- Stronicowanie (50 na stronę).

## 2.6. Wysłane / kolejka (M)

- Tabela: data, odbiorca, treść, status, modem, przyczyna błędu, oznaczenie „zewnętrzna”.
- **Statusy** (szczegóły w [03-integracja-smstools3.md](03-integracja-smstools3.md#35-statusy-wiadomości-wychodzących)):
  zaplanowana, w kolejce, wysłana, doręczona, niedoręczona, błąd, anulowana.
- Filtry: status, tekst, zakres dat, wysyłka (`batch_id`), źródło (panel / zewnętrzna / API).
- Akcje:
  - **Ponów** – dla „błąd”/„niedoręczona”,
  - **Anuluj** – dla „zaplanowana” (zawsze) i „w kolejce” (tylko jeśli smsd jeszcze nie pobrał pliku),
  - **Usuń** z historii,
  - **Kopiuj** – nowa wiadomość z tą samą treścią.
- Wiadomości wysłane innymi drogami (skrypty, `smssend`) też są widoczne jako „zewnętrzne”.

## 2.7. Kontakty – książka telefoniczna (M)

- Lista z wyszukiwarką i filtrem po grupie, stronicowanie.
- Pola kontaktu: **nazwa** (wymagana), **numer** (wymagany, unikalny, normalizowany do formatu
  międzynarodowego), **notatka**, **grupy** (wiele).
- Dodawanie, edycja, usuwanie (pojedyncze i zbiorcze, z potwierdzeniem).
- Akcje zbiorcze: dodaj do grupy, usuń z grupy, **wyślij SMS do zaznaczonych**.
- Ekran kontaktu pokazuje też ostatnie wiadomości z tym numerem.
- **Import CSV** (S): kolumny `nazwa;numer;grupy;notatka` (grupy oddzielone `|`), separator `;` lub `,`
  wykrywany automatycznie, UTF-8 (także z BOM). Podgląd przed importem: ile nowych, ile aktualizacji
  (dopasowanie po numerze), ile błędnych wierszy.
- **Eksport CSV** (S) – ten sam format (z BOM, żeby Excel poprawnie pokazał polskie znaki).
- Wszędzie w panelu zamiast numeru widać nazwę kontaktu (dopasowanie po numerze).

## 2.8. Grupy (M)

- Lista grup z liczbą członków; dodawanie, zmiana nazwy, usuwanie (usunięcie grupy nie usuwa kontaktów).
- Akcje: „Pokaż członków” (→ kontakty z filtrem), „Wyślij SMS do grupy”.

## 2.9. Szablony wiadomości (S)

- Lista gotowych treści (nazwa + treść, mogą zawierać zmienne personalizacji), dodawanie/edycja/usuwanie.
- Wybór szablonu na ekranie „Nowa wiadomość” wstawia treść do pola.

## 2.10. Konfiguracja smstools (M)

Cztery zakładki.

### a) Ustawienia (formularz)
Formularz dla najczęściej zmienianych parametrów. Zapis zmienia **tylko te linie**, których
dotyczy – komentarze, kolejność i pozostałe parametry w pliku zostają nietknięte.
Puste pole = parametr usuwany z pliku (smsd użyje wartości domyślnej).
Przy każdym polu krótki opis i wartość domyślna smsd.

| Sekcja | Parametr | Kontrolka | Opis w panelu |
|--------|----------|-----------|---------------|
| globalna | `devices` | tekst | nazwy modemów (sekcji), np. `GSM1` |
| globalna | `loglevel` | lista | 3 błędy … 7 debug |
| globalna | `autosplit` | lista | 0 brak (obcinanie) / 1 / 2 / 3 UDH – zalecane |
| globalna | `receive_before_send` | tak/nie | najpierw odbieraj, potem wysyłaj |
| globalna | `incoming_utf8` | tak/nie | zapis odebranych w UTF-8 (zalecane: tak) |
| globalna | `delaytime` | liczba | co ile sekund smsd sprawdza kolejkę |
| globalna | `outgoing`, `checked`, `incoming`, `sent`, `failed`, `logfile`, `stats` | tekst | ścieżki – z ostrzeżeniem, że panel musi mieć do nich dostęp |
| modem | `device` | tekst | np. `/dev/ttyUSB0` lub `/dev/serial/by-id/…` |
| modem | `baudrate` | lista | 9600 … 115200 |
| modem | `pin` | tekst (maskowany) | PIN karty SIM |
| modem | `smsc` | tekst | numer centrum SMS (zwykle niepotrzebny) |
| modem | `incoming` | lista | nie / tak / wysoki priorytet |
| modem | `report` | tak/nie | raporty doręczenia dla wszystkich SMS |
| modem | `init` | tekst | dodatkowa komenda AT przy starcie |
| modem | `cs_convert` | tak/nie | konwersja znaków ISO→GSM |
| modem | `cs_convert_optical` | tak/nie | „zamiana optyczna” znaków spoza GSM (np. ó→o); panel jej nie potrzebuje |
| modem | `messageids` | lista | 1 pierwsza / 2 ostatnia (domyślnie) / 3 wszystkie części – ID do raportów |

Sekcje modemów są tworzone na podstawie `devices` – dopisanie `GSM2` i zapis dodaje
nową, pustą sekcję do uzupełnienia.

### b) Edytor pliku
Pełna treść `smsd.conf` w polu tekstowym (czcionka o stałej szerokości, numeracja linii).
Przed zapisem prosta walidacja: każdy modem z `devices` ma swoją sekcję, wymagane ścieżki istnieją,
nie ma niezrozumiałych linii.

### Potwierdzenie zapisu (decyzja D4)
Każdy zapis (formularz, edytor, przywrócenie kopii) otwiera okno:

> **Zapisujesz główny plik konfiguracyjny smstools3 (`/etc/smsd.conf`).**
> Błędna konfiguracja może sprawić, że smstools3 nie uruchomi się lub przestanie wysyłać i odbierać SMS.
> Przed zapisem zostanie utworzona kopia zapasowa, którą przywrócisz w zakładce „Kopie zapasowe”.
>
> Zmiany: *(lista zmienionych parametrów lub podgląd różnic w pliku)*
> Ostrzeżenia walidacji: *(jeśli są)*
>
> ☐ Zrestartuj smstools po zapisie (zmiany działają dopiero po restarcie)
>
> [Anuluj] [Zapisz konfigurację]

Okno pokazuje **różnice** między obecnym a nowym plikiem (dodane linie na zielono, usunięte na czerwono),
żeby było widać dokładnie, co się zmieni.

### c) Kopie zapasowe
Każdy zapis najpierw tworzy kopię `/var/lib/smsgui/backups/smsd.conf.RRRRMMDD-GGMMSS`.
Lista kopii z możliwością **podglądu, porównania z bieżącą wersją** i **przywrócenia**
(przywrócenie też przechodzi przez okno potwierdzenia). Trzymane jest ostatnie 30 kopii.

### d) Usługa
- Stan usługi, przycisk **„Restartuj smstools”** (z potwierdzeniem) i wynik polecenia.
- Po restarcie panel pokazuje ostatnie ~20 linii logu, żeby od razu było widać, czy modem wstał.

## 2.11. Log smsd (S)

- Ostatnie N linii (100 / 500 / 2000) pliku logu, najnowsze na górze lub na dole.
- Filtr tekstowy i podświetlanie poziomów (błędy na czerwono).
- Automatyczne odświeżanie (przełącznik).

## 2.12. Ustawienia panelu i konto (M)

**Ustawienia panelu** (zapisywane w bazie, edytowalne z WWW):
- domyślny stan opcji: raport doręczenia, zamiana polskich znaków,
- dławienie: SMS na minutę,
- cisza nocna: włączona, godziny,
- czas wygaśnięcia sesji,
- automatyczne czyszczenie historii (R),
- liczba kopii zapasowych smsd.conf.

Ustawienia systemowe (ścieżki, polecenia, baza) są tylko w `config.php` i wyświetlane w panelu do odczytu.

**Zmiana hasła** – wymaga podania obecnego hasła; zmiana loginu – opcjonalnie w tym samym formularzu.

## 2.13. Narzędzie konsolowe `bin/smsgui` (M)

| Polecenie | Działanie |
|-----------|-----------|
| `sync` | jednorazowa synchronizacja kolejek (używane przez timer systemd) |
| `passwd [login]` | ustawienie loginu i hasła konta (używane przez instalator i gdy hasło zostało zapomniane) |
| `check [--quiet]` | diagnostyka: wersje, ścieżki, uprawnienia, odczyt smsd.conf, stan usługi; kod wyjścia ≠ 0 przy problemie |
| `cleanup [--days=N]` | usunięcie starych wiadomości |

---

## 2.14. Rozszerzenia (R – etap końcowy)

Ekran **Automatyzacje** grupuje 2.14.1–2.14.3. Wszystkie automatyzacje wykonuje synchronizacja
(timer), więc działają także przy zamkniętym panelu.

### 2.14.1. Przekazywanie odebranych SMS
- Reguły: **warunek** (wszystkie / od numeru lub kontaktu / od grupy / treść zawiera tekst)
  → **kanał** (e-mail / webhook – decyzja D12). Reguły można włączać i wyłączać.
- E-mail: przez PHPMailer (decyzja D17) – systemowy `sendmail` (np. pakiet `msmtp-mta`) albo bezpośrednio
  serwer SMTP podany w ustawieniach (host, port, STARTTLS/SSL, login, hasło); przełącznik w zakładce „Poczta”.
  Temat: „SMS od <nazwa/numer>”, treść: wiadomość + data + modem.
- Webhook: `POST` z JSON (`from`, `name`, `text`, `received_at`, `modem`) na podany adres,
  opcjonalny nagłówek z sekretem (np. `X-Smsgui-Signature` – HMAC treści).
- Kanały zaprojektowane jako wymienne moduły – Telegram i inne dodamy później bez zmian w regułach.
- Przycisk „Wyślij test”, licznik i ostatni błąd przy każdej regule. Nieudane przekazanie ponawiane
  przy kolejnych synchronizacjach (maks. 5 prób).

### 2.14.2. Proste HTTP API
- Tokeny tworzone w panelu (nazwa, data utworzenia, ostatnie użycie, możliwość unieważnienia).
  Token pokazywany tylko raz, w bazie przechowywany jego skrót.
- `POST /api/send` – parametry `to` (jeden lub wiele numerów), `text`, opcjonalnie `report`, `flash`;
  odpowiedź JSON z identyfikatorami wiadomości.
- `GET /api/status?id=…` – status wiadomości.
- Uwierzytelnienie nagłówkiem `Authorization: Bearer <token>`. Opcjonalna lista dozwolonych adresów IP przy tokenie.
- Wiadomości z API widoczne w „Wysłane” ze źródłem „API: <nazwa tokenu>”. Dotyczą ich te same limity.

### 2.14.3. Autoodpowiedzi
- Reguły: **słowo kluczowe** (treść równa / zaczyna się od / zawiera) lub **godziny** (np. poza 8–16 w dni robocze)
  → treść odpowiedzi.
- Zabezpieczenie przed pętlą: najwyżej jedna autoodpowiedź do tego samego numeru na 60 min;
  brak odpowiedzi na nadawców alfanumerycznych i raporty.

### 2.14.4. Stan modemu i karty SIM
- Sygnał, operator, saldo/pakiet przez kod USSD – z pliku `regular_run_statfile`, do którego smsd
  co kilka minut zapisuje odpowiedzi modemu (mechanizm opisany w [03, rozdz. 3.8](03-integracja-smstools3.md#38-stan-modemu-dla-rozszerzenia-2144)).
- Przycisk „Włącz monitorowanie modemu” dopisuje odpowiednie parametry do sekcji modemu
  (przez okno potwierdzenia zapisu konfiguracji). Kod USSD salda – w ustawieniach.
- Działanie USSD zależy od modemu i operatora – panel pokazuje surową odpowiedź, jeśli nie umie jej zinterpretować.

### 2.14.5. Powiadomienia administracyjne
Wysyłane tymi samymi kanałami co przekazywanie (e-mail / webhook) lub SMS-em na wskazany numer, gdy:
usługa smstools nie działa, wiadomości stoją w kolejce > 15 min, saldo poniżej progu,
kilka kolejnych wysyłek zakończonych błędem. Najwyżej jedno powiadomienie danego typu na godzinę.

### 2.14.6. Statystyki
Wykresy SMS wysłanych/odebranych dziennie (30 dni) i miesięcznie (12 mies.), odsetek doręczeń,
najaktywniejsze numery. Wykresy jako proste SVG generowane po stronie serwera (bez bibliotek).

### 2.14.7. Wybór modemu przy wysyłce
Przy kilku modemach – lista „Wyślij przez” na ekranie nowej wiadomości (domyślnie: dowolny).
Realizacja przez kolejki smstools: w `smsd.conf` sekcja `[queues]` (nazwa kolejki → katalog) i w sekcji modemu
`queues = NAZWA`; panel dodaje nagłówek `Queue:`. Lista „Wyślij przez” pojawia się tylko, gdy `[queues]` istnieje.

### 2.14.8. Automatyczne czyszczenie historii
Ustawienie „usuwaj wiadomości starsze niż N dni” (domyślnie wyłączone), wykonywane raz dziennie.

### 2.14.9. Wersja angielska interfejsu
Przełącznik języka w ustawieniach.
