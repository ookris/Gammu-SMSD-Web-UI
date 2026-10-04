# 2. Specyfikacja funkcjonalna

Oznaczenia priorytetów: **M** – musi być w wersji 1, **S** – powinno być w wersji 1,
**R** – rozszerzenie realizowane w etapie końcowym.

Panel ma **jedno konto** z dostępem do wszystkich funkcji (decyzja D1). Każda funkcja jest dostępna
**z przeglądarki** (D20) – konsola serwera służy tylko do instalacji i w sytuacjach awaryjnych.

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
    ├── Połączenia                        (S)
    ├── Zablokowane numery                (M)
    ├── Modem ── USSD                     (M: stan i USSD; R: kilka modemów, saldo cykliczne)
    ├── Konfiguracja Gammu
    ├── Log Gammu                         (S)
    ├── Ustawienia panelu
    ├── Automatyzacje                     (R: przekazywanie, autoodpowiedzi, API)
    ├── Statystyki                        (R)
    └── Zmiana hasła
```

Wspólne elementy każdej strony: menu boczne (na telefonie zwijane), licznik nieprzeczytanych
przy „Rozmowy/Odebrane” (odświeżany co 15 s), wskaźnik stanu usługi Gammu i modemu (zielony / żółty – modem
niedostępny / czerwony – usługa nie działa), komunikaty o wyniku operacji. Interfejs responsywny – ma działać
wygodnie na telefonie; jasny i ciemny motyw zgodnie z ustawieniem systemu.

---

## 2.1. Logowanie (M)

- Formularz: login + hasło. Hasło przechowywane jako `password_hash()`.
- Po 5 nieudanych próbach z jednego IP – blokada na 15 minut.
- Sesja wygasa po 8 h bezczynności (ustawienie), opcja „Zapamiętaj mnie” na 30 dni.
- Konto tworzy instalator (pyta o login i hasło). Brak formularza „pierwszego uruchomienia”.
- Zapomniane hasło (jedyna czynność wymagająca konsoli): `sudo -u www-data php /opt/smsgui/bin/smsgui passwd`.

## 2.2. Pulpit (M)

- Kafelki: nieprzeczytane odebrane, w kolejce, wysłane dziś, błędy (ostatnie 7 dni), liczba kontaktów,
  odrzucone połączenia dziś (jeśli funkcja włączona).
- Stan usługi: `active` / `inactive` / `failed` + czas ostatniej synchronizacji.
- **Stan modemu** z tabeli `phones`: sygnał, operator, czas ostatniej aktualizacji (szczegóły – ekran Modem).
- **Kontrola zdrowia** – czytelne ostrzeżenia z podpowiedzią, co poprawić:
  - brak połączenia z bazą, brak bazy `gammu` lub wersja schematu ≠ 17,
  - tabele Gammu w silniku MyISAM (ryzyko wysłania niepełnego SMS wieloczęściowego),
  - `gammu-smsdrc` nieczytelny / niezapisywalny, `service` ≠ `sql` albo dane bazy niezgodne z panelem,
  - `DeliveryReportDelay` < 3600 s (raporty od wyłączonych telefonów nie zostaną dopasowane),
  - ustawiona lista `IncludeNumbers…` (czarna lista nie działa), plik czarnej listy niezgodny z bazą panelu,
  - różnica czasu między bazą a PHP > 60 s (błędne godziny wysyłki planowanej),
  - proces w tle nie działa (ostatnia synchronizacja > 1 min temu),
  - modem niedostępny (brak aktualizacji w `phones`),
  - wiadomości „w kolejce” dłużej niż 15 min bez ponowień (Gammu nie wysyła).
- 10 ostatnich wiadomości (obu kierunków).

## 2.3. Nowa wiadomość – do jednego lub wielu odbiorców (M)

### Wybór odbiorców
Odbiorców można łączyć z trzech źródeł; wynik jest **sumą bez duplikatów**:

1. **Grupy** – pola wyboru z listą grup (z liczbą członków).
2. **Kontakty** – lista z wyszukiwarką (filtrowanie po nazwie/numerze podczas pisania).
3. **Numery ręcznie** – pole tekstowe, numery oddzielone przecinkiem, średnikiem lub nową linią.

Pod polami licznik: „Odbiorców: 37 (2 duplikaty pominięte)”. Numery z czarnej listy – ostrzeżenie (można wysłać).
Ekran można otworzyć z gotowym wyborem: z kontaktu, z grupy, z zaznaczonych kontaktów, z rozmowy,
z połączenia, z „przekaż dalej” (treść odebranego SMS).

### Treść
- Pole tekstowe z **licznikiem na żywo**: liczba znaków, liczba części SMS, kodowanie
  (GSM-7 / Unicode), np. „182 znaki · 2 SMS · GSM-7”.
- Jeśli tekst wymusza Unicode (np. polskie znaki), licznik pokazuje **które znaki** to powodują
  i ile części oszczędzi zamiana: „ą, ł, ż wymuszają Unicode – 3 SMS zamiast 2”.
- Opcja **„Zamień polskie znaki”** (ą→a, ł→l…) – licznik od razu się przelicza.
  Domyślny stan opcji – w ustawieniach panelu.
- Limit: 10 części na wiadomość (ok. 1530 znaków GSM / 670 Unicode).
- Wybór **szablonu** (S) wstawia treść do pola.
- **Personalizacja** (S): zmienne `{nazwa}` (pełna nazwa kontaktu) i `{imie}` (pierwsze słowo nazwy).
  Odbiorca spoza książki → zmienna zastępowana pustym tekstem (z ostrzeżeniem przed wysyłką).
  Podgląd: „Tak zobaczy to: Jan Kowalski → Cześć Jan, …”. Licznik liczy najdłuższy wariant.
- Podsumowanie: odbiorcy × części, np. „37 × 2 = 74 SMS”.

### Opcje
- **Raport doręczenia** (domyślny stan w ustawieniach panelu).
- **Flash SMS** (wyświetla się od razu na ekranie, nie zapisuje się w telefonie).
- **Wyślij później** – data i godzina (wysyłkę o czasie realizuje Gammu – `SendingDateTime`).
- **Wyślij priorytetowo** – wiadomość wyprzedza wysyłki masowe w kolejce (rozdz. 3.6). Pojedyncze SMS
  i odpowiedzi w rozmowie mają wyższy priorytet zawsze.

### Dławienie wysyłki (S)
Przy więcej niż jednym odbiorcy wiadomości nie są wysyłane naraz, tylko w tempie
**N SMS na minutę** (ustawienie, domyślnie 10; 0 = bez limitu). Realizacja: każda wiadomość dostaje
`SendingDateTime` rozłożone w czasie. Formularz pokazuje szacowany czas: „37 wiadomości – wysyłka potrwa ok. 4 min”.
Powód: operatorzy mogą zablokować kartę SIM za nagłe wysłanie wielu SMS.

### Okno wysyłki (S) – zastępuje „ciszę nocną”
Ustawienie „wysyłki do wielu odbiorców tylko w godzinach” (domyślnie 8:00–21:00, można wyłączyć).
Realizowane przez Gammu (`SendAfter`/`SendBefore`, rozdz. 3.6): wiadomości zapisane poza oknem czekają w kolejce
i wychodzą automatycznie po jego otwarciu. Formularz pokazuje informację: „Teraz jest 22:15 – wysyłka rozpocznie się
o 8:00” i pozwala jednorazowo wyłączyć okno („Wyślij od razu”). Okno nie może przechodzić przez północ
(ograniczenie Gammu) – formularz ustawień to sprawdza.

### Wysłanie
- Przy więcej niż 1 odbiorcy – okno potwierdzenia z liczbą odbiorców i SMS.
- Walidacja: pusta treść, brak odbiorców, nieprawidłowe numery (lista błędnych numerów
  pokazana wraz z formularzem – nic nie jest wysyłane, dopóki ich nie poprawisz lub nie usuniesz),
  przekroczony limit `max_recipients`, przekroczony limit części.
- Każdy odbiorca = osobny rekord i osobny wiersz w kolejce Gammu; wszystkie z jednej wysyłki mają wspólny
  `batch_id`. **Raport wysyłki**: ile zaplanowano, w kolejce, wysłano, doręczono, błędów; pasek postępu przy
  wysyłce dławionej; przyciski „Ponów nieudane” i „Anuluj pozostałe”.

## 2.4. Rozmowy (M)

- Lista rozmów (jeden wiersz na numer): nazwa kontaktu lub numer, fragment ostatniej wiadomości,
  data, licznik nieprzeczytanych. Wyszukiwarka po nazwie, numerze i treści.
- **Widok rozmowy** – „dymki” jak w komunikatorze: przychodzące po lewej, wychodzące po prawej
  ze statusem (zaplanowana / w kolejce / ponawiana / wysłana / doręczona / błąd + przyczyna).
  Odrzucone połączenia z tym numerem jako krótkie wpisy w osi czasu (ikona telefonu + „połączenie odrzucone 14:32”).
- Pole odpowiedzi na dole z licznikiem znaków; wysłanie bez przeładowania strony.
- Otwarcie rozmowy oznacza wiadomości jako przeczytane.
- Nowe wiadomości i zmiany statusów pojawiają się same (odświeżanie fragmentu strony co 10 s,
  bez przeładowania i bez utraty wpisywanego tekstu).
- Akcje: „Dodaj do kontaktów” (jeśli numer nieznany), „Zablokuj numer”, „Usuń rozmowę” (z potwierdzeniem).
- Nadawcy alfanumeryczni (np. „ORLEN”, „InfoPLAY”) – rozmowa bez możliwości odpowiedzi.

## 2.5. Odebrane (M)

- Tabela: data odebrania, nadawca (kontakt/numer), treść, modem, przeczytana; oznaczenia „Flash”, „niekompletna”.
- Filtry: tekst, zakres dat, tylko nieprzeczytane.
- Akcje zbiorcze: oznacz jako przeczytane/nieprzeczytane, usuń.
- Akcje na wierszu: odpowiedz (→ rozmowa), przekaż dalej (→ nowa wiadomość z treścią), zablokuj nadawcę.
- Stronicowanie (50 na stronę).

## 2.6. Wysłane / kolejka (M)

- Tabela: data, odbiorca, treść, części, status, modem, przyczyna błędu, oznaczenie „zewnętrzna”.
- **Statusy** (szczegóły w [03-integracja-gammu-smsd.md](03-integracja-gammu-smsd.md#36-statusy-wysyłka-planowana-okna-wysyłki-i-priorytet)):
  zaplanowana, w kolejce (także „ponawiana – próba X z Y”), wysłana, doręczona, niedoręczona, błąd, anulowana.
- Filtry: status, tekst, zakres dat, wysyłka (`batch_id`), źródło (panel / zewnętrzna / API).
- Akcje:
  - **Ponów** – dla „błąd”/„niedoręczona”,
  - **Anuluj** – dla „zaplanowana” i „w kolejce” (o ile Gammu właśnie jej nie wysyła),
  - **Usuń** z historii,
  - **Kopiuj** – nowa wiadomość z tą samą treścią.
- Wiadomości wysłane innymi drogami (`gammu-smsd-inject`, inne programy piszące do bazy Gammu) też są widoczne
  jako „zewnętrzne”.

## 2.7. Kontakty – książka telefoniczna (M)

- Lista z wyszukiwarką i filtrem po grupie, stronicowanie.
- Pola kontaktu: **nazwa** (wymagana), **numer** (wymagany, unikalny, normalizowany do formatu
  międzynarodowego), **notatka**, **grupy** (wiele).
- Dodawanie, edycja, usuwanie (pojedyncze i zbiorcze, z potwierdzeniem).
- Akcje zbiorcze: dodaj do grupy, usuń z grupy, **wyślij SMS do zaznaczonych**.
- Ekran kontaktu pokazuje też ostatnie wiadomości i połączenia z tym numerem oraz przycisk „Zablokuj”.
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

## 2.10. Konfiguracja Gammu (M)

Cztery zakładki.

### a) Ustawienia (formularz)
Formularz dla najczęściej zmienianych parametrów `/etc/gammu-smsdrc`. Zapis zmienia **tylko te linie**, których
dotyczy – komentarze, kolejność i pozostałe parametry w pliku zostają nietknięte.
Puste pole = parametr usuwany z pliku (Gammu użyje wartości domyślnej).
Przy każdym polu krótki opis i wartość domyślna Gammu. Parametry zarządzane przez panel (dane bazy, połączenia,
czarna lista) nie występują w formularzu ([03, rozdz. 3.8](03-integracja-gammu-smsd.md#38-edycja-etcgammu-smsdrc)).

| Sekcja | Parametr | Kontrolka | Opis w panelu |
|--------|----------|-----------|---------------|
| `[gammu]` | `device` | tekst / lista | port modemu, np. `/dev/serial/by-id/…` (lista wykrytych portów) |
| `[gammu]` | `connection` | lista | `at` (zalecane), `at115200`, `at19200` … |
| `[smsd]` | `phoneid` | tekst | nazwa modemu widoczna w panelu (np. `GSM1`) |
| `[smsd]` | `pin` | tekst (maskowany) | PIN karty SIM |
| `[smsd]` | `smsc` | tekst | numer centrum SMS (zwykle niepotrzebny) |
| `[smsd]` | `send`, `receive` | tak/nie | wysyłanie / odbieranie włączone |
| `[smsd]` | `deliveryreportdelay` | liczba (s) | jak długo czekać na raport doręczenia (zalecane: 172800 = 2 dni; domyślnie 600) |
| `[smsd]` | `maxretries`, `retrytimeout` | liczby | liczba ponowień nieudanej wysyłki i odstęp (domyślnie 1 i 600 s) |
| `[smsd]` | `multiparttimeout` | liczba (s) | jak długo czekać na brakujące części odebranej wiadomości (domyślnie 600) |
| `[smsd]` | `statusfrequency` | liczba (s) | co ile odświeżać stan modemu (domyślnie 60) |
| `[smsd]` | `checksignal`, `checknetwork`, `checkbattery` | tak/nie | które informacje o modemie zbierać |
| `[smsd]` | `resetfrequency`, `hardresetfrequency` | liczby (s) | okresowy reset modemu (0 = wyłączony) – pomaga przy „zawieszających się” modemach |
| `[smsd]` | `loopsleep`, `commtimeout`, `sendtimeout` | liczby (s) | czasy pętli i oczekiwania na modem |
| `[smsd]` | `debuglevel` | lista | szczegółowość logu Gammu |
| `[smsd]` | `logfile` | tekst | ścieżka logu (z ostrzeżeniem, że panel musi mieć do niego dostęp) |

Kilka modemów (R, 2.17.7) = kilka plików konfiguracji – formularz ma wtedy wybór modemu u góry.

### b) Edytor pliku
Pełna treść `gammu-smsdrc` w polu tekstowym (czcionka o stałej szerokości, numeracja linii); hasło do bazy maskowane.
Przed zapisem prosta walidacja: wymagane sekcje `[gammu]` i `[smsd]`, `service = sql`, dane bazy zgodne z panelem,
istniejący port modemu, brak niezrozumiałych linii, ostrzeżenie przy zmianie parametrów zarządzanych przez panel.

### Potwierdzenie zapisu (decyzja D4)
Każdy zapis (formularz, edytor, przywrócenie kopii, włączenie funkcji panelu zmieniającej konfigurację) otwiera okno:

> **Zapisujesz główny plik konfiguracyjny Gammu SMSD (`/etc/gammu-smsdrc`).**
> Błędna konfiguracja może sprawić, że Gammu nie uruchomi się lub przestanie wysyłać i odbierać SMS.
> Przed zapisem zostanie utworzona kopia zapasowa, którą przywrócisz w zakładce „Kopie zapasowe”.
>
> Zmiany: *(lista zmienionych parametrów lub podgląd różnic w pliku)*
> Ostrzeżenia walidacji: *(jeśli są)*
>
> ◉ Przeładuj Gammu po zapisie (zalecane) ○ Zrestartuj usługę ○ Nie rób nic
>
> [Anuluj] [Zapisz konfigurację]

Okno pokazuje **różnice** między obecnym a nowym plikiem (dodane linie na zielono, usunięte na czerwono).

### c) Kopie zapasowe
Każdy zapis najpierw tworzy kopię `/var/lib/smsgui/backups/gammu-smsdrc.RRRRMMDD-GGMMSS`.
Lista kopii z możliwością **podglądu, porównania z bieżącą wersją** i **przywrócenia**
(przywrócenie też przechodzi przez okno potwierdzenia). Trzymane jest ostatnie 30 kopii.

### d) Usługa
- Stan usługi, przyciski **„Przeładuj konfigurację”** i **„Restartuj Gammu”** (z potwierdzeniem) i wynik polecenia.
- Po restarcie panel pokazuje ostatnie ~20 linii logu i czeka (do 30 s) na pojawienie się modemu w tabeli `phones`,
  żeby od razu było widać, czy modem wstał.

## 2.11. Log Gammu (S)

- Ostatnie N linii (100 / 500 / 2000) pliku logu, najnowsze na górze lub na dole.
- Filtr tekstowy i podświetlanie błędów (linie z `Error`, `Failed` na czerwono).
- Automatyczne odświeżanie (przełącznik).

## 2.12. Modem i USSD (M)

### Stan modemu
- Dla każdego modemu (wiersz `phones`): nazwa (PhoneID), stan (dostępny / niedostępny od…), sygnał (pasek + %),
  operator, IMEI, IMSI, bateria (jeśli modem podaje), liczba wysłanych i odebranych od startu, wersja Gammu,
  czas ostatniej aktualizacji. Odświeżanie co 15 s.
- Ostatnie saldo (jeśli skonfigurowano cykliczne sprawdzanie – R, 2.17.4).

### USSD na żądanie
- Pole „Kod USSD” (np. `*101#`) + wybór modemu (przy kilku) + przycisk „Wyślij”; lista szybkich kodów
  zapisanych w ustawieniach (np. „Saldo: `*101#`”, „Numer karty: `*100#`”).
- Po wysłaniu: wskaźnik oczekiwania, odpowiedź pojawia się bez przeładowania strony (htmx, do 60 s).
- Gdy operator czeka na wybór z menu – pole „Odpowiedz” (np. `1`).
- Historia żądań i odpowiedzi (data, kod, odpowiedź, stan sesji).
- Ograniczenia: jedno oczekujące żądanie na modem; działanie zależy od modemu i operatora – panel pokazuje
  surową odpowiedź i stan sesji Gammu.

## 2.13. Połączenia przychodzące (S)

- Przełącznik w ustawieniach panelu **„Odrzucaj połączenia przychodzące i zapisuj je w panelu”** – włącza
  `HangupCalls` i `RunOnIncomingCall` w konfiguracji Gammu (przez okno potwierdzenia zapisu). Opis przy przełączniku:
  „Gammu nie pozwala zapisywać połączeń bez ich odrzucania – każdy dzwoniący usłyszy rozłączenie”.
- Ekran **Połączenia**: data, numer / kontakt, modem; filtry: zakres dat, numer; akcje: „Wyślij SMS” (→ nowa
  wiadomość), „Dodaj do kontaktów”, „Zablokuj”, usuń. Numer zastrzeżony pokazywany jako „numer ukryty”.
- Licznik odrzuconych połączeń na pulpicie, wpisy w widoku rozmowy.
- Automatyzacje (R): autoodpowiedź SMS na połączenie (np. „Nie odbieram połączeń, napisz SMS”), przekazanie
  informacji o połączeniu na e-mail / webhook – regułami z 2.17.1 i 2.17.3.

## 2.14. Zablokowane numery (M)

- Lista: numer / nazwa nadawcy, kontakt, notatka, data dodania; dodawanie (numer lub nazwa alfanumeryczna + notatka),
  usuwanie, wyszukiwarka.
- Blokowanie także z rozmowy, „Odebranych”, kontaktu i listy połączeń.
- Działanie: SMS od zablokowanego numeru **nie trafia do bazy** – Gammu usuwa go z modemu (ExcludeNumbersFile).
  Połączenia z zablokowanych numerów są nadal odrzucane i zapisywane, ale bez automatyzacji.
- Po każdej zmianie panel zapisuje plik listy i przeładowuje Gammu (bez okna potwierdzenia – plik listy jest
  w całości zarządzany przez panel, a `gammu-smsdrc` się nie zmienia). Pierwsze włączenie dopisuje `ExcludeNumbersFile`
  do konfiguracji przez okno potwierdzenia.
- Import / eksport listy (CSV: `numer;notatka`).

## 2.15. Ustawienia panelu i konto (M)

**Ustawienia panelu** (zapisywane w bazie, edytowalne z WWW):
- domyślny stan opcji: raport doręczenia, zamiana polskich znaków,
- dławienie: SMS na minutę,
- okno wysyłki do wielu odbiorców: włączone, godziny,
- szybkie kody USSD,
- odrzucanie i zapisywanie połączeń (2.13),
- czas wygaśnięcia sesji,
- automatyczne czyszczenie historii (R),
- liczba kopii zapasowych `gammu-smsdrc`.

Ustawienia systemowe (ścieżki, polecenia, baza) są tylko w `config.php` i wyświetlane w panelu do odczytu (bez haseł).

**Zmiana hasła** – wymaga podania obecnego hasła; zmiana loginu – opcjonalnie w tym samym formularzu.

## 2.16. Narzędzie konsolowe `bin/smsgui` (M)

Używane przez instalator, usługi systemd i w sytuacjach awaryjnych – nie jest potrzebne w codziennej pracy (D20).

| Polecenie | Działanie |
|-----------|-----------|
| `worker` | proces w tle: synchronizacja co `worker_interval` s (uruchamiany przez `smsgui-worker.service`) |
| `sync` | jednorazowa synchronizacja |
| `hook call --phone=<PhoneID> [numer]` | zapis połączenia przychodzącego (wywoływany przez Gammu – `RunOnIncomingCall`) |
| `passwd [login]` | ustawienie loginu i hasła konta (instalator, zapomniane hasło) |
| `check [--quiet]` | diagnostyka: wersje, baza i schemat Gammu, silnik tabel, konfiguracja, uprawnienia, stan usługi i modemu; kod wyjścia ≠ 0 przy problemie |
| `setup …` | kroki instalatora korzystające z parsera panelu (zmiany w `gammu-smsdrc`, utworzenie baz) |
| `send <numer> <treść> [--wait=N]` | wysyłka testowa (instalator) |
| `cleanup [--days=N]` | usunięcie starych wiadomości |

---

## 2.17. Rozszerzenia (R – etap końcowy)

Ekran **Automatyzacje** grupuje 2.17.1–2.17.3. Wszystkie automatyzacje wykonuje proces w tle,
więc działają także przy zamkniętym panelu.

### 2.17.1. Przekazywanie odebranych SMS i połączeń
- Reguły: **zdarzenie** (SMS / połączenie) + **warunek** (wszystkie / od numeru lub kontaktu / od grupy /
  treść zawiera tekst – tylko SMS) → **kanał** (e-mail / webhook – decyzja D12). Reguły można włączać i wyłączać.
- E-mail: przez PHPMailer (decyzja D17) – systemowy `sendmail` (np. pakiet `msmtp-mta`) albo bezpośrednio
  serwer SMTP podany w ustawieniach (host, port, STARTTLS/SSL, login, hasło); przełącznik w zakładce „Poczta”.
  Temat: „SMS od <nazwa/numer>” / „Połączenie od <nazwa/numer>”, treść: wiadomość + data + modem.
- Webhook: `POST` z JSON (`type` = `sms`/`call`, `from`, `name`, `text`, `received_at`, `modem`) na podany adres,
  opcjonalny nagłówek z sekretem (`X-Smsgui-Signature` – HMAC treści).
- Kanały zaprojektowane jako wymienne moduły – Telegram i inne dodamy później bez zmian w regułach.
- Przycisk „Wyślij test”, licznik i ostatni błąd przy każdej regule. Nieudane przekazanie ponawiane
  przy kolejnych przebiegach (maks. 5 prób).
- Zablokowane numery są pomijane.

### 2.17.2. Proste HTTP API
- Tokeny tworzone w panelu (nazwa, data utworzenia, ostatnie użycie, możliwość unieważnienia).
  Token pokazywany tylko raz, w bazie przechowywany jego skrót.
- `POST /api/send` – parametry `to` (jeden lub wiele numerów), `text`, opcjonalnie `report`, `flash`, `modem`,
  `send_at`, `priority` (`normal`/`high`); odpowiedź JSON z identyfikatorami wiadomości.
- `GET /api/status?id=…` – status wiadomości.
- Uwierzytelnienie nagłówkiem `Authorization: Bearer <token>`. Opcjonalna lista dozwolonych adresów IP przy tokenie.
- Wiadomości z API widoczne w „Wysłane” ze źródłem „API: <nazwa tokenu>”. Dotyczą ich te same limity.

### 2.17.3. Autoodpowiedzi
- Reguły: **słowo kluczowe** (treść równa / zaczyna się od / zawiera), **godziny** (np. poza 8–16 w dni robocze)
  lub **połączenie odrzucone** → treść odpowiedzi.
- Zabezpieczenie przed pętlą: najwyżej jedna autoodpowiedź do tego samego numeru na 60 min;
  brak odpowiedzi na nadawców alfanumerycznych, numery ukryte i zablokowane.

### 2.17.4. Cykliczne sprawdzanie salda
- Kod USSD salda i odstęp (np. co 6 h) w ustawieniach; wysyłany przez ten sam mechanizm co USSD na żądanie.
- Panel próbuje odczytać kwotę z odpowiedzi (wyrażenie regularne z ustawień, domyślne dla typowych formatów
  „12,34 zł”); jeśli się nie uda – pokazuje surową odpowiedź.
- Wykres salda (30 dni) na ekranie Modem; próg niskiego salda dla powiadomień (2.17.5).

### 2.17.5. Powiadomienia administracyjne
Wysyłane tymi samymi kanałami co przekazywanie (e-mail / webhook) lub SMS-em na wskazany numer, gdy:
usługa Gammu nie działa, modem niedostępny (brak aktualizacji w `phones`), słaby sygnał przez dłuższy czas,
wiadomości stoją w kolejce > 15 min, saldo poniżej progu, kilka kolejnych wysyłek zakończonych błędem,
proces w tle nie działa (wysyłane przez `check --quiet` z timera systemd). Najwyżej jedno powiadomienie danego typu na godzinę.

### 2.17.6. Statystyki
Wykresy SMS wysłanych/odebranych dziennie (30 dni) i miesięcznie (12 mies.), odsetek doręczeń,
najaktywniejsze numery, liczba odrzuconych połączeń. Wykresy jako proste SVG generowane po stronie serwera (bez bibliotek).

### 2.17.7. Kilka modemów
- Każdy modem = osobny proces Gammu z własną konfiguracją (`/etc/gammu-smsdrc-<PhoneID>`) i własną usługą
  (`gammu-smsd@<PhoneID>.service` – szablon dostarczany przez panel); wszystkie zapisują do tej samej bazy `gammu`.
- „Dodaj modem” w panelu: wybór portu, PhoneID, PIN → nowy plik konfiguracji (przez okno potwierdzenia) i uruchomienie usługi
  (polecenia `systemctl` dla `gammu-smsd@*` dopuszczone w sudoers).
- Lista „Wyślij przez” na ekranie nowej wiadomości (domyślnie: dowolny) → `outbox.SenderID`. Odpowiedź w rozmowie
  domyślnie przez modem, który odebrał ostatnią wiadomość od tego numeru.
- Formularz konfiguracji, log, usługa, USSD – z wyborem modemu.

### 2.17.8. Automatyczne czyszczenie historii
Ustawienie „usuwaj wiadomości starsze niż N dni” (domyślnie wyłączone), wykonywane raz dziennie;
obejmuje też połączenia, historię USSD i zaimportowane wiersze w tabelach Gammu.

### 2.17.9. Wersja angielska interfejsu
Przełącznik języka w ustawieniach.
