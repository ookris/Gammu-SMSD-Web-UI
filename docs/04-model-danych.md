# 4. Model danych (MariaDB)

Serwer MariaDB z repozytorium Ubuntu, dwie bazy (decyzja D18, szczegóły uprawnień w
[03, rozdz. 3.2](03-integracja-gammu-smsd.md#32-bazy-danych-i-uprawnienia)):

- **`gammu`** – tabele Gammu SMSD w schemacie w wersji 17 (`outbox`, `outbox_multipart`, `sentitems`, `inbox`,
  `phones`, `gammu`), tworzone z oficjalnego skryptu `mysql.sql` i zamienione na InnoDB. Panel nie zmienia ich struktury.
- **`smsgui`** – tabele panelu opisane poniżej.

Ustawienia: silnik **InnoDB**, kodowanie `utf8mb4`, porównywanie `utf8mb4_polish_ci` (sortowanie i wyszukiwanie
bez rozróżniania wielkości liter, poprawna kolejność polskich liter). Połączenie PDO: `ERRMODE_EXCEPTION`,
`SET time_zone` zgodnie z `config.php`, `sql_mode` z `STRICT_ALL_TABLES`.

Daty: `DATETIME` w czasie lokalnym (strefa z konfiguracji) – tak samo jak w tabelach Gammu.

## 4.1. Diagram

```
messages >── (phone) ── contacts >──< contact_group_members >──< groups
    │
    └── gammu_id ··· gammu.outbox.ID / gammu.sentitems.ID   (powiązanie logiczne, bez klucza obcego)

users (1 konto)   login_attempts   templates   settings   schema_version
calls   ussd_requests   blocked_numbers   modem_status

rozszerzenia:  api_tokens ──< messages    forward_rules ──< forward_queue >── messages / calls
               auto_replies   auto_reply_log   notifications_log   balance_history
```

## 4.2. Tabele

### `users`
| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| id | INT PK AUTO_INCREMENT | |
| username | VARCHAR(64) UNIQUE | porównanie bez wielkości liter (collation) |
| password_hash | VARCHAR(255) | `password_hash()` |
| remember_secret | CHAR(64) | losowy klucz podpisu ciasteczek „Zapamiętaj mnie” (HMAC); działa na wielu urządzeniach naraz, zmiana hasła losuje nowy klucz i unieważnia wszystkie ciasteczka |
| created_at, last_login_at | DATETIME | |

Tabela ma jeden wiersz (decyzja D1); osobna tabela zamiast wpisu w `settings` ułatwia ewentualne
dodanie kolejnych kont w przyszłości.

### `contacts`
| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| id | INT PK | |
| name | VARCHAR(190) NOT NULL | |
| phone | VARCHAR(32) NOT NULL UNIQUE | znormalizowany, np. `48601234567` |
| note | TEXT | |
| created_at, updated_at | DATETIME | |

### `groups`, `contact_group_members`
| Tabela | Kolumny |
|--------|---------|
| `groups` | id, name VARCHAR(190) UNIQUE, created_at |
| `contact_group_members` | contact_id → contacts (ON DELETE CASCADE), group_id → groups (ON DELETE CASCADE), PK(contact_id, group_id) |

### `messages`
Jedna tabela dla obu kierunków – upraszcza widok rozmów.

| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| id | INT PK | |
| direction | ENUM('in','out') | |
| phone | VARCHAR(32) | numer znormalizowany (lub nazwa nadawcy alfanumerycznego) |
| body | TEXT | treść w UTF-8 |
| encoding | ENUM('GSM','UCS2','8bit') | |
| parts | TINYINT | liczba części SMS |
| status | VARCHAR(16) | `received` dla `in`; dla `out` – patrz [03, rozdz. 3.6](03-integracja-gammu-smsd.md#36-statusy-wysyłka-planowana-okna-wysyłki-i-priorytet) |
| incomplete | TINYINT(1) | 1 – odebrana wiadomość wieloczęściowa bez wszystkich części |
| flash | TINYINT(1) | wysłana / odebrana jako Flash SMS |
| report | TINYINT(1) | zażądano raportu doręczenia |
| priority | TINYINT | `outbox.Priority` (0 / 10 / 20) |
| send_window | TINYINT(1) | wysyłka ograniczona oknem godzinowym (`SendAfter`/`SendBefore`) |
| source | ENUM('gui','external','api','autoreply','notify') | |
| api_token_id | INT → api_tokens | dla `source = api` (R) |
| batch_id | CHAR(16) | wspólny dla wysyłki masowej |
| gammu_id | INT UNSIGNED | `outbox.ID` = `sentitems.ID` bieżącej próby (wychodzące) lub `inbox.ID` pierwszej części (odebrane) |
| udh_ref | TINYINT UNSIGNED | numer referencyjny UDH wiadomości wieloczęściowej |
| modem | VARCHAR(64) | PhoneID: modem, który wysłał (`sentitems.SenderID`) lub odebrał (`inbox.RecipientID`) |
| modem_requested | VARCHAR(64) | modem wybrany przy wysyłce (`outbox.SenderID`, R) |
| retries | TINYINT | ostatnio odczytane `outbox.Retries` |
| status_code | INT | `StatusCode` / `StatusError` z Gammu |
| error | VARCHAR(255) | opis błędu / raportu w języku użytkownika |
| is_read | TINYINT(1) | dla `in` |
| scheduled_at, created_at, sent_at, received_at, delivered_at, updated_at | DATETIME | |

Indeksy: `(phone, id)` – rozmowy; `(direction, status)` – listy i liczniki;
`(direction, gammu_id)` – synchronizacja i rozpoznawanie wiadomości zewnętrznych; `(updated_at)` – wykrywanie zmian przy
auto-odświeżaniu; `(batch_id)`.

Wiadomość wysłana zapisuje się w `messages` i w `gammu.outbox` w **jednej transakcji** (oba schematy na tym samym
serwerze, jedno połączenie).

### `calls`
| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| id | INT PK | |
| phone | VARCHAR(32) | znormalizowany; pusty – numer ukryty |
| raw_number | VARCHAR(40) | numer w postaci od Gammu (przed normalizacją) |
| modem | VARCHAR(64) | PhoneID z parametru hooka |
| received_at | DATETIME | |
| processed | TINYINT(1) | automatyzacje wykonane przez proces w tle |
| is_read | TINYINT(1) | widoczne na liście / licznik na pulpicie |

Do tej tabeli pisze hook (`smsgui_hook`: tylko `INSERT` na kolumnach `phone`, `raw_number`, `modem`, `received_at`).
Indeksy: `(phone, received_at)`, `(processed)`.

### `ussd_requests`
| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| id | INT PK | |
| modem | VARCHAR(64) | |
| code | VARCHAR(64) | np. `*101#` lub odpowiedź w menu (`1`) |
| gammu_id | INT UNSIGNED | `outbox.ID` żądania |
| status | ENUM('queued','sent','answered','timeout','failed') | |
| response | TEXT | odpowiedź operatora |
| session_status | TINYINT | `inbox.Status` odpowiedzi (1–7) |
| purpose | ENUM('manual','balance') | na żądanie / cykliczne saldo (R) |
| created_at, answered_at | DATETIME | |

### `blocked_numbers`
| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| id | INT PK | |
| phone | VARCHAR(32) UNIQUE | znormalizowany numer lub nazwa alfanumeryczna |
| note | VARCHAR(255) | |
| created_at | DATETIME | |

Plik `ExcludeNumbersFile` jest generowany z tej tabeli ([03, rozdz. 3.12](03-integracja-gammu-smsd.md#312-czarna-lista-numerów)).

### `modem_status`
| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| modem | VARCHAR(64) PK | PhoneID |
| imei, imsi | VARCHAR(35) | |
| signal, battery | TINYINT | % (–1 = brak danych) |
| net_code, net_name | VARCHAR(35) | |
| sent, received | INT | liczniki od startu Gammu |
| client | VARCHAR(255) | wersja Gammu |
| balance | VARCHAR(255) | ostatnia odpowiedź salda (R) |
| balance_value | DECIMAL(10,2) | kwota odczytana z odpowiedzi (R) |
| balance_at | DATETIME | |
| gammu_updated_at | DATETIME | `phones.UpdatedInDB` – do wykrywania niedostępnego modemu |
| updated_at | DATETIME | |

Kopia tabeli `phones`, bo Gammu usuwa swój wiersz przy restarcie – panel pamięta ostatni znany stan.

### `templates`
id, name, body, created_at, updated_at.

### `settings`
`key` VARCHAR(64) PK, `value` TEXT – m.in. czas i wynik ostatniej synchronizacji, domyślne opcje wysyłki,
okno wysyłki, szybkie kody USSD, licznik numerów referencyjnych UDH, retencja.

### `login_attempts`
ip, attempted_at – do blokady po nieudanych logowaniach (stare wpisy czyszczone).

### `schema_version`
Jeden wiersz z numerem wersji schematu panelu (odpowiednik `PRAGMA user_version` z SQLite).

### Tabele rozszerzeń (R)
| Tabela | Kolumny |
|--------|---------|
| `api_tokens` | id, name, token_hash (SHA-256), allowed_ips, created_at, last_used_at, revoked_at |
| `forward_rules` | id, name, enabled, event (`sms`/`call`), match_type (`all`/`number`/`group`/`contains`), match_value, channel (`email`/`webhook`), target (JSON: adres e-mail lub URL i sekret), last_error, last_error_at, sent_count, created_at |
| `forward_queue` | id, rule_id, message_id, call_id, attempts, last_error, next_attempt_at, done_at |
| `auto_replies` | id, name, enabled, match_type (`equals`/`starts`/`contains`/`hours`/`call`), match_value, schedule (JSON z godzinami), reply_body, sent_count, created_at |
| `auto_reply_log` | phone, sent_at – blokada pętli (1 odpowiedź / 60 min / numer) |
| `notifications_log` | type, sent_at, details – limit 1 powiadomienia danego typu na godzinę |
| `balance_history` | modem, value, raw, checked_at – wykres salda |

## 4.3. Migracje

Wersja schematu panelu w tabeli `schema_version`. Przy każdym połączeniu aplikacja wykonuje brakujące
migracje (numerowane bloki SQL w `Db.php`). Uwaga: w MariaDB polecenia DDL (`CREATE`, `ALTER`) **zatwierdzają
transakcję automatycznie** – każda migracja musi więc być bezpieczna do ponownego uruchomienia
(`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`), a numer wersji jest zapisywany po jej zakończeniu.
Współbieżne uruchomienie (panel + proces w tle) chroni blokada `GET_LOCK('smsgui-migrate')`.

Schematu `gammu` panel nie migruje. Instalator tworzy go z `mysql.sql` dostarczonego z paczką Gammu
i zamienia silnik na InnoDB; przy aktualizacji Gammu do nowszej wersji schematu (np. 18) potrzebna będzie
osobna, opisana procedura (poza zakresem wersji 1 – D19).

## 4.4. Wydajność

Szacunek: 1 000 SMS dziennie ≈ 365 tys. rekordów rocznie w `messages` i tyle samo wierszy w tabelach Gammu
(w trybie `keep`) – MariaDB z indeksami obsługuje to bez problemu. Lista rozmów używa podzapytania
`MAX(id) GROUP BY phone` po indeksie `(phone, id)`. Synchronizacja odpytuje tabele Gammu wyłącznie po kluczach
(`ID`, `Processed`), więc jej koszt nie rośnie z historią.
Opcjonalna retencja (`cleanup --days=N`, w panelu – 2.17.8) czyści także zaimportowane wiersze `inbox` i `sentitems`.

Kopia zapasowa: `mariadb-dump --single-transaction` obu baz (rozdz. 6.5).
