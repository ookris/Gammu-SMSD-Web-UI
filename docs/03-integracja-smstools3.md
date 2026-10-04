# 3. Integracja ze smstools3

Ten dokument opisuje, jak panel wymienia dane z demonem `smsd`.
Ustalenia zostały **zweryfikowane w kodzie źródłowym smstools3 w wersji 3.1.21** (tej samej, która jest
w pakiecie Ubuntu) – przy każdym podano plik/funkcję, z której wynikają.
Punkty oznaczone **⚠ do weryfikacji** dotyczą wyłącznie paczki Ubuntu (użytkownik, uprawnienia,
nazwa usługi, opcje kompilacji) i zostaną sprawdzone na docelowej maszynie.

## 3.1. Katalogi kolejek

| Katalog | Kto pisze | Kto czyta | Rola | Domyślnie w smsd |
|---------|-----------|-----------|------|------------------|
| `outgoing` | panel | smsd | nowe wiadomości do wysłania | `/var/spool/sms/outgoing` |
| `checked` | smsd | smsd | wiadomości sprawdzone, czekające na modem | `/var/spool/sms/checked` |
| `sent` | smsd | panel | wysłane poprawnie | **brak** |
| `failed` | smsd | panel | nieudane | **brak** |
| `incoming` | smsd | panel | odebrane SMS i raporty doręczenia | `/var/spool/sms/incoming` |

Panel odczytuje ścieżki z globalnej sekcji `smsd.conf`; można je nadpisać w `config.php`.

**Ważne (zweryfikowane, `smsd_cfg.c`: `d_sent[0]=0; d_failed[0]=0;`):** domyślnie smsd **nie ma**
katalogów `sent` i `failed` – po wysyłce plik jest po prostu usuwany i panel nie pozna wyniku.
Wymagane ustawienia w `smsd.conf` (sprawdzane przez kontrolę zdrowia i `bin/smsgui check`):

```ini
sent = /var/spool/sms/sent
failed = /var/spool/sms/failed
```

## 3.2. Wysyłka – plik w `outgoing`

### Nazwa i zapis atomowy
Nazwa: `gui_<id>_<6 znaków hex>`, np. `gui_1523_a3f9c1` (`<id>` – identyfikator wiadomości w bazie).

1. Zapis do pliku `.gui_1523_a3f9c1` w katalogu `outgoing` (z kropką na początku).
2. `chmod 0660` (grupa `smsd` – smsd wymaga, by plik był dla niego **zapisywalny**, inaczej próbuje
   go skopiować i loguje błąd uprawnień; `extras.c`, `getfile()`).
3. `rename()` na nazwę docelową – operacja atomowa.

✔ Zweryfikowane (`extras.c`, `getfile()`, od 3.1.17): smsd **pomija pliki zaczynające się od kropki**,
pliki `*.LOCK`, `LOCKED*` oraz pliki mniejsze niż 8 bajtów. Plan B nie jest potrzebny.

### Format pliku
```
To: 48601234567
Report: yes
Flash: yes
Alphabet: UTF

<treść>
```

| Nagłówek | Kiedy | Uwagi |
|----------|-------|-------|
| `To:` | zawsze | numer międzynarodowy **bez `+`**; numer skrócony poprzedzony `s` (np. `s8080`) |
| `Report: yes` / `no` | zawsze, wg opcji | bez nagłówka smsd użyłby ustawienia modemu |
| `Flash: yes` | gdy zaznaczono Flash SMS | |
| `Alphabet: UTF` lub `Alphabet: UCS2` | zawsze | patrz niżej |
| `Queue:` | wybór modemu (rozszerzenie 2.14.7) | |

Nagłówki są **wrażliwe na wielkość liter** (`fileformat.html`). Pusta linia oddziela nagłówki od treści.

### Kodowanie znaków (najważniejsza pułapka)

| Kodowanie | Znaków w 1 SMS | Na część w SMS wieloczęściowym | Polskie znaki |
|-----------|---------------:|-------------------------------:|:-------------:|
| GSM-7 | 160 | 153 | ✘ |
| UCS-2 (Unicode) | 70 | 67 | ✔ |

**Jedno „ą” skraca SMS ze 160 do 70 znaków.** Stąd licznik i opcja transliteracji w panelu.

Co robi smsd 3.1.21 (zweryfikowane, `smsd.c` ok. linii 4711, `charset.c`):
- `Alphabet: UTF` – smsd próbuje zamienić tekst na GSM; jeśli jakiegoś znaku nie da się zamienić,
  wysyła całość jako UCS2.
- **Pułapka:** przed tą próbą działa „zamiana optyczna” (`cs_convert_optical`, domyślnie włączona),
  która po cichu zamienia m.in. **`ó` → `o`, `Ó` → `O`**, `á` → `a`, `š` → `s`, `«` → `<`.
  Wiadomość „Mój kot” poszłaby więc jako „Moj kot” w GSM, bez ostrzeżenia. Pozostałe polskie litery
  (ą ć ę ł ń ś ź ż) poprawnie wymuszają UCS2.

**Decyzja:** panel sam wybiera kodowanie, niezależnie od ustawień smsd:

1. Zamień `\r\n` na `\n`.
2. Jeśli zaznaczono „Zamień polskie znaki” – transliteracja (ą→a, ć→c, ę→e, ł→l, ń→n, ó→o, ś→s, ź/ż→z,
   wielkie analogicznie; `„ ” “` → `"`, `‘ ’` → `'`, `– —` → `-`, `…` → `...`, twarda spacja → spacja).
3. Jeśli **każdy znak** należy do alfabetu GSM (tablica `charset[]` + `ext_charset[]` z `charset.c`) –
   zapis treści w UTF-8 z nagłówkiem `Alphabet: UTF`. smsd zamieni wszystko na GSM bez strat
   (zamiana optyczna nie dotyczy znaków, które są w GSM).
4. W przeciwnym razie – treść zakodowana przez panel w UTF-16BE z nagłówkiem `Alphabet: UCS2`.
   Nic nie zostanie zamienione ani zgubione.

Alfabet GSM używany przez panel (identyczny w PHP i JavaScript):
- podstawowy (1 jednostka): `@ £ $ ¥ è é ù ì ò Ç \n Ø ø \r Å å Δ _ Φ Γ Λ Ω Π Ψ Σ Θ Ξ Æ æ ß É`,
  spacja, `! " # % & ' ( ) * + , - . /`, cyfry, `: ; < = > ? ¡`, `A–Z`, `Ä Ö Ñ Ü §`, `¿`, `a–z`, `ä ö ñ ü à`,
- rozszerzony (2 jednostki): `^ { } \ [ ] ~ | €`,
- **bez** `¤` (smsd nie ma go w tablicy) i bez znaku nowej strony.

Długość w UCS2 liczona w jednostkach UTF-16 (emoji = 2). Długie wiadomości dzieli smsd;
✔ domyślnie `autosplit = 3` (UDH, `smsd_cfg.c`). Kontrola zdrowia ostrzega, jeśli ktoś ustawi `0`.

## 3.3. Odbiór – pliki w `incoming`

Przykład (z `examples/received_sms.sms`, uzupełniony o nagłówki z 3.1.21):
```
From: 48601234567
From_TOA: 91 international, ISDN/telephone
From_SMSC: 48601000310
Sent: 26-10-01 12:34:56
Received: 26-10-01 12:35:01
Subject: GSM1
Modem: GSM1
IMSI: 260021234567890
Report: no
Alphabet: ISO
Length: 50

<treść>
```

Przetwarzanie:

1. Pomiń pliki zaczynające się od `.`, `*.LOCK` / `LOCKED*`, mające obok `<nazwa>.LOCK`,
   oraz zmodyfikowane w ostatnich 2 sekundach.
2. Nagłówki do pierwszej pustej linii, reszta to treść.
3. Dekodowanie treści **na podstawie nagłówka `Alphabet`** (smsd sprawdza tylko 3 pierwsze znaki):

   | `Alphabet` | Treść w pliku | Konwersja w panelu |
   |------------|---------------|--------------------|
   | `UTF-8` | UTF-8 | brak |
   | `UCS2` | UTF-16BE (surowe bajty) | UTF-16BE → UTF-8 |
   | `ISO` | ISO-8859-15 | ISO-8859-15 → UTF-8 |
   | `GSM` | surowe znaki GSM (gdy `cs_convert = no`) | traktowane jak ISO-8859-15 |
   | `binary` | dane binarne | zapis jako `[dane binarne] <hex>` |

   ✔ Domyślnie `incoming_utf8 = no` (`smsd_cfg.c`) – dlatego panel obsługuje wszystkie warianty.
   **Zalecenie:** `incoming_utf8 = yes` – wtedy wszystkie odebrane są w UTF-8, także te wysłane jako
   Unicode (`smsd.c`, ok. linii 3184). Kontrola zdrowia zasugeruje to ustawienie.
4. Daty `Sent`/`Received` domyślnie w formacie `RR-MM-DD GG:MM:SS`. Format może zmienić plik
   lokalizacji (`datetime`), więc panel próbuje kilku formatów, a w ostateczności bierze czas modyfikacji pliku.
5. `From` może być **alfanumeryczny** (nadawcy firmowi) – zapisywany bez normalizacji.
6. Nagłówek `Incomplete:` (brakujące części wiadomości wieloczęściowej) – wiadomość zapisana
   z oznaczeniem „niekompletna”.
7. Lokalizacja nagłówków (`language_file`) zmienia ich nazwy – panel obsługuje tylko nazwy angielskie;
   `check` ostrzega, jeśli `language_file` jest ustawiony.

## 3.4. Raporty doręczenia

✔ Raport to plik w `incoming` (`examples/received_report.sms`):
```
From: 491721234567
From_SMSC: 491722270333
Sent: 02-01-21 22:26:23
Received: 02-01-21 22:26:25
Subject: GSM1

SMS STATUS REPORT
Message_id: 117
Discharge_timestamp: 02-01-21 22:27:02
Status: 0,Ok,short message received by the SME
```

✔ Nagłówek `Message_id:` dopisywany jest do pliku w `sent` **tylko gdy zażądano raportu**
(`fileformat.html`). Dla SMS wieloczęściowych zapisywane jest ID ostatniej części
(`messageids = 2`, domyślne) – raport dla tej części ustala status całej wiadomości.
Gdy `messageids = 3`, nagłówek zawiera wszystkie ID oddzielone spacją i zakończone ` .` – panel obsługuje
oba warianty i dopasowuje raport do dowolnego ID z listy.

Dopasowanie: najnowsza wiadomość wychodząca o tym samym ID i numerze (`Message_id` to licznik
modemu 0–255, więc się powtarza).

| Kod `Status` | Znaczenie | Status w panelu |
|-------------:|-----------|-----------------|
| 0–31 | doręczono | `delivered` + `delivered_at` z `Discharge_timestamp` |
| 32–63 | błąd tymczasowy, sieć ponawia | bez zmian, opis w `error` |
| 64–127 | błąd trwały | `undelivered` + opis |

Raporty nie są pokazywane jako wiadomości w skrzynce odbiorczej.

## 3.5. Statusy wiadomości wychodzących

```
                ┌─────────────┐  czas nadszedł   ┌──────────┐  plik w sent   ┌─────────┐  raport 0–31  ┌───────────┐
  (planowana) ─►│ scheduled   │─────────────────►│ queued   │───────────────►│  sent   │──────────────►│ delivered │
                └─────┬───────┘                  └────┬─────┘                └────┬────┘               └───────────┘
                      │ anuluj                        │ plik w failed             │ raport ≥64
                      ▼                               ▼                           ▼
                ┌─────────────┐                  ┌──────────┐               ┌─────────────┐
                │ cancelled   │                  │ failed   │               │ undelivered │
                └─────────────┘                  └────┬─────┘               └──────┬──────┘
                                                      └───── ponów ──► queued ◄────┘
```

| Status | Etykieta | Skąd wiadomo |
|--------|----------|--------------|
| `scheduled` | Zaplanowana | zapis w bazie, pliku jeszcze nie ma (także wysyłka dławiona) |
| `dispatching` | Przekazywana do kolejki | stan przejściowy na czas zapisu pliku (atomowe przejęcie wiadomości – anulowanie w tym czasie jest niemożliwe) |
| `queued` | W kolejce | plik zapisany do `outgoing` |
| `sent` | Wysłana | plik w `sent` |
| `failed` | Błąd | plik w `failed` |
| `delivered` | Doręczona | raport 0–31 |
| `undelivered` | Niedoręczona | raport 64–95 (błąd trwały) lub 96–127 (błąd tymczasowy, centrum SMS przestało próbować); wartość spoza 0–127 – plik uszkodzony |
| `cancelled` | Anulowana | użytkownik anulował przed wysłaniem |

✔ Nagłówki dopisywane przez smsd (zweryfikowane w `smsd.c`):

| Plik w | Nagłówki | Uwagi |
|--------|----------|-------|
| `sent` | `Modem:`, `Sent:`, `Message_id:` (tylko z raportem), `IMSI:`, `IMEI:`, `Sending_time:`, `NOTICE:` | `NOTICE` – znaki, których nie dało się zamienić |
| `failed` | `Fail_reason:`, `Failed:` (czas); przy błędzie modemu także `Modem:`, `IMSI:`, `IMEI:` | przykładowe przyczyny: `No destination`, `Too long text`, `Modem initialization failed`, `Destination is blacklisted` |

✔ Nazwa pliku: domyślnie `keep_filename = yes` – plik zachowuje nazwę `gui_…` w `checked`/`sent`/`failed`.
Gdy `keep_filename = no`, smsd nadaje unikalne nazwy, ale zapisuje nagłówek `Original_filename:`
(`store_original_filename = yes`, domyślne). Panel dopasowuje więc wiadomość najpierw po nazwie pliku,
a potem po `Original_filename`. Ustawienie `filename_preview` (domyślnie wyłączone) też zmienia
nazwy – obsługiwane tą samą drogą.

**Anulowanie wiadomości w kolejce:** panel próbuje usunąć plik z `outgoing`. Udało się → `cancelled`;
pliku już nie ma (smsd przeniósł go do `checked`) → „wiadomość jest już przetwarzana”.

**Wiadomość „zawieszona”:** `queued` dłużej niż 15 minut → ostrzeżenie na pulpicie.

## 3.6. Synchronizacja – algorytm

Uruchamiana:
- **natychmiast po zmianie w katalogach** `incoming`, `sent`, `failed` – jednostka systemd typu `path`
  obserwuje katalogi i uruchamia synchronizację (bez żadnych zmian w smsd),
- **co 30 s** przez timer systemd – dla wysyłki planowanej/dławionej i jako zabezpieczenie,
- **przy odświeżeniu panelu**, nie częściej niż co 10 s.

Chroniona blokadą `flock` – jeśli trwa, kolejne wywołanie kończy się od razu.

1. **Wysyłka planowana i dławiona:** `scheduled` z `scheduled_at <= teraz` → przejęcie (`dispatching`)
   razem z zapisem nazwy pliku tej próby w `spool_file`, zapis pliku do `outgoing`, `queued`.
   Wiadomość anulowana w międzyczasie nie zostanie przejęta. Nazwa zapisana przed plikiem jednoznacznie
   odróżnia bieżącą próbę od plików poprzednich (czasy plików mają rozdzielczość 1 s – nie wystarczą).
   `dispatching` starsze niż 5 min (przerwany proces): plik o nazwie ze `spool_file` w `outgoing`, `checked`,
   `sent` albo `failed` (w `sent`/`failed` także pod zmienioną nazwą – po nagłówku `Original_filename`) →
   `queued` (wynik z `sent`/`failed` importuje ta sama synchronizacja), brak pliku → `failed`.
   Wiersze `dispatching` sprzed aktualizacji (wcześniejsza wersja zapisywała nazwę dopiero po pliku) mają
   `spool_file` NULL albo z poprzedniej próby: bieżąca próba to wtedy **nieprzetworzony** plik tej wiadomości
   (wyniki poprzednich prób są zawsze przetworzone – „Ponów” jest możliwe dopiero po ich imporcie). Wynik
   z `sent`/`failed` dla takiego wiersza nie jest odrzucany ani zapamiętywany – czeka na odzyskanie.
2. **`sent` i `failed`:** dla każdego nowego pliku:
   - dopasowanie po nazwie `gui_<id>_…` lub nagłówku `Original_filename` → aktualizacja statusu, modemu,
     `message_id`, daty, przyczyny błędu,
   - brak dopasowania → nowy rekord „zewnętrzny” (`source = external`).
3. **`incoming`:** raporty → aktualizacja statusów; pozostałe → nowe wiadomości odebrane;
   następnie automatyzacje (przekazywanie, autoodpowiedzi – rozszerzenia).
   Krok 2 jest przed 3, żeby raport nie trafił do bazy przed informacją o `Message_id`.
4. Każdy przetworzony plik:
   - tryb `keep` – wpis w tabeli `processed_files` (żeby nie importować go ponownie),
   - tryb `delete` – usunięcie pliku (jeśli się nie uda – też wpis w `processed_files`).
   Wpisy dla plików, które zniknęły z katalogu, są czyszczone.
5. Każdy plik w osobnej transakcji – uszkodzony plik nie blokuje pozostałych; błąd trafia do logu
   aplikacji i na pulpit. Tylko plik z błędną treścią (np. raport bez poprawnego `Status`) jest zapamiętywany
   jako przetworzony; błąd chwilowy (odczyt pliku, pełny dysk, baza) – ponowna próba przy następnej synchronizacji.
6. Zapis czasu i wyniku ostatniej synchronizacji (tabela `settings`).

Alternatywa rozważona i odrzucona: `eventhandler` smsd uruchamiający synchronizację. Działałby jako
użytkownik `smsd`, który nie powinien mieć prawa zapisu do bazy panelu. Obserwacja katalogów przez
systemd daje ten sam efekt bez zmian w konfiguracji smsd.

## 3.7. Edycja `smsd.conf`

✔ Format (`cfgfile.c`): linie `klucz = wartość`, sekcje `[nazwa]`, linie przed pierwszą sekcją to sekcja
globalna, sekcja modemu nazywa się tak jak wpis w `devices`. **Komentarzem jest tylko cała linia
zaczynająca się od `#`** – `#` w środku linii jest częścią wartości (ważne np. dla `regular_run_cmd`
z kodem USSD `*101#`).

Parser zachowuje plik linia po linii. Zmiana parametru:
- istnieje → podmiana wartości w tej samej linii (jeśli występuje kilka razy – we wszystkich),
- nie istnieje → dopisanie za ostatnim parametrem tej sekcji,
- brak sekcji → dopisanie nowej sekcji na końcu pliku,
- pusta wartość → usunięcie linii (smsd użyje wartości domyślnej).

Wyjątek: parametry, które z założenia występują wiele razy (`regular_run_cmd`), są edytowane jako lista.

Zapis: kopia zapasowa → zapis pliku „w miejscu” z blokadą (`LOCK_EX`). Plik tymczasowy i `rename()` nie są możliwe:
`www-data` nie może tworzyć plików w `/etc`, a `rename()` zmieniłby właściciela i uprawnienia pliku. Nie jest to
problem, bo smsd czyta konfigurację tylko przy starcie – zmiany działają po restarcie usługi.

## 3.8. Stan modemu (dla rozszerzenia 2.14.4)

- ✔ Plik `stats/status` i statystyki wymagają skompilowania smsd z biblioteką `libmm`; w domyślnym
  `Makefile` są wyłączone (`-D NOSTATS`). ⚠ do weryfikacji, czy paczka Ubuntu ma je włączone.
- Niezależna od tego droga (✔ `configure.html`): w sekcji modemu
  ```ini
  regular_run_interval = 300
  regular_run_cmd = AT+CSQ
  regular_run_cmd = AT+COPS?
  regular_run_cmd = AT+CUSD=1,"*101#",15
  regular_run_statfile = /var/spool/sms/stats/modemname.stat
  ```
  smsd co 5 minut wysyła komendy do modemu i zapisuje odpowiedzi do pliku, który czyta panel
  (sygnał, operator, saldo przez USSD). `ussd_convert` dekoduje odpowiedź USSD.
  Formularz konfiguracji będzie miał gotowy przycisk „Włącz monitorowanie modemu”.

## 3.9. Normalizacja numerów

| Wpisano | Wynik |
|---------|-------|
| `601 234 567` | `48601234567` (9 cyfr → dodany kod kraju z konfiguracji) |
| `+48 601-234-567` | `48601234567` |
| `0048601234567` | `48601234567` |
| `+44 7911 123456` | `447911123456` |
| `8080` | `8080` (numer skrócony; w pliku dla smsd zapisany jako `To: s8080`) |
| `abc`, `12` | błąd walidacji |

Reguła: usuń spacje, `-`, `.`, `(`, `)`, `/`; `+` lub `00` na początku → usuń; dokładnie
`national_number_length` cyfr → dodaj `default_country_code`; wynik musi mieć 3–15 cyfr.
Numery krótsze niż `national_number_length` traktowane są jako skrócone – w bazie bez zmian
(tak samo przychodzą w `From:`, więc rozmowy się łączą), a do pliku smsd trafiają z prefiksem `s`. Wyświetlanie: `+48 601 234 567`.

## 3.10. Pozostałe do weryfikacji na Ubuntu (etap 0)

| # | Co | Jak sprawdzimy |
|---|----|----------------|
| U1 | Nazwa usługi systemd (`smstools`?) | `systemctl list-units 'sms*'` |
| U2 | Użytkownik/grupa smsd i uprawnienia `/var/spool/sms/*` | `ls -l`, `grep -E '^(user|group)' /etc/smsd.conf` |
| U3 | Domyślny `/etc/smsd.conf` z paczki (czy są `sent`/`failed`) | odczyt pliku |
| U4 | Uprawnienia logu i reguła logrotate | `ls -l /var/log/smstools`, `/etc/logrotate.d/smstools` |
| U5 | Czy paczka ma statystyki (`libmm`) | `ldd $(which smsd) \| grep mm` |
| U6 | ✔ Wersja PHP – użytkownik potwierdził PHP 8.5 na serwerze produkcyjnym (2026-10-03) | `php -v` przy instalacji |
