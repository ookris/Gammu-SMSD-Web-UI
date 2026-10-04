# 3. Integracja z Gammu SMSD

Ten dokument opisuje, jak panel wymienia dane z demonem `gammu-smsd`.
Ustalenia zostały **zweryfikowane w kodzie źródłowym Gammu w wersji 1.42.0** (tej samej, która jest
w pakiecie Ubuntu 26.04) – przy każdym podano plik/funkcję, z której wynikają
(`smsd/core.c`, `smsd/services/sql.c`, `docs/sql/mysql.sql`, `libgammu/misc/coding/coding.c`).
Punkty oznaczone **⚠ do weryfikacji** dotyczą paczki Ubuntu lub konkretnego modemu i zostaną sprawdzone
na docelowej maszynie (lista w rozdz. 3.14).

Źródła do weryfikacji: `git clone --branch 1.42.0 https://github.com/gammu/gammu.git` (lokalnie, poza repozytorium – D16).

## 3.1. Tabele Gammu (schemat w wersji 17)

| Tabela | Kto pisze | Kto czyta | Rola |
|--------|-----------|-----------|------|
| `gammu` | instalator | Gammu, panel | jedna kolumna `Version` = 17; Gammu przy starcie odmawia pracy z inną wersją |
| `outbox` | panel | Gammu | wiadomość do wysłania (część 1); Gammu blokuje wiersz (`SendingTimeOut`), po wysyłce **usuwa** go |
| `outbox_multipart` | panel | Gammu | części 2…N wiadomości wieloczęściowej |
| `sentitems` | Gammu | panel | jeden wiersz na **każdą wysłaną część**, z wynikiem wysyłki i później raportu doręczenia |
| `inbox` | Gammu | panel | jeden wiersz na każdą odebraną część; także odpowiedzi USSD |
| `phones` | Gammu | panel | jeden wiersz na działający modem: sygnał, bateria, operator, IMEI, IMSI, liczniki |

Najważniejsze kolumny (pełny schemat: `docs/sql/mysql.sql` w źródłach Gammu):

| Tabela | Kolumny używane przez panel |
|--------|-----------------------------|
| `outbox` | `ID` (auto), `DestinationNumber`, `TextDecoded`, `Coding`, `UDH`, `Class`, `MultiPart`, `SendingDateTime`, `SendAfter`, `SendBefore`, `SendingTimeOut`, `DeliveryReport`, `SenderID`, `CreatorID`, `Priority`, `Retries`, `StatusCode` |
| `outbox_multipart` | `ID` (= `outbox.ID`), `SequencePosition` (od 2), `TextDecoded`, `Coding`, `UDH`, `Class`, `Status` |
| `sentitems` | `ID` (= `outbox.ID`), `SequencePosition`, `Status`, `StatusError`, `StatusCode`, `TPMR`, `SendingDateTime`, `DeliveryDateTime`, `SenderID`, `CreatorID`, `DestinationNumber`, `TextDecoded`, `Class` |
| `inbox` | `ID`, `SenderNumber`, `ReceivingDateTime`, `TextDecoded`, `Text`, `Coding`, `UDH`, `Class`, `RecipientID`, `Processed`, `Status`, `UpdatedInDB` |
| `phones` | `ID` (= PhoneID), `IMEI`, `IMSI`, `Signal`, `Battery`, `NetCode`, `NetName`, `Sent`, `Received`, `Client`, `Send`, `Receive`, `UpdatedInDB` |

Nowszy schemat 18 (Gammu 1.44+) dodaje m.in. grupowanie części w `inbox` i kolumnę `SendDays`.
Panel z nich nie korzysta (D19); kontrola zdrowia sprawdza, że `gammu.Version = 17`.

## 3.2. Bazy danych i uprawnienia

Jeden serwer MariaDB, dwie bazy (D18):

| Baza | Właściciel danych | Konta MariaDB i uprawnienia |
|------|-------------------|-----------------------------|
| `gammu` | Gammu | `gammu` – `SELECT, INSERT, UPDATE, DELETE` na `gammu.*` (dane w `/etc/gammu-smsdrc`) |
| `smsgui` | panel | `smsgui` – pełne prawa do `smsgui.*` oraz `SELECT, INSERT, UPDATE, DELETE` na `gammu.*`; `smsgui_hook` – tylko `INSERT` na `smsgui.calls` (rozdz. 3.11) |

Dzięki osobnym bazom demon Gammu nie ma dostępu do danych panelu (kontakty, hasło, tokeny API),
a panel jednym połączeniem i **jedną transakcją** może zapisywać w obu bazach (np. wiadomość w `smsgui.messages`
razem z wierszami w `gammu.outbox`).

**⚠ Silnik tabel (zweryfikowane, `docs/sql/mysql.sql`):** oficjalny skrypt tworzy tabele Gammu jako **MyISAM**,
który nie ma transakcji. Wtedy Gammu mógłby pobrać wiersz z `outbox`, zanim panel dopisze części do
`outbox_multipart`, i wysłać tylko pierwszą część (`SMSDSQL_FindOutboxSMS` czyta części po kolei
i kończy na pierwszej brakującej). Instalator zamienia więc silnik na InnoDB
(`ALTER TABLE … ENGINE=InnoDB` dla wszystkich tabel `gammu`). Zapytania Gammu nie zależą od silnika.
Kontrola zdrowia sprawdza silnik tabel.

**Czas:** Gammu zapisuje czas lokalny serwera bazy (`NOW()`, `CURRENT_TIMESTAMP`). Panel ustawia
w połączeniu `time_zone` zgodną z `timezone` z `config.php`, a kontrola zdrowia porównuje
`SELECT NOW()` z czasem PHP (rozbieżność > 60 s = ostrzeżenie).

**Kodowanie:** wszystkie tabele i połączenia `utf8mb4` (emoji w treści SMS).

## 3.3. Wysyłka – zapis do `outbox`

### Dlaczego panel sam dzieli wiadomość i wybiera kodowanie
Zweryfikowane w kodzie 1.42.0:

- **Gammu nie dzieli długich wiadomości** zapisanych w bazie (`SMSDSQL_FindOutboxSMS` bierze `TextDecoded`
  wiersza bez dzielenia). Zbyt długi tekst w jednym wierszu kończy się błędem wysyłki albo obciętą wiadomością.
  Podział robi albo program `gammu-smsd-inject`, albo – tak jak w panelu – program zapisujący do bazy.
- **Ciche zamiany znaków przy kodowaniu GSM:** przy `Coding = Default_No_Compression` funkcja `EncodeDefault()`
  zamienia znaki spoza alfabetu GSM według tabeli `ConvertTable` – m.in. **`ą`→`a`, `ł`→`l`, `ó`→`o`, `ż`→`z`** –
  a pozostałe na `?`. Bez ostrzeżenia. To gorzej niż w smstools3 (tam dotyczyło to tylko `ó`).

**Decyzja (D13):** panel sam wybiera kodowanie i sam dzieli wiadomość na części z nagłówkiem UDH.
Nie używa `gammu-smsd-inject` – nie trzeba uruchamiać zewnętrznego programu dla każdej wiadomości,
panel nie potrzebuje dostępu do haseł z `gammu-smsdrc`, a wszystko odbywa się w jednej transakcji.

### Wybór kodowania

| Kodowanie | Znaków w 1 SMS | Na część w SMS wieloczęściowym | Polskie znaki | `Coding` w `outbox` |
|-----------|---------------:|-------------------------------:|:-------------:|---------------------|
| GSM-7 | 160 | 153 | ✘ | `Default_No_Compression` |
| UCS-2 (Unicode) | 70 | 67 | ✔ | `Unicode_No_Compression` |

**Jedno „ą” skraca SMS ze 160 do 70 znaków.** Stąd licznik i opcja transliteracji w panelu.

1. Zamień `\r\n` na `\n`.
2. Jeśli zaznaczono „Zamień polskie znaki” – transliteracja (ą→a, ć→c, ę→e, ł→l, ń→n, ó→o, ś→s, ź/ż→z,
   wielkie analogicznie; `„ ” “` → `"`, `‘ ’` → `'`, `– —` → `-`, `…` → `...`, twarda spacja → spacja).
3. Jeśli **każdy znak** należy do alfabetu GSM (poniżej) – `Default_No_Compression`.
4. W przeciwnym razie – `Unicode_No_Compression`. Nic nie zostanie zamienione ani zgubione.

Alfabet GSM używany przez panel (identyczny w PHP i JavaScript, zgodny z tablicami
`GSM_DefaultAlphabetUnicode` i `GSM_DefaultAlphabetCharsExtension` z Gammu):
- podstawowy (1 jednostka): `@ £ $ ¥ è é ù ì ò Ç \n Ø ø \r Å å Δ _ Φ Γ Λ Ω Π Ψ Σ Θ Ξ Æ æ ß É`,
  spacja, `! " # ¤ % & ' ( ) * + , - . /`, cyfry, `: ; < = > ? ¡`, `A–Z`, `Ä Ö Ñ Ü §`, `¿`, `a–z`, `ä ö ñ ü à`,
- rozszerzony (2 jednostki): `^ { } \ [ ] ~ | €`,
- **bez** `¹` – Gammu ma go w tablicy na pozycji `0x1B`, która w GSM jest znakiem ucieczki (wysłanie zepsułoby tekst),
- bez znaku nowej strony.

`¤` jest w alfabecie Gammu (w smstools3 nie był).

### Podział na części
- Jedna część: do 160 jednostek GSM / 70 jednostek UTF-16 – bez UDH.
- Więcej: części po 153 jednostki GSM / 67 jednostek UTF-16. Znak rozszerzony GSM (2 jednostki) i para
  zastępcza UTF-16 (emoji) **nie są dzielone** między części.
- UDH każdej części (8-bitowy numer referencyjny): `050003` + `RR` (numer wiadomości 00–FF, licznik w `settings`)
  + `NN` (liczba części) + `PP` (numer części), zapis szesnastkowy, np. `0500034F0301`.
- Limit: 10 części (ok. 1530 znaków GSM / 670 Unicode) – ochrona przed przypadkowym wklejeniem długiego tekstu.

### Zapis (jedna transakcja)
1. Wiersz w `smsgui.messages` (status `queued` albo `scheduled`).
2. Wiersz w `gammu.outbox` – część 1:

   | Kolumna | Wartość |
   |---------|---------|
   | `DestinationNumber` | numer międzynarodowy **z `+`** (`+48601234567`); numer skrócony bez zmian (`8080`) |
   | `TextDecoded` | treść części 1 w UTF-8 (`Text` puste – Gammu użyje `TextDecoded`) |
   | `Coding` | `Default_No_Compression` / `Unicode_No_Compression` |
   | `UDH` | nagłówek części 1 lub puste |
   | `MultiPart` | `true`, jeśli części > 1 |
   | `Class` | `0` – Flash SMS (klasa 0 wg GSM 03.38, tak samo ustawia backend FILES Gammu), `-1` – zwykły |
   | `DeliveryReport` | `yes` / `no` – zawsze jawnie (wartość `default` zależałaby od `DeliveryReport` w konfiguracji) |
   | `SendingDateTime` | teraz albo czas wysyłki planowanej / dławionej |
   | `SendAfter`, `SendBefore` | okno godzinowe wysyłki (rozdz. 3.6); domyślnie `00:00:00`–`23:59:59` |
   | `Priority` | rozdz. 3.6 |
   | `SenderID` | PhoneID wybranego modemu lub `NULL` (dowolny) |
   | `CreatorID` | `smsgui` (z konfiguracji `creator_id`) |
3. Wiersze w `gammu.outbox_multipart` dla części 2…N (`ID` z kroku 2, `SequencePosition` = 2…N).
4. `messages.gammu_id` = `outbox.ID`, `messages.parts` = N.
5. `COMMIT`. Gammu widzi wiadomość dopiero w całości.

Gammu pobiera wiadomości, które spełniają: `SendingDateTime < NOW()`, `SendingTimeOut < NOW()`,
`SendAfter <= CURTIME() <= SendBefore`, `SenderID` pusty lub równy jego PhoneID; kolejność:
`Priority DESC, InsertIntoDB ASC` (✔ `sql.c`, zapytanie `find_outbox_sms_id`).

### Anulowanie
```sql
DELETE FROM gammu.outbox
 WHERE ID = ? AND (SendingTimeOut < NOW() OR SendingTimeOut IS NULL)
```
(+ usunięcie wierszy z `outbox_multipart`, w transakcji). Usunięto wiersz → `cancelled`.
Nie usunięto → Gammu właśnie wysyła (`SendingTimeOut` w przyszłości) → komunikat „wiadomość jest już wysyłana”.
Anulować można też wiadomość ponawianą (rozdz. 3.5); jeśli któraś część miała już status `SendingOK`
w `outbox_multipart.Status`, panel zapisuje uwagę „część wiadomości mogła zostać wysłana”.

### Ponowienie
Dla `failed`/`undelivered` panel tworzy **nowy** wiersz w `outbox` (te same części, nowy numer referencyjny UDH)
i zmienia `messages.gammu_id`. Wiersze `sentitems` poprzedniej próby zostają, ale nie są już powiązane.

## 3.4. Odbiór – tabela `inbox`

✔ Zweryfikowane (`SMSDSQL_SaveInboxSMS`, `SMSD_ReadDeleteSMS`, `SMSD_CheckMultipart`):

- **Każda część to osobny wiersz.** Gammu czeka na wszystkie części wiadomości wieloczęściowej
  (najdłużej `MultipartTimeout`, domyślnie 600 s), po czym zapisuje je **kolejno, jedna po drugiej**.
- Od 1.40 **pełny, sklejony tekst** trafia do `TextDecoded` pierwszego zapisanego wiersza, a kolejne części
  mają `TextDecoded` puste. Gdy sklejenie się nie uda (np. brak części po upływie `MultipartTimeout`),
  każda część ma własny tekst.
- `SenderNumber`: numery zaczynające się od `00` są zapisywane z `+` (`%R` w `sql.c`); w praktyce numery
  międzynarodowe przychodzą jako `+48…`. Może też być nazwa alfanumeryczna (nadawcy firmowi).
- `Class = 0` – odebrany Flash SMS; `Class = 127` – odpowiedź USSD (rozdz. 3.10).
- `Processed` – Gammu tylko ustawia `false` przy zapisie; kolumna jest do dyspozycji programu czytającego.
- `RecipientID` – PhoneID modemu, który odebrał wiadomość.
- `ReceivingDateTime` – znacznik czasu centrum SMS (czas lokalny).

Import w panelu:

1. Pobierz wiersze `Processed = 'false'` w kolejności `ID`, ale tylko o `ID` nie większym niż ostatni wiersz
   zmieniony ponad 2 s temu (`UpdatedInDB < NOW() - INTERVAL 2 SECOND`) – Gammu zapisuje części pojedynczo,
   więc najświeższa grupa może być jeszcze niepełna.
2. Grupowanie części: wiersze z UDH łączenia (IEI `00` – 8-bitowy numer, IEI `08` – 16-bitowy)
   o tym samym `SenderNumber`, `RecipientID`, numerze referencyjnym i liczbie części, o kolejnych `ID`.
3. Treść = złączenie `TextDecoded` wszystkich części w kolejności z UDH. Działa w obu przypadkach:
   gdy Gammu skleił tekst (pozostałe części puste) i gdy nie skleił (każda ma swój fragment).
4. Mniej części niż w UDH → wiadomość z oznaczeniem „niekompletna”.
5. `Coding = 8bit` (dane binarne, `TextDecoded` puste) → zapis jako `[dane binarne] <hex z Text>`.
6. `Class = 127` → odpowiedź USSD (nie trafia do „Odebranych”).
7. Numer nadawcy na blokowanej liście panelu (rozdz. 3.12) → zapis, ale bez automatyzacji.
8. Po imporcie: tryb `keep` – `Processed = 'true'`; tryb `delete` – usunięcie wierszy z `inbox` (D8).

## 3.5. Wynik wysyłki i raporty doręczenia – tabela `sentitems`

✔ Zweryfikowane (`SMSD_SendSMS` w `core.c`, `SMSDSQL_AddSentSMSInfo`, `SMSDSQL_SaveInboxSMS`):

- Po wysłaniu **każdej części** Gammu dopisuje wiersz do `sentitems` (`ID` = `outbox.ID`, `SequencePosition`),
  a po wysłaniu całości usuwa wiersz z `outbox`.
- `Status` po wysyłce: `SendingOK` (zażądano raportu), `SendingOKNoReport` (bez raportu).
- Nieudana próba: Gammu **zostawia wiadomość w `outbox`**, zwiększa `Retries`, zapisuje `StatusCode`
  (kod błędu modemu, np. `+CMS ERROR`) i przesuwa `SendingTimeOut` o `RetryTimeout` (domyślnie 600 s).
  Po przekroczeniu `MaxRetries` (domyślnie 1, czyli 2 próby) dopisuje części ze statusem `SendingError`
  i usuwa wiersz z `outbox`. Status `Error` – wiadomości nie dało się odczytać z bazy (uszkodzony wiersz).
- **Raporty doręczenia dopasowuje sam Gammu**, nie panel: szuka w `sentitems` wiersza z tym samym `TPMR`
  (numer referencyjny z modemu, 0–255), numerem odbiorcy, `SenderID` (PhoneID) i pustym `DeliveryDateTime`,
  o statusie `SendingOK` lub `DeliveryPending`. Ustawia `Status` = `DeliveryOK` / `DeliveryFailed` /
  `DeliveryPending` / `DeliveryUnknown`, `StatusError` (kod TP-Status 0–127) i – przy doręczeniu – `DeliveryDateTime`.
- **⚠ Pułapka – `DeliveryReportDelay`:** raport jest dopasowywany tylko wtedy, gdy różnica czasu między wysłaniem
  a raportem jest mniejsza niż `DeliveryReportDelay` – **domyślnie 600 s**. Telefon odbiorcy wyłączony na
  dłużej niż 10 minut = raport nigdy nie zostanie dopasowany, a wiadomość na zawsze zostanie „wysłana”.
  Panel zaleca `DeliveryReportDelay = 172800` (2 dni); kontrola zdrowia ostrzega przy wartości < 3600.
- Raport dotyczy pojedynczej części – w SMS wieloczęściowym każda część ma własny raport.

Panel odczytuje wynik po `messages.gammu_id` i łączy statusy części:

| Części w `sentitems` / stan `outbox` | Status w panelu |
|--------------------------------------|-----------------|
| wiersz w `outbox`, `SendingDateTime` w przyszłości lub poza oknem `SendAfter`–`SendBefore` | `scheduled` |
| wiersz w `outbox`, `Retries = 0` | `queued` |
| wiersz w `outbox`, `Retries > 0` | `queued` + uwaga „ponawiana (próba X z Y), kolejna o GG:MM” + opis `StatusCode` |
| brak w `outbox`, wszystkie części `SendingOK`/`SendingOKNoReport`/`DeliveryPending`/`DeliveryUnknown` | `sent` (przy `Pending`/`Unknown` – opis w `error`) |
| wszystkie części `DeliveryOK` | `delivered` + `delivered_at` = najpóźniejsze `DeliveryDateTime` |
| którakolwiek część `DeliveryFailed` | `undelivered` + opis kodu `StatusError` |
| którakolwiek część `SendingError` / `Error` | `failed` + opis `StatusCode` |
| brak w `outbox` i brak w `sentitems` (ktoś usunął wiersz) | `failed` – „wiadomość usunięta z kolejki Gammu” |

Kody `StatusError` (TP-Status): 0–31 doręczono, 32–63 błąd tymczasowy (sieć ponawia), 64–127 błąd trwały
lub centrum SMS przestało próbować. Kody `StatusCode`: panel ma tabelę najczęstszych kodów `+CMS ERROR`
(np. 21 – odrzucona, 38 – awaria sieci, 42 – przeciążenie, 330 – nieznany numer centrum SMS,
500 – nieznany błąd); nieznany kod pokazywany jako liczba z podpowiedzią „szczegóły w logu Gammu”.

Raporty nie są widoczne w skrzynce odbiorczej – Gammu nie zapisuje ich do `inbox`.

## 3.6. Statusy, wysyłka planowana, okna wysyłki i priorytet

```
                ┌─────────────┐ SendingDateTime  ┌──────────┐ wszystkie części ┌─────────┐ raport OK ┌───────────┐
  (planowana) ─►│ scheduled   │─────────────────►│ queued   │─────────────────►│  sent   │──────────►│ delivered │
                └─────┬───────┘  i okno wysyłki  └────┬─────┘   w sentitems    └────┬────┘           └───────────┘
                      │ anuluj                        │ SendingError              │ DeliveryFailed
                      ▼                               ▼                           ▼
                ┌─────────────┐                  ┌──────────┐               ┌─────────────┐
                │ cancelled   │                  │ failed   │               │ undelivered │
                └─────────────┘                  └────┬─────┘               └──────┬──────┘
                                                      └───── ponów ──► queued ◄────┘
```

| Status | Etykieta | Skąd wiadomo |
|--------|----------|--------------|
| `scheduled` | Zaplanowana | wiersz w `outbox` z `SendingDateTime` w przyszłości lub poza oknem wysyłki |
| `queued` | W kolejce | wiersz w `outbox` (także ponawiana – z uwagą) |
| `sent` | Wysłana | wszystkie części w `sentitems`, wysłane |
| `failed` | Błąd | `SendingError` / `Error` |
| `delivered` | Doręczona | wszystkie części `DeliveryOK` |
| `undelivered` | Niedoręczona | którakolwiek część `DeliveryFailed` |
| `cancelled` | Anulowana | panel usunął wiersz z `outbox` |

W porównaniu ze smstools3 znika stan przejściowy `dispatching`: wiadomość planowana od razu trafia do `outbox`
z przyszłym `SendingDateTime` – **wysyłkę planowaną obsługuje sam Gammu**, a panel nie musi niczego „przekazywać”
o właściwej godzinie.

**Wiadomość „zawieszona”:** `queued` z `SendingDateTime` starszym niż 15 minut, bez ponowień → ostrzeżenie na pulpicie
(Gammu nie działa, modem nie wysyła albo `SenderID` wskazuje modem, którego nie ma).

### Okno wysyłki (zamiast „ciszy nocnej” po stronie panelu)
✔ `SendAfter`/`SendBefore` (typ `TIME`): Gammu wysyła wiadomość tylko, gdy `SendAfter <= CURTIME() <= SendBefore`.
Okno **nie może przechodzić przez północ** – „nie wysyłaj między 21:00 a 8:00” zapisuje się jako
`SendAfter = 08:00:00`, `SendBefore = 21:00:00`. Wiadomość zapisana wieczorem czeka w `outbox` do rana
bez udziału panelu.

### Priorytet
✔ `Priority` (wyższa liczba = wcześniej). Wartości używane przez panel:

| Priorytet | Wiadomości |
|----------:|------------|
| 20 | USSD, powiadomienia administracyjne |
| 10 | pojedyncze SMS z panelu i API, odpowiedzi w rozmowie, autoodpowiedzi |
| 0 | wysyłka do wielu odbiorców |

Dzięki temu odpowiedź w rozmowie nie czeka za wysyłką do 300 osób. Na ekranie nowej wiadomości jest opcja
„Wyślij priorytetowo” (10 także dla wysyłki do wielu).

### Dławienie
Bez zmian: przy więcej niż jednym odbiorcy kolejne wiadomości dostają `SendingDateTime` rozłożone wg N SMS/min.

## 3.7. Synchronizacja – proces w tle

Proces `bin/smsgui worker` (usługa `smsgui-worker.service`, użytkownik `www-data`) w pętli co `worker_interval` (3 s)
wykonuje kroki poniżej. Te same kroki wykonuje `bin/smsgui sync` (jednorazowo) i panel przy odświeżeniu strony
(nie częściej niż co 10 s). Blokada: `SELECT GET_LOCK('smsgui-sync', 0)` – jeśli zajęta, wywołanie kończy się od razu.

1. **Wynik wysyłki:** wiadomości panelu w stanach `scheduled`/`queued`/`sent` (te ostatnie – nie starsze niż
   7 dni, bo dłużej raport nie przyjdzie) → odczyt `outbox` i `sentitems` po `gammu_id` → statusy wg tabeli z rozdz. 3.5.
   Zapytania tylko po kluczu `ID`, więc koszt nie rośnie z historią.
2. **Wiadomości „zewnętrzne”** (D9 – `gammu-smsd-inject`, inne programy): wiersze `sentitems` z `CreatorID`
   różnym od `creator_id`, `Class <> 127`, z ostatnich 7 dni, których `ID` nie ma w `smsgui.messages` i których nie ma już
   w `outbox` (wysyłka zakończona) → nowy rekord `source = external`; treść = złączenie `TextDecoded` części.
   Zmiany raportów dla nich – jak w kroku 1.
3. **`inbox`:** import wg rozdz. 3.4; odpowiedzi USSD → rozdz. 3.10; następnie automatyzacje
   (przekazywanie, autoodpowiedzi – rozszerzenia).
4. **`phones`:** stan modemów → `smsgui.modem_status` (rozdz. 3.9).
5. **Połączenia:** nowe wpisy w `smsgui.calls` → automatyzacje (rozdz. 3.11).
6. **Zadania okresowe:** cykliczne sprawdzanie salda USSD, powiadomienia, czyszczenie historii (raz na dobę).
7. Zapis czasu i wyniku ostatniej synchronizacji (`settings`) – kontrola zdrowia sprawdza, czy proces w tle działa.

Każda wiadomość w osobnej transakcji – uszkodzony wiersz nie blokuje pozostałych; błąd trafia do logu aplikacji
i na pulpit. Wiersz `inbox` z błędną treścią jest oznaczany jako przetworzony (`Processed = 'true'`) z wpisem w logu;
błąd chwilowy (baza, połączenie) – ponowna próba w następnym przebiegu.

Proces w tle kończy się sam po 1 godzinie albo po zmianie plików aplikacji (systemd uruchamia go ponownie) –
zapobiega to wyciekom pamięci i powoduje, że po aktualizacji działa nowy kod.

## 3.8. Edycja `/etc/gammu-smsdrc`

✔ Format (`libgammu/misc/cfg.c`): plik INI, sekcje `[nazwa]`, linie `klucz = wartość`, nazwy kluczy
**bez rozróżniania wielkości liter**. **Komentarzem jest tylko cała linia zaczynająca się (po spacjach) od `#` lub `;`** –
`#` w środku wartości jest jej częścią. Sekcje używane przez panel:

| Sekcja | Zawartość |
|--------|-----------|
| `[gammu]` | połączenie z modemem: `device`, `connection` (np. `at`, `at115200`) |
| `[smsd]` | demon: `service = sql`, `driver = native_mysql`, `host`, `user`, `password`, `database`, `phoneid`, `pin`, `logfile`, `debuglevel` i pozostałe opcje |
| `[include_numbers]`, `[exclude_numbers]` | listy numerów wpisane w pliku (panel używa pliku z listą – rozdz. 3.12) |

Parser zachowuje plik linia po linii (jak dla smstools). Zmiana parametru:
- istnieje → podmiana wartości w tej samej linii (porównanie nazw bez wielkości liter),
- nie istnieje → dopisanie za ostatnim parametrem sekcji,
- brak sekcji → dopisanie nowej sekcji na końcu pliku,
- pusta wartość → usunięcie linii (Gammu użyje wartości domyślnej).

**Parametry zarządzane przez panel** (`service`, `driver`, `host`, `user`, `password`, `database`, `sql`,
`runonincomingcall`, `excludenumbersfile`, `includenumbersfile`, `hangupcalls`) nie występują w formularzu –
ustawia je instalator albo odpowiednia funkcja panelu (połączenia, czarna lista). Edytor pliku je pokazuje,
ale walidacja ostrzega przy ich zmianie. **Hasło do bazy jest maskowane** w edytorze, w podglądzie różnic
i w kopiach zapasowych pokazywanych w przeglądarce; niezmienione maskowane pole zachowuje oryginalną wartość.

Zapis: kopia zapasowa → zapis „w miejscu” z blokadą (`LOCK_EX`) – `www-data` nie może tworzyć plików w `/etc`.
Potem panel proponuje **przeładowanie** (`systemctl reload gammu-smsd` → `SIGHUP`; ✔ `smsd/main.c`,
`smsd_reconfigure()` – Gammu kończy bieżącą pętlę, czyta konfigurację od nowa i ponownie łączy się z modemem)
albo pełny restart usługi.

## 3.9. Stan modemu – tabela `phones`

✔ Gammu zapisuje wiersz przy starcie (`insert_phone`, klucz `IMEI`) i co `StatusFrequency` sekund (domyślnie 60)
oraz przed każdą wysyłką aktualizuje `Signal` (%), `Battery` (%), `NetCode`, `NetName` i `TimeOut`
(`refresh_phone_status`); liczniki `Sent`/`Received` rosną przy każdej części. `ID` = PhoneID, `Client` = wersja Gammu.

Panel pokazuje: sygnał (pasek + %), operatora (`NetName`, `NetCode`), IMEI, IMSI, liczniki od startu,
wersję Gammu i czas ostatniej aktualizacji. Modem uznaje się za **niedostępny**, gdy `UpdatedInDB` jest starszy niż
`StatusFrequency` + 60 s (kolumna `TimeOut` zmienia się przy każdej aktualizacji, więc `UpdatedInDB` też).
Po restarcie Gammu usuwa i ponownie wstawia swój wiersz.

Nie trzeba już niczego dopisywać do konfiguracji (w smstools3 potrzebne było `regular_run_cmd`).
Saldo – przez USSD (rozdz. 3.10).

## 3.10. USSD

✔ Obsługiwane przez Gammu SMSD od 1.38.5 (`SMSD_SendSMS`, `SMSD_IncomingUSSDCallback`):

- **Wysłanie:** wiersz w `outbox` z `Class = 127`, `DestinationNumber` = kod (np. `*101#`), `TextDecoded` = kod,
  `DeliveryReport = no`, `Priority = 20`, `CreatorID = smsgui`. Gammu wywołuje `GSM_DialService()`
  (gdy modem tego nie obsługuje – próbuje połączenia głosowego). Wynik w `sentitems` (`Class = 127`).
- **Odpowiedź:** Gammu zapisuje ją jak odebraną wiadomość – wiersz w `inbox` z `Class = 127`,
  `Coding = Unicode_No_Compression`, treścią w `TextDecoded`, pustym `SenderNumber` (⚠ do potwierdzenia z modemem)
  i `Status` = stan sesji: 1 nieznany, 2 brak dalszej akcji, 3 **oczekiwana odpowiedź** (menu), 4 zakończona,
  5 obsłużona przez innego klienta, 6 nieobsługiwana, 7 przekroczony czas.

Panel:
- pozwala na **jedno oczekujące żądanie USSD na modem** – odpowiedź to pierwszy wiersz `inbox` z `Class = 127`
  od tego modemu (`RecipientID`) zapisany po wysłaniu żądania (`sentitems.SendingDateTime`),
- brak odpowiedzi w 60 s → „brak odpowiedzi” (żądanie zamykane),
- przy `Status = 3` (menu operatora) pokazuje pole „Odpowiedz” – odpowiedź to kolejne żądanie USSD z wybraną cyfrą
  (⚠ działanie zależy od modemu i operatora),
- USSD nie są pokazywane jako wiadomości „zewnętrzne” ani w „Odebranych”.

Zastosowania: ekran **USSD** (kod na żądanie + historia) i cykliczne sprawdzanie salda (rozszerzenie 2.17.4).

## 3.11. Połączenia przychodzące

✔ `HangupCalls` i `RunOnIncomingCall` (`SMSD_IncomingCallCallback`, Gammu ≥ 1.38.5):

- Obsługa połączeń włącza się **tylko przy `HangupCalls = yes`** – Gammu odrzuca każde połączenie przychodzące
  i dopiero wtedy uruchamia `RunOnIncomingCall` z numerem dzwoniącego jako argumentem.
  Nie da się zapisywać połączeń bez ich odrzucania.
- Kolejne sygnały `RING` w ciągu 5 s są pomijane (jedno połączenie = jedno wywołanie).
- Polecenie jest uruchamiane przez `sh -c "<polecenie> <numer>"` jako użytkownik demona.
  **⚠ Bezpieczeństwo:** w 1.42.0 argument nie jest cytowany (poprawione w 1.43.3 – „Prevent command injection through
  SMSD RunOn hook arguments”). Numer pochodzi z sieci GSM (cyfry, `+`, `*`, `#`), więc ryzyko jest małe,
  ale `*` może zostać rozwinięty przez powłokę. Dlatego polecenie to wyłącznie
  `/usr/bin/php /opt/smsgui/bin/smsgui hook call --phone=<PhoneID>`, a hook:
  - akceptuje tylko numer pasujący do `^\+?[0-9*#]{1,20}$` (pusty = numer zastrzeżony), inne wartości zapisuje jako „nieprawidłowy numer”,
  - łączy się z bazą jako `smsgui_hook` (tylko `INSERT` do `smsgui.calls`; dane w `/etc/smsgui/hook.cnf`,
    czytelnym tylko dla użytkownika demona),
  - nie robi nic więcej – automatyzacje (autoodpowiedź SMS, przekazanie) wykonuje proces w tle.
- Panel włącza funkcję jednym przełącznikiem (ustawia `HangupCalls` i `RunOnIncomingCall` przez okno potwierdzenia
  zapisu konfiguracji i przeładowuje Gammu).

## 3.12. Czarna lista numerów

✔ `ExcludeNumbersFile` / `IncludeNumbersFile` (`SMSD_LoadNumbersFile`, `SMSD_CheckRemoteNumber`):

- Plik: jeden numer w linii; porównanie **dokładne** z numerem nadawcy w postaci, w jakiej podał go modem.
- SMS od numeru z listy jest **usuwany z modemu bez zapisu** w `inbox` (Gammu loguje dopasowanie).
- Gdy ustawiono `IncludeNumbers…` (lista dozwolonych), lista wykluczonych jest ignorowana – panel jej nie ustawia,
  a kontrola zdrowia ostrzega, jeśli ktoś ustawił ją ręcznie.
- Lista jest czytana przy starcie i przy przeładowaniu (`SIGHUP`) – po zmianie panel przeładowuje Gammu.

Panel:
- ekran **Zablokowane numery** (dodaj z numerem i notatką, usuń; także „Zablokuj” w rozmowie i na kontakcie),
- lista w tabeli `smsgui.blocked_numbers`; plik `blocklist_file` jest generowany z tabeli przy każdej zmianie
  (zapis atomowy: plik tymczasowy w `/var/lib/smsgui` + `rename()`), z kopią poprzedniej wersji,
- ponieważ format numeru z modemu nie jest pewny (⚠ U9), dla każdego numeru zapisuje warianty:
  `+48601234567`, `48601234567`, `0048601234567`, `601234567`; nazwy alfanumeryczne – bez zmian,
- połączenia z zablokowanych numerów nadal są zapisywane (Gammu nie filtruje połączeń), ale bez automatyzacji;
  przy wysyłce do zablokowanego numeru panel pokazuje ostrzeżenie.

## 3.13. Normalizacja numerów

| Wpisano / odebrano | W bazie panelu | W `outbox.DestinationNumber` |
|--------------------|----------------|------------------------------|
| `601 234 567` | `48601234567` | `+48601234567` |
| `+48 601-234-567` | `48601234567` | `+48601234567` |
| `0048601234567` | `48601234567` | `+48601234567` |
| `+44 7911 123456` | `447911123456` | `+447911123456` |
| `8080` | `8080` (numer skrócony) | `8080` |
| `abc`, `12` | błąd walidacji | — |
| odebrano `+48601234567` | `48601234567` | — |
| odebrano `ORLEN` | `ORLEN` (alfanumeryczny, bez odpowiedzi) | — |

Reguła: usuń spacje, `-`, `.`, `(`, `)`, `/`; `+` lub `00` na początku → usuń; dokładnie
`national_number_length` cyfr → dodaj `default_country_code`; wynik musi mieć 3–15 cyfr.
Numery krótsze niż `national_number_length` traktowane są jako skrócone i zapisywane bez zmian.
Do Gammu numer międzynarodowy trafia z `+` (tak Gammu zapisuje numery w `inbox` i `sentitems`, więc rozmowy się łączą).
Wyświetlanie: `+48 601 234 567`.

## 3.14. Do weryfikacji na Ubuntu (etap 0)

| # | Co | Jak sprawdzimy |
|---|----|----------------|
| U1 | Nazwa i plik usługi systemd (`gammu-smsd.service`?), czy ma `ExecReload` (SIGHUP), jako kto działa demon | `systemctl cat gammu-smsd` |
| U2 | Domyślny `/etc/gammu-smsdrc` z paczki (backend, `logfile`, ścieżki) | odczyt pliku |
| U3 | Czy paczka ma wkompilowany sterownik `native_mysql` | uruchomienie z `driver = native_mysql`, log startu |
| U4 | Położenie skryptu `mysql.sql` w paczce | `dpkg -L gammu-smsd \| grep -i sql` |
| U5 | Uprawnienia pliku logu, reguła logrotate (czy Gammu po rotacji pisze dalej – `copytruncate`) | `ls -l`, test rotacji |
| U6 | ✔ Wersja PHP – użytkownik potwierdził PHP 8.5 na serwerze produkcyjnym (2026-10-03) | `php -v` przy instalacji |
| U7 | Czy przeładowanie (`SIGHUP`) odczytuje ponownie `ExcludeNumbersFile` | test z numerem na liście |
| U8 | USSD i odrzucanie połączeń na docelowym modemie; `SenderNumber` odpowiedzi USSD | test `*101#`, połączenie testowe |
| U9 | Format numeru nadawcy podawany przez modem (z `+`, krajowy?) – istotne dla czarnej listy | odebrany SMS + log Gammu |
| U10 | Strefa czasowa MariaDB = strefa systemu; `DeliveryReportDelay` – czas raportu przy wyłączonym telefonie | `SELECT NOW()`, test raportu |
