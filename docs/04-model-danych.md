# 4. Model danych (SQLite)

Plik: `/var/lib/smsgui/smsgui.sqlite`. Ustawienia połączenia: `journal_mode = WAL`
(odczyty nie blokują zapisów – ważne, bo synchronizacja i panel działają równocześnie),
`foreign_keys = ON`, `busy_timeout = 5000`.

Daty zapisywane jako tekst `RRRR-MM-DD GG:MM:SS` w czasie lokalnym (strefa z konfiguracji).

## 4.1. Diagram

```
messages >── (phone) ── contacts >──< contact_group_members >──< groups

users (1 konto)   login_attempts   templates   settings   processed_files

rozszerzenia:  api_tokens ──< messages    forward_rules ──< forward_queue >── messages
               auto_replies   auto_reply_log   notifications_log   modem_status
```

## 4.2. Tabele

### `users`
| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| id | INTEGER PK | |
| username | TEXT UNIQUE NOCASE | |
| password_hash | TEXT | `password_hash()` |
| remember_secret | TEXT | losowy klucz podpisu ciasteczek „Zapamiętaj mnie” (HMAC); działa na wielu urządzeniach naraz, zmiana hasła losuje nowy klucz i unieważnia wszystkie ciasteczka |
| created_at, last_login_at | TEXT | |

Tabela ma jeden wiersz (decyzja D1); osobna tabela zamiast wpisu w `settings` ułatwia ewentualne
dodanie kolejnych kont w przyszłości.

### `contacts`
| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| id | INTEGER PK | |
| name | TEXT NOT NULL | |
| phone | TEXT NOT NULL UNIQUE | znormalizowany, np. `48601234567` |
| note | TEXT | |
| created_at, updated_at | TEXT | |

### `groups`, `contact_group_members`
| Tabela | Kolumny |
|--------|---------|
| `groups` | id, name UNIQUE NOCASE, created_at |
| `contact_group_members` | contact_id → contacts (CASCADE), group_id → groups (CASCADE), PK(contact_id, group_id) |

### `messages`
Jedna tabela dla obu kierunków – upraszcza widok rozmów.

| Kolumna | Typ | Uwagi |
|---------|-----|-------|
| id | INTEGER PK | |
| direction | TEXT | `in` / `out` |
| phone | TEXT | numer (lub nazwa nadawcy alfanumerycznego) |
| body | TEXT | treść w UTF-8 |
| encoding | TEXT | `GSM` / `UCS2` |
| parts | INTEGER | liczba części SMS |
| status | TEXT | `received` dla `in`; dla `out` – patrz rozdz. 3.5 |
| incomplete | INTEGER | 1 – odebrana wiadomość wieloczęściowa bez wszystkich części |
| source | TEXT | `gui` / `external` / `api` / `autoreply` |
| api_token_id | INTEGER → api_tokens | dla `source = api` (R) |
| batch_id | TEXT | wspólny dla wysyłki masowej |
| spool_file | TEXT | nazwa pliku w kolejce smsd |
| modem | TEXT | np. `GSM1` |
| message_id | TEXT | `Message_id` od smsd (do raportów); przy `messageids = 3` lista ID oddzielonych spacją |
| error | TEXT | przyczyna błędu / opis raportu |
| flash, report | INTEGER | opcje wysyłki |
| is_read | INTEGER | dla `in` |
| scheduled_at, created_at, sent_at, received_at, delivered_at, updated_at | TEXT | |

Indeksy: `(phone, id)` – rozmowy; `(direction, status)` – listy i liczniki;
`(spool_file)` – import z `sent`/`failed`; `(message_id, phone)` – raporty;
`(updated_at)` – wykrywanie zmian przy auto-odświeżaniu; `(batch_id)`.

### `templates`
id, name, body, created_at, updated_at.

### `processed_files`
| Kolumna | Uwagi |
|---------|-------|
| dir | `incoming` / `sent` / `failed` |
| name | nazwa pliku |
| processed_at | |
PK `(dir, name)`.

### `settings`
`key` PK, `value` – m.in. czas i wynik ostatniej synchronizacji, domyślne opcje wysyłki,
stopka SMS, retencja.

### `login_attempts`
ip, attempted_at – do blokady po nieudanych logowaniach (stare wpisy czyszczone).

### Tabele rozszerzeń (R)
| Tabela | Kolumny |
|--------|---------|
| `api_tokens` | id, name, token_hash (SHA-256), allowed_ips, created_at, last_used_at, revoked_at |
| `forward_rules` | id, name, enabled, match_type (`all`/`number`/`group`/`contains`), match_value, channel (`email`/`webhook`; kolejne w przyszłości), target (JSON: adres e-mail lub URL i sekret), last_error, last_error_at, sent_count, created_at |
| `forward_queue` | id, rule_id, message_id, attempts, last_error, next_attempt_at, done_at |
| `auto_replies` | id, name, enabled, match_type (`equals`/`starts`/`contains`/`hours`), match_value, schedule (JSON z godzinami), reply_body, sent_count, created_at |
| `auto_reply_log` | phone, sent_at – blokada pętli (1 odpowiedź / 60 min / numer) |
| `notifications_log` | type, sent_at, details – limit 1 powiadomienia danego typu na godzinę |
| `modem_status` | modem, signal, operator, balance (JSON: tekst USSD i wartość), raw, updated_at |
| `messages.queue` | kolejka smsd wybrana przy wysyłce (wybór modemu); `modem` to modem, który faktycznie wysłał |

## 4.3. Migracje

Wersja schematu w `PRAGMA user_version`. Przy każdym połączeniu aplikacja wykonuje brakujące
migracje (numerowane bloki SQL w `Db.php`) w transakcji. Aktualizacja aplikacji nie wymaga więc
ręcznych kroków na bazie.

## 4.4. Wydajność

Szacunek: 1 000 SMS dziennie ≈ 365 tys. rekordów rocznie – SQLite z indeksami obsługuje to
bez problemu. Lista rozmów używa podzapytania `MAX(id) GROUP BY phone` po indeksie `(phone, id)`.
Opcjonalna retencja (`bin/smsgui cleanup --days=N`) i `VACUUM` raz w miesiącu.
