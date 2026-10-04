# 7. Plan realizacji i testów

Każdy etap kończy się działającą, możliwą do przetestowania wersją.
Szczegółowe zadania i pliki dla każdego etapu: [09-plan-implementacji.md](09-plan-implementacji.md).

| Etap | Zakres | Kryterium odbioru |
|------|--------|-------------------|
| **0. Weryfikacja środowiska** | ✔ zachowanie smsd sprawdzone w kodzie źródłowym 3.1.21 (pliki z kropką, nagłówki, kodowania, raporty). Pozostaje: punkty U1–U6 z [03, rozdz. 3.10](03-integracja-smstools3.md#310-pozostałe-do-weryfikacji-na-ubuntu-etap-0) na docelowym Ubuntu | U1–U6 zamknięte (**po dostarczeniu modemu** – nie blokuje etapów 1–5); zebrane prawdziwe pliki z modemu do testów |
| **1. Szkielet** | struktura katalogów, konfiguracja, baza i migracje, logowanie, układ strony wg [makiet](https://claude.ai/artifact/1GcJxMSqGRWsxjPYG9mXG8) (Pico.css, htmx, ikony Solar), CLI `passwd`, `check` | można się zalogować, `check` raportuje stan środowiska |
| **2. Rdzeń SMS** | `SmsText`, `Phone`, `Spool` (zapis + parsowanie + synchronizacja), timer, ekran „Nowa wiadomość” (jeden odbiorca), „Odebrane”, „Wysłane” | do czasu dostarczenia modemu: testy na symulatorze smsd (skrypt przenoszący pliki z `outgoing` do `sent`/`failed` i tworzący raporty); potem: SMS wysłany z panelu dochodzi, odpowiedź pojawia się w panelu |
| **3. Rozmowy i raporty** | widok rozmów, odpowiedzi, raporty doręczenia, auto-odświeżanie, ponów/anuluj, wysyłka planowana | pełny cykl: zaplanuj → w kolejce → wysłana → doręczona |
| **4. Książka telefoniczna i wysyłka do wielu** | kontakty, grupy, szablony, personalizacja, dławienie, cisza nocna, raport wysyłki, import/eksport CSV | wysyłka do grupy 3+ numerów z personalizacją i dławieniem, raport pokazuje każdy status |
| **5. Konfiguracja** | formularz, edytor, okno potwierdzenia z różnicami, kopie, restart usługi, log, ustawienia panelu, pulpit z kontrolą zdrowia | zmiana parametru z panelu → potwierdzenie → plik zmieniony tylko w tej linii → restart → smsd działa |
| **6. Wdrożenie (wersja 1)** | `install.sh`, konfiguracja nginx, unity systemd, README | instalacja na czystej maszynie wirtualnej Ubuntu jednym skryptem |
| **7. Rozszerzenia A** | przekazywanie SMS (e-mail, webhook), powiadomienia administracyjne, HTTP API | przekazanie odebranego SMS na e-mail i webhook; wysyłka przez `curl` z tokenem |
| **8. Rozszerzenia B** | autoodpowiedzi, stan modemu/USSD, wybór modemu, statystyki, czyszczenie historii, wersja angielska | wg specyfikacji 2.14 |

## 7.1. Testy

**Automatyczne (prosty runner testów bez zależności – `php tests/run.php`):**
- `SmsText`: wybór kodowania, liczenie części (granice 160/161, 153×2, 70/71, 67×2, emoji,
  znaki rozszerzone GSM), transliteracja; „Mój kot” → UCS2 (nie GSM!), greckie litery i `€` → GSM, `¤` → UCS2; zgodność z wersją JS (wspólny plik z przypadkami testowymi
  używany przez oba testy).
- `Phone`: tabela przypadków z rozdz. 3.8.
- `Spool::parse`: pliki przykładowe ze źródeł smstools3 (`examples/*.sms`) oraz prawdziwe pliki z modemu (ISO, UCS2, UTF-8, binarny, raport, failed, `Original_filename`, `messageids = 3`).
- `SmsdConf`: odczyt/zmiana/dodanie/usunięcie parametru bez naruszenia komentarzy; plik bez zmian
  po „wczytaj i zapisz”.
- Synchronizacja na katalogach tymczasowych: symulacja smsd (przeniesienie pliku do `sent` z dopisanymi
  nagłówkami, raport w `incoming`), tryby `keep`/`delete`, uszkodzony plik.

**Ręczne (na prawdziwym modemie) – lista kontrolna:**
- SMS 160 znaków bez polskich znaków = 1 część; z jednym „ą” = 3 części; z transliteracją = 1.
- SMS 500 znaków dochodzi jako jedna sklejona wiadomość.
- Polskie znaki i emoji w obie strony wyświetlają się poprawnie.
- Raport doręczenia zmienia status; wyłączony telefon odbiorcy → status pozostaje „wysłana”, potem „doręczona”.
- Wysyłka do 20 odbiorców, w tym 2 błędne numery i 1 duplikat.
- Restart smstools z panelu; błędny PIN w konfiguracji → widoczny błąd w logu, przywrócenie kopii.
- Panel na telefonie (szerokość 360 px).
