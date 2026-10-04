# 5. Bezpieczeństwo

Panel pozwala wysyłać SMS na koszt właściciela karty SIM i zmieniać konfigurację usługi systemowej,
więc musi być traktowany jak panel administracyjny.

## 5.1. Zagrożenia i zabezpieczenia

| Zagrożenie | Zabezpieczenie |
|------------|----------------|
| Nieuprawniony dostęp do panelu | domyślnie dostęp tylko z sieci lokalnej (nginx), logowanie, hasło `password_hash()`, blokada po 5 nieudanych próbach / 15 min, wygasanie sesji |
| Przechwycenie hasła/sesji | HTTPS (zalecany, opcjonalny w sieci lokalnej); ciasteczko sesji `HttpOnly`, `SameSite=Lax`, `Secure` przy HTTPS; `session_regenerate_id()` po zalogowaniu |
| Zdalne wymuszenie akcji (CSRF), np. wysyłka SMS przez spreparowany link | token CSRF w każdym formularzu POST, sprawdzany centralnie w `index.php`; wszystkie akcje zmieniające stan tylko przez POST |
| XSS (np. złośliwa treść odebranego SMS) | każde wyjście przez `htmlspecialchars()` (funkcja `e()`); nagłówek `Content-Security-Policy` (rozdz. 5.4) – brak skryptów i stylów inline |
| SQL injection | wyłącznie zapytania przygotowane (PDO) |
| Wstrzyknięcie nagłówków do pliku smsd (np. numer `"123\nFlash: yes"`) | numer po normalizacji to same cyfry; treść zawsze po pustej linii nagłówków |
| Wykonanie poleceń systemowych | panel uruchamia tylko dwa polecenia zdefiniowane w `config.php`, bez parametrów od użytkownika; `sudo` ograniczone do jednego polecenia (patrz 5.2) |
| Odczyt dowolnych plików przez panel | ścieżki tylko z `config.php` / `smsd.conf`; nazwy plików kopii zapasowych walidowane wzorcem, bez `..` |
| Nadużycie wysyłki do wielu odbiorców | limit `max_recipients`, dławienie (N SMS/min) |
| Nadużycie HTTP API (R) | tokeny przechowywane jako skrót, unieważnianie, opcjonalna lista dozwolonych IP, te same limity co w panelu |
| Przypadkowe uszkodzenie `smsd.conf` | okno potwierdzenia z podglądem różnic (D4), kopia przed każdym zapisem, przywracanie jednym kliknięciem |
| Wyciek bazy / kopii konfiguracji (PIN karty SIM w smsd.conf) | dane w `/var/lib/smsgui` poza document root, uprawnienia `0750`, właściciel `www-data` |

## 5.2. Model uprawnień w systemie

Panel działa jako `www-data`. Potrzebuje:

| Zasób | Dostęp | Jak nadany |
|-------|--------|------------|
| `/var/spool/sms/outgoing` | zapis | `www-data` w grupie `smsd`, katalog `2770 smsd:smsd` |
| `/var/spool/sms/incoming`, `sent`, `failed` | odczyt (+ usuwanie w trybie `delete`) | j.w. |
| `/var/log/smstools/smsd.log` | odczyt | grupa `smsd` + `g+r` (także w regule logrotate) |
| `/etc/smsd.conf` | odczyt i zapis | `root:smsd 0664` (decyzja D4) |
| `systemctl restart smstools` | wykonanie jako root | wpis w `/etc/sudoers.d/smsgui` |
| `/var/lib/smsgui` | odczyt i zapis | właściciel `www-data`, `0750` |

Wpis sudoers – tylko jedno, konkretne polecenie:
```
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart smstools
```

Panel zapisuje `/etc/smsd.conf` bezpośrednio (decyzja D4). Ryzyko: ktoś, kto przejąłby proces PHP,
mógłby zmienić konfigurację smsd – akceptowalne przy panelu dostępnym tylko w sieci lokalnej.
Plik jest zapisywany w miejscu (z blokadą) – `www-data` nie może tworzyć plików tymczasowych w `/etc`. smsd czyta
konfigurację tylko przy starcie, a restart następuje po zakończeniu zapisu, więc nie odczyta pliku w połowie.

## 5.3. Dostęp sieciowy

- **Domyślnie** (decyzja D2) konfiguracja nginx z instalatora wpuszcza tylko adresy prywatne:
  `127.0.0.1`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` (oraz IPv6 `::1`, `fc00::/7`).
  Pozostałe dostają odpowiedź 403.
- Wystawienie panelu na zewnątrz zależy od użytkownika. Zalecenia w README: najlepiej VPN
  (WireGuard, Tailscale); jeśli bezpośrednio – HTTPS (certbot) i fail2ban (panel zapisuje nieudane
  logowania w formacie gotowym dla fail2ban; przykładowy filtr w `deploy/`).
- Endpointy HTTP API (R) podlegają tym samym ograniczeniom adresów co panel.

## 5.4. Nagłówki HTTP

`Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'`
(`img-src data:` – Pico.css rysuje znaczniki pól wyboru i strzałki list jako obrazki `data:`),
`X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`,
`Strict-Transport-Security` (przy HTTPS).
