# 5. Bezpieczeństwo

Panel pozwala wysyłać SMS na koszt właściciela karty SIM, wysyłać kody USSD i zmieniać konfigurację usługi
systemowej, więc musi być traktowany jak panel administracyjny.

## 5.1. Zagrożenia i zabezpieczenia

| Zagrożenie | Zabezpieczenie |
|------------|----------------|
| Nieuprawniony dostęp do panelu | domyślnie dostęp tylko z sieci lokalnej (nginx), logowanie, hasło `password_hash()`, blokada po 5 nieudanych próbach / 15 min, wygasanie sesji |
| Przechwycenie hasła/sesji | HTTPS (zalecany, opcjonalny w sieci lokalnej); ciasteczko sesji `HttpOnly`, `SameSite=Lax`, `Secure` przy HTTPS; `session_regenerate_id()` po zalogowaniu |
| Zdalne wymuszenie akcji (CSRF), np. wysyłka SMS lub USSD przez spreparowany link | token CSRF w każdym formularzu POST, sprawdzany centralnie w `index.php`; wszystkie akcje zmieniające stan tylko przez POST |
| XSS (np. złośliwa treść odebranego SMS lub odpowiedzi USSD) | każde wyjście przez `htmlspecialchars()` (funkcja `e()`); nagłówek `Content-Security-Policy` (rozdz. 5.4) – brak skryptów i stylów inline |
| SQL injection (baza panelu i tabele Gammu) | wyłącznie zapytania przygotowane (PDO); numer i treść trafiają do `outbox` jako parametry |
| Wstrzyknięcie poleceń przez hook połączeń (`RunOnIncomingCall` w Gammu 1.42.0 nie cytuje argumentu) | stałe polecenie bez danych od użytkownika, walidacja numeru w hooku, osobne konto bazy tylko z `INSERT` ([03, rozdz. 3.11](03-integracja-gammu-smsd.md#311-połączenia-przychodzące)) |
| Wykonanie poleceń systemowych | panel uruchamia tylko polecenia zdefiniowane w `config.php`, bez parametrów od użytkownika; `sudo` ograniczone do kilku konkretnych poleceń (rozdz. 5.2) |
| Odczyt dowolnych plików przez panel | ścieżki tylko z `config.php` / `gammu-smsdrc`; nazwy plików kopii zapasowych walidowane wzorcem, bez `..` |
| Wyciek hasła do bazy z `gammu-smsdrc` | plik `0660 root:www-data` (nieczytelny dla innych użytkowników); hasło maskowane w edytorze, podglądzie różnic i kopiach pokazywanych w przeglądarce |
| Wyciek PIN-u karty SIM | pole maskowane w formularzu; kopie konfiguracji w `/var/lib/smsgui` (`0750`) |
| Nadużycie wysyłki do wielu odbiorców | limit `max_recipients`, limit części, dławienie (N SMS/min), okno wysyłki |
| Nadużycie USSD (np. kody aktywujące usługi płatne) | USSD tylko po zalogowaniu i z tokenem CSRF; jedno żądanie na raz; pełna historia żądań; API nie udostępnia USSD |
| Nadużycie HTTP API (R) | tokeny przechowywane jako skrót, unieważnianie, opcjonalna lista dozwolonych IP, te same limity co w panelu |
| Przypadkowe uszkodzenie `gammu-smsdrc` | okno potwierdzenia z podglądem różnic (D4), walidacja, kopia przed każdym zapisem, przywracanie jednym kliknięciem |
| Wyciek danych z bazy | MariaDB nasłuchuje tylko lokalnie (gniazdo / `127.0.0.1`), osobne konta z minimalnymi uprawnieniami (rozdz. 5.2) |

## 5.2. Model uprawnień

### W systemie
Panel i proces w tle działają jako `www-data`. Potrzebują:

| Zasób | Dostęp | Jak nadany |
|-------|--------|------------|
| MariaDB | połączenie przez gniazdo | konto `smsgui` z hasłem w `config/config.php` (`0640 root:www-data`) |
| `/etc/gammu-smsdrc` | odczyt i zapis | `root:www-data 0660` (decyzja D4) |
| plik czarnej listy (`/var/lib/smsgui/exclude-numbers.txt`) | zapis (panel), odczyt (Gammu) | katalog panelu; Gammu działa jako root (⚠ U1) |
| log Gammu (`/var/log/gammu-smsd/smsd.log`) | odczyt | grupa `www-data` + `g+r`, także w regule logrotate |
| `systemctl reload` / `restart gammu-smsd` (i `gammu-smsd@*` – R) | wykonanie jako root | wpis w `/etc/sudoers.d/smsgui` |
| `/var/lib/smsgui` | odczyt i zapis | właściciel `www-data`, `0750` |

Wpis sudoers – tylko konkretne polecenia:
```
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload gammu-smsd, /usr/bin/systemctl restart gammu-smsd
```
(rozszerzenie „kilka modemów” dodaje analogiczne wpisy dla `gammu-smsd@*`).

Panel zapisuje `/etc/gammu-smsdrc` bezpośrednio (decyzja D4). Ryzyko: ktoś, kto przejąłby proces PHP,
mógłby zmienić konfigurację Gammu – w tym `RunOnReceive`, czyli polecenie uruchamiane przez demon jako root.
Akceptowalne przy panelu dostępnym tylko w sieci lokalnej (i tak równoważne pełnej kontroli nad bramką SMS);
walidacja edytora ostrzega przy każdym parametrze `RunOn…` innym niż ustawiony przez panel.
Jeśli ⚠ U1 pokaże, że paczka pozwala uruchamiać Gammu jako zwykły użytkownik (`--user`), instalator to wykorzysta
(użytkownik `gammu` w grupie `dialout`).

### W bazie
| Konto | Uprawnienia | Używa |
|-------|-------------|-------|
| `gammu` | `SELECT, INSERT, UPDATE, DELETE` na `gammu.*` | demon Gammu |
| `smsgui` | wszystkie na `smsgui.*`; `SELECT, INSERT, UPDATE, DELETE` na `gammu.*` | panel, proces w tle |
| `smsgui_hook` | `INSERT (phone, raw_number, modem, received_at)` na `smsgui.calls` | hook połączeń (dane w `/etc/smsgui/hook.cnf`, `0640 root:<grupa demona>`) |

Żadne konto nie ma uprawnień administracyjnych (`GRANT`, `CREATE USER`, `FILE`). Struktura baz jest tworzona
przez instalator kontem `root` MariaDB (uwierzytelnianie przez gniazdo, bez hasła w plikach).

## 5.3. Dostęp sieciowy

- **Domyślnie** (decyzja D2) konfiguracja nginx z instalatora wpuszcza tylko adresy prywatne:
  `127.0.0.1`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` (oraz IPv6 `::1`, `fc00::/7`).
  Pozostałe dostają odpowiedź 403.
- MariaDB: `bind-address = 127.0.0.1` (domyślne w Ubuntu) – kontrola zdrowia ostrzega, jeśli serwer bazy nasłuchuje
  na innych adresach.
- Wystawienie panelu na zewnątrz zależy od użytkownika. Zalecenia w README: najlepiej VPN
  (WireGuard, Tailscale); jeśli bezpośrednio – HTTPS (certbot) i fail2ban (panel zapisuje nieudane
  logowania w formacie gotowym dla fail2ban; przykładowy filtr w `deploy/`).
- Endpointy HTTP API (R) podlegają tym samym ograniczeniom adresów co panel.

## 5.4. Nagłówki HTTP

`Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'`
(`img-src data:` – Pico.css rysuje znaczniki pól wyboru i strzałki list jako obrazki `data:`),
`X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`,
`Strict-Transport-Security` (przy HTTPS).
