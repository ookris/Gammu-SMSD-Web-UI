# 7. Plan realizacji i testów

Każdy etap kończy się działającą, możliwą do przetestowania wersją.
Szczegółowe zadania i pliki dla każdego etapu: [09-plan-implementacji.md](09-plan-implementacji.md).

| Etap | Zakres | Kryterium odbioru |
|------|--------|-------------------|
| **0. Weryfikacja środowiska** | ✔ zachowanie Gammu SMSD sprawdzone w kodzie źródłowym 1.42.0 (tabele, dzielenie, kodowanie, raporty, USSD, połączenia, czarna lista). ✔ U1, U2, U4, U6, U10 (część 1) na Ubuntu 26.04 bez modemu. Pozostaje: U3, U5, U7–U9 i U10 (część 2) z [03, rozdz. 3.14](03-integracja-gammu-smsd.md#314-do-weryfikacji-na-ubuntu-etap-0) z modemem | U1–U10 zamknięte (**po dostarczeniu modemu** – nie blokuje etapów 1–5); zebrane prawdziwe wiersze z tabel Gammu do testów |
| **P. Projekt interfejsu** ✔ | makiety wszystkich ekranów i stanów z rozdz. 2 (wersja 1) na canvasie; statyczny prototyp HTML w `prototype/` (Pico.css, `app.css`, `app.js`, ikony, czcionki, przykładowe dane) | makiety zaakceptowane; prototyp otwiera się z dysku i z `php -S`, działa przy 360 px, w jasnym i ciemnym motywie, bez błędów CSP; każdy ekran z mapy 2.0 (wersja 1) ma swój plik |
| **1. Szkielet** ✔ | struktura katalogów, konfiguracja, połączenie z MariaDB i migracje, logowanie, układ strony (Pico.css, htmx, ikony Solar), CLI `passwd`, `check`, `setup db` | można się zalogować, `check` raportuje stan środowiska (baza, schemat Gammu, silnik tabel) |
| **2. Rdzeń SMS** ✔ (symulator) | `SmsText`, `SmsSplit`, `Phone`, `GammuDb` (zapis do `outbox` w transakcji, odczyt `inbox`/`sentitems`), proces w tle, ekran „Nowa wiadomość” (jeden odbiorca), „Odebrane”, „Wysłane” | do czasu dostarczenia modemu: testy na symulatorze Gammu; potem: SMS wysłany z panelu dochodzi (także 500 znaków i polskie znaki), odpowiedź pojawia się w panelu |
| **3. Rozmowy i raporty** ✔ (symulator) | widok rozmów, odpowiedzi, statusy z `sentitems` (raporty doręczenia, ponowienia), auto-odświeżanie, ponów/anuluj, wysyłka planowana, priorytet | pełny cykl: zaplanuj → w kolejce → wysłana → doręczona |
| **4. Książka telefoniczna i wysyłka do wielu** ✔ (symulator) | kontakty, grupy, szablony, personalizacja, dławienie, okno wysyłki, raport wysyłki, import/eksport CSV | wysyłka do grupy 3+ numerów z personalizacją i dławieniem, raport pokazuje każdy status; wysyłka poza oknem czeka do jego otwarcia |
| **5. Konfiguracja i modem** ✔ (symulator) | formularz i edytor `gammu-smsdrc`, okno potwierdzenia z różnicami, kopie, przeładowanie/restart usługi, log, ustawienia panelu, pulpit z kontrolą zdrowia; **ekran Modem, USSD na żądanie, czarna lista, połączenia** | zmiana parametru z panelu → potwierdzenie → plik zmieniony tylko w tej linii → przeładowanie → Gammu działa; `*101#` zwraca odpowiedź; zablokowany numer nie trafia do „Odebranych”; połączenie widoczne na liście |
| **6. Wdrożenie (wersja 1)** – instalacja ✔ (Ubuntu 26.04 bez modemu), czeka na test z modemem | `install.sh` (tryby `full`/`app`, Gammu, MariaDB, kreator modemu), nginx, usługi systemd, logrotate, README | instalacja na czystej maszynie wirtualnej Ubuntu jednym skryptem |
| **7. Rozszerzenia A** | przekazywanie SMS i połączeń (e-mail, webhook), powiadomienia administracyjne, HTTP API | przekazanie odebranego SMS na e-mail i webhook; wysyłka przez `curl` z tokenem |
| **8. Rozszerzenia B** | autoodpowiedzi (także na połączenie), cykliczne saldo, kilka modemów, statystyki, czyszczenie historii (wersja angielska – zrobiona wcześniej, 2026-10-04) | wg specyfikacji 2.17 |

## 7.1. Testy

**Automatyczne (prosty runner testów bez zależności – `php tests/run.php`):**
- `SmsText`: wybór kodowania, liczenie części (granice 160/161, 153×2, 70/71, 67×2, emoji,
  znaki rozszerzone GSM), transliteracja; „Mój kot” i „Zażółć” → UCS2 (nie GSM – Gammu zamieniłby je po cichu!),
  greckie litery, `€` i `¤` → GSM, `¹` → UCS2; zgodność z wersją JS (wspólny plik z przypadkami testowymi).
- `SmsSplit`: podział na granicach części, znak rozszerzony GSM i emoji nie są rozdzielane, poprawny UDH
  (`050003RRNNPP`), limit 10 części.
- `Phone`: tabela przypadków z rozdz. 3.13.
- `GammuDb`: zapis wiadomości jedno- i wieloczęściowej (wiersze `outbox` + `outbox_multipart` w jednej transakcji),
  anulowanie (wolny i zablokowany wiersz), łączenie statusów części w status wiadomości (tabela z rozdz. 3.5),
  grupowanie części odebranych (tekst sklejony przez Gammu i nie), USSD (`Class = 127`), wiadomości zewnętrzne.
  Testy na osobnej bazie testowej MariaDB (`smsgui_test`, `gammu_test`) tworzonej z `mysql.sql`.
- `GammuConf`: odczyt/zmiana/dodanie/usunięcie parametru bez naruszenia komentarzy (`#` i `;`), nazwy bez
  wielkości liter, `#` w wartości, maskowanie hasła; plik bez zmian po „wczytaj i zapisz”.
- `Blocklist`: warianty numeru, generowanie pliku.
- Hook połączeń: walidacja numeru (cyfry, `+`, `*`, `#`, pusty, śmieci).

**Ręczne (na prawdziwym modemie) – lista kontrolna:**
- SMS 160 znaków bez polskich znaków = 1 część; z jednym „ą” = 3 części; z transliteracją = 1.
- SMS 500 znaków dochodzi jako jedna sklejona wiadomość; odebrany długi SMS widoczny w całości.
- Polskie znaki i emoji w obie strony wyświetlają się poprawnie.
- Raport doręczenia zmienia status; wyłączony telefon odbiorcy na > 10 min → po włączeniu status „doręczona”
  (sprawdza `DeliveryReportDelay`).
- Nieudana wysyłka (np. błędny numer) → „ponawiana” → „błąd” z opisem.
- Wysyłka do 20 odbiorców, w tym 2 błędne numery i 1 duplikat; pojedynczy SMS wysłany w trakcie wyprzedza kolejkę.
- USSD salda; połączenie przychodzące odrzucone i zapisane; SMS z zablokowanego numeru nie pojawia się.
- Przeładowanie i restart Gammu z panelu; błędny PIN w konfiguracji → widoczny błąd w logu, przywrócenie kopii.
- Panel na telefonie (szerokość 360 px).
